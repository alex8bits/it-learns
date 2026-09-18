<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Practice\Docker;

use App\Services\Practice\Docker\CliDockerClient;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException as LaravelProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/**
 * Unit coverage of the CLI wrapper around the docker binary: the
 * exact argv of every docker call (labels, --network none, resource
 * limits, engine args before the image), the stdin/timeout plumbing
 * of exec, the suppression of rm -f failures and the TSV parsing of
 * docker ps. The Process facade is faked, so no Docker daemon is
 * needed (or touched) here.
 */
class CliDockerClientTest extends TestCase
{
    private CliDockerClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['practice.docker.binary' => 'docker']);

        $this->client = new CliDockerClient;
    }

    public function test_start_container_builds_the_hardened_run_command(): void
    {
        Process::fake(fn () => 'abc123containerid');

        $containerId = $this->client->startContainer(
            image: 'mysql:8',
            name: 'itlearns-practice-0f0f0f0f-0f0f-4f0f-8f0f-0f0f0f0f0f0f',
            engineArgs: ['-e', 'MYSQL_ROOT_PASSWORD=practice'],
            memoryMb: 512,
            cpus: 0.5,
            pidsLimit: 128,
        );

        $this->assertSame('abc123containerid', $containerId);

        Process::assertRan(function (PendingProcess $process, ProcessResult $result): bool {
            return $process->command === [
                'docker',
                'run',
                '--detach',
                '--name', 'itlearns-practice-0f0f0f0f-0f0f-4f0f-8f0f-0f0f0f0f0f0f',
                '--label', 'itlearns.practice=1',
                '--label', 'itlearns.env=itlearns-practice-0f0f0f0f-0f0f-4f0f-8f0f-0f0f0f0f0f0f',
                '--network', 'none',
                '--memory', '512m',
                '--cpus', '0.5',
                '--pids-limit', '128',
                '-e', 'MYSQL_ROOT_PASSWORD=practice',
                'mysql:8',
            ] && $result->successful();
        });
    }

    public function test_start_container_fails_loud_when_docker_run_fails(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'docker: Cannot connect to the Docker daemon', exitCode: 1));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('docker command failed: docker: Cannot connect to the Docker daemon');

        $this->client->startContainer('mysql:8', 'n', [], 512, 0.5, 128);
    }

    public function test_start_container_fails_loud_when_no_container_id_is_returned(): void
    {
        Process::fake(fn () => '   ');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('docker run returned no container id');

        $this->client->startContainer('postgres:16', 'n', [], 512, 0.5, 128);
    }

    public function test_exec_feeds_stdin_and_kills_shortly_after_the_budget(): void
    {
        Process::fake(fn () => "1\n");

        $result = $this->client->exec(
            containerId: 'cid-1',
            command: ['mysql', '-uroot', '-ppractice', '--batch'],
            stdin: 'SELECT 1;',
            timeoutSeconds: 20,
        );

        $this->assertSame(0, $result->exitCode);
        $this->assertSame("1\n", $result->output);
        $this->assertGreaterThan(0.0, $result->durationMs);

        Process::assertRan(function (PendingProcess $process): bool {
            return $process->command === ['docker', 'exec', '-i', 'cid-1', 'mysql', '-uroot', '-ppractice', '--batch']
                && $process->input === 'SELECT 1;'
                && $process->timeout === 25;
        });
    }

    public function test_exec_without_stdin_leaves_the_input_empty(): void
    {
        Process::fake(fn () => '');

        $this->client->exec('cid-1', ['psql', '--version'], null, 10);

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['docker', 'exec', '-i', 'cid-1', 'psql', '--version']
            && $process->input === null);
    }

    public function test_exec_preserves_the_exit_code_and_raw_outputs(): void
    {
        Process::fake(fn () => Process::result(output: 'partial', errorOutput: 'ERROR 1064 (42000)', exitCode: 1));

        $result = $this->client->exec('cid-1', ['mysql'], 'SELECT boom;', 20);

        $this->assertSame(1, $result->exitCode);
        // The fake appends a trailing newline to string outputs —
        // only its shape is asserted, the driver does not trim stdout.
        $this->assertSame("partial\n", $result->output);
        $this->assertSame("ERROR 1064 (42000)\n", $result->errorOutput);
        $this->assertGreaterThan(0.0, $result->durationMs);
    }

    public function test_exec_reports_a_hard_killed_run_as_exit_code_124(): void
    {
        $symfonyProcess = new SymfonyProcess(['docker']);
        $exception = new LaravelProcessTimedOutException(
            new SymfonyProcessTimedOutException($symfonyProcess, SymfonyProcessTimedOutException::TYPE_GENERAL),
            Process::result(),
        );

        Process::fake(fn () => throw $exception);

        $result = $this->client->exec('cid-1', ['mysql'], 'SELECT sleep(999);', 20);

        $this->assertSame(124, $result->exitCode);
        $this->assertSame('', $result->output);
        $this->assertStringContainsString('exceeded the timeout', $result->errorOutput);
    }

    /**
     * A start failure of the docker binary (ProcessStartFailedException,
     * the exact class Symfony throws when the process never comes up)
     * is mapped onto exit code 127 instead of escaping exec().
     */
    public function test_exec_reports_a_start_failure_as_exit_code_127(): void
    {
        $exception = new ProcessStartFailedException(
            new SymfonyProcess(['docker']),
            'The system cannot find the file specified',
        );

        Process::fake(fn () => throw $exception);

        $result = $this->client->exec('cid-1', ['mysql'], 'SELECT 1;', 20);

        $this->assertSame(127, $result->exitCode);
        $this->assertSame('', $result->output);
        $this->assertStringContainsString('Docker CLI failed to start:', $result->errorOutput);
        $this->assertStringContainsString('The system cannot find the file specified', $result->errorOutput);
        $this->assertLessThanOrEqual(500, mb_strlen($result->errorOutput));
        $this->assertGreaterThan(0.0, $result->durationMs);
    }

    public function test_exec_reports_any_other_infra_exception_as_exit_code_127(): void
    {
        Process::fake(fn () => throw new RuntimeException('posix_spawn() failed'));

        $result = $this->client->exec('cid-1', ['psql'], null, 5);

        $this->assertSame(127, $result->exitCode);
        $this->assertSame('', $result->output);
        $this->assertStringContainsString('Docker CLI failed to start: posix_spawn() failed', $result->errorOutput);
    }

    public function test_remove_container_ignores_failures(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'Error: No such container: cid-gone', exitCode: 1));

        $this->client->removeContainer('cid-gone');

        // A failing rm -f must not surface — destroy() idempotency.
        $this->expectNotToPerformAssertions();
    }

    public function test_remove_container_swallows_a_start_failure(): void
    {
        Process::fake(fn () => throw new ProcessStartFailedException(
            new SymfonyProcess(['docker']),
            'binary not found',
        ));

        // destroy() runs in the caller's finally: a start failure of
        // the docker binary must be swallowed exactly like a non-zero
        // exit, or it would mask the primary failure.
        $this->client->removeContainer('cid-1');

        $this->expectNotToPerformAssertions();
    }

    public function test_remove_container_runs_docker_rm_force(): void
    {
        Process::fake();

        $this->client->removeContainer('cid-1');

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['docker', 'rm', '-f', 'cid-1']);
    }

    public function test_list_labeled_parses_the_tsv_output(): void
    {
        Process::fake(fn () => "abc123\t2026-09-17 10:00:00 +0000 UTC\ndef456\t2026-09-17 11:30:00 +0000 UTC\n");

        $containers = $this->client->listLabeled();

        $this->assertSame([
            ['container_id' => 'abc123', 'created_at' => '2026-09-17 10:00:00 +0000 UTC'],
            ['container_id' => 'def456', 'created_at' => '2026-09-17 11:30:00 +0000 UTC'],
        ], $containers);

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
            'docker', 'ps', '-a', '--filter', 'label=itlearns.practice=1', '--format', "{{.ID}}\t{{.CreatedAt}}",
        ]);
    }

    public function test_list_labeled_returns_an_empty_list_without_containers(): void
    {
        Process::fake(fn () => '');

        $this->assertSame([], $this->client->listLabeled());
    }

    public function test_list_labeled_fails_loud_when_docker_ps_fails(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'Cannot connect to the Docker daemon', exitCode: 1));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('docker command failed');

        $this->client->listLabeled();
    }

    public function test_the_binary_path_comes_from_the_config(): void
    {
        config(['practice.docker.binary' => '/usr/local/bin/docker']);

        Process::fake(fn () => 'abc123');

        $this->client->startContainer('mysql:8', 'n', [], 512, 0.5, 128);

        Process::assertRan(fn (PendingProcess $process): bool => $process->command[0] === '/usr/local/bin/docker');
    }
}
