<?php

declare(strict_types=1);

namespace App\Services\Practice\Docker;

use App\Services\Practice\Docker\Dto\DockerExecResult;
use Illuminate\Contracts\Process\ProcessResult as ProcessResultContract;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * The single DockerClient implementation: a thin CLI wrapper over the
 * `docker` binary via the Laravel Process facade (Stage 10 design,
 * option A — zero new composer dependencies; symfony/process is already
 * vendored transitively and the facade is part of laravel/framework).
 *
 * Commands are always passed in the array form so Symfony escapes
 * every argument — no shell is involved on any platform. The binary
 * path is read from config('practice.docker.binary') at call time (the
 * PaymentGateway/LlmClient config pattern), never cached in the
 * constructor. Config is deliberately not boot-validated against a
 * live daemon: a missing/unavailable Docker Engine surfaces as a clear
 * RuntimeException from startContainer/listLabeled instead.
 */
final class CliDockerClient implements DockerClient
{
    /**
     * Slack added to the caller's exec budget before the local
     * `docker exec` process is hard-killed: the engine itself keeps
     * no timeout, so this kill is what bounds a runaway query — the
     * friendly post-factum verdict lives in DockerPracticeEnvironment.
     */
    private const EXEC_KILL_GRACE_SECONDS = 5;

    /**
     * Budget for the control-plane calls (docker run / rm / ps): they
     * move no student data, so a flat generous limit is enough.
     */
    private const CONTROL_TIMEOUT_SECONDS = 120;

    /**
     * The label marking every container this platform creates; the
     * pruner and the destroy path rely on it.
     */
    private const PRACTICE_LABEL = 'itlearns.practice=1';

    public function startContainer(string $image, string $name, array $engineArgs, int $memoryMb, float $cpus, int $pidsLimit): string
    {
        // Engine args (-e MYSQL_ROOT_PASSWORD=…, -e POSTGRES_PASSWORD=…)
        // must precede the image: everything after the image would be
        // interpreted as the container command, not `docker run`
        // options.
        $containerId = trim($this->runControlCommand([
            $this->binary(),
            'run',
            '--detach',
            '--name', $name,
            '--label', self::PRACTICE_LABEL,
            '--label', "itlearns.env={$name}",
            '--network', 'none',
            '--memory', "{$memoryMb}m",
            '--cpus', (string) $cpus,
            '--pids-limit', (string) $pidsLimit,
            ...$engineArgs,
            $image,
        ])->output());

        if ($containerId === '') {
            throw new RuntimeException('docker run returned no container id');
        }

        return $containerId;
    }

    public function exec(string $containerId, array $command, ?string $stdin, int $timeoutSeconds): DockerExecResult
    {
        $startedAt = hrtime(true);

        $pending = Process::timeout($timeoutSeconds + self::EXEC_KILL_GRACE_SECONDS);

        if ($stdin !== null) {
            $pending = $pending->input($stdin);
        }

        try {
            $result = $pending->run([
                $this->binary(),
                'exec',
                '-i',
                $containerId,
                ...$command,
            ]);
        } catch (ProcessTimedOutException $exception) {
            // The local docker exec was hard-killed: report it as the
            // conventional timeout exit code so the caller can turn it
            // into the friendly timeout verdict via duration_ms.
            return new DockerExecResult(
                exitCode: 124,
                output: '',
                errorOutput: $exception->getMessage(),
                durationMs: (hrtime(true) - $startedAt) / 1e6,
            );
        } catch (Throwable $exception) {
            // The process never came up — the docker binary is missing
            // or the platform refused to spawn it (Symfony's
            // ProcessStartFailedException and any other infrastructure
            // failure). exec() never throws (the interface contract):
            // the conventional "command could not be started" exit
            // code 127 carries the capped reason instead.
            return new DockerExecResult(
                exitCode: 127,
                output: '',
                errorOutput: $this->trimError('Docker CLI failed to start: '.$exception->getMessage()),
                durationMs: (hrtime(true) - $startedAt) / 1e6,
            );
        }

        return new DockerExecResult(
            exitCode: $result->exitCode() ?? 124,
            output: $result->output(),
            errorOutput: $result->errorOutput(),
            durationMs: (hrtime(true) - $startedAt) / 1e6,
        );
    }

    public function removeContainer(string $containerId): void
    {
        // A non-zero exit here means the container is already gone
        // (idempotent destroy) or the engine refused — either way the
        // caller's bookkeeping must proceed, so the result is never
        // even inspected. A failure to even start the docker binary is
        // swallowed the same way: destroy() runs in the caller's
        // finally, and an exception escaping here would mask the
        // primary failure — the idempotency superinvariant outranks
        // it (see the interface PHPDoc).
        try {
            Process::timeout(self::CONTROL_TIMEOUT_SECONDS)->run([
                $this->binary(),
                'rm',
                '-f',
                $containerId,
            ]);
        } catch (Throwable) {
            // Deliberately ignored.
        }
    }

    public function listLabeled(): array
    {
        $output = $this->runControlCommand([
            $this->binary(),
            'ps',
            '-a',
            '--filter', 'label='.self::PRACTICE_LABEL,
            '--format', "{{.ID}}\t{{.CreatedAt}}",
        ])->output();

        $containers = [];

        foreach (explode("\n", trim($output)) as $line) {
            if ($line === '') {
                continue;
            }

            [$containerId, $createdAt] = array_pad(explode("\t", $line, 2), 2, null);

            $containers[] = [
                'container_id' => $containerId,
                'created_at' => is_string($createdAt) && $createdAt !== '' ? $createdAt : null,
            ];
        }

        return $containers;
    }

    /**
     * Run a control-plane command that must succeed and return its
     * result — the throwing complement of removeContainer(), which
     * deliberately ignores failures.
     *
     * @param  list<string>  $command
     *
     * @throws RuntimeException with the engine's stderr when the command fails
     */
    private function runControlCommand(array $command): ProcessResultContract
    {
        $result = Process::timeout(self::CONTROL_TIMEOUT_SECONDS)->run($command);

        if (! $result->successful()) {
            $reason = trim($result->errorOutput()) !== '' ? trim($result->errorOutput()) : trim($result->output());

            throw new RuntimeException('docker command failed: '.($reason !== '' ? $reason : 'unknown error'));
        }

        return $result;
    }

    /**
     * The docker binary path from the config, read at call time.
     */
    private function binary(): string
    {
        return (string) config('practice.docker.binary');
    }

    /**
     * Cap an infrastructure error so a runaway exception message
     * cannot bloat the result (mirrors the 500-char engine error cap
     * of DockerPracticeEnvironment).
     */
    private function trimError(string $message): string
    {
        return mb_substr(trim($message), 0, 500);
    }
}
