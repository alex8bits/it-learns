<?php

declare(strict_types=1);

namespace App\Services\Practice\Docker;

use App\Enums\PracticeEnvironmentStatus;
use App\Enums\PracticeRuntime;
use App\Models\PracticeEnvironment;
use App\Models\User;
use App\Services\Practice\CanonicalResultSerializer;
use App\Services\Practice\Concerns\LogsPracticeEnvironmentEvents;
use App\Services\Practice\Dto\ExecutionResult;
use App\Services\Practice\Dto\PracticeTaskInput;
use App\Services\Practice\PracticeEnvironmentManager;
use App\Support\Practice\SqlStatementSplitter;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Stage 10 practice runtime: a disposable Docker container per attempt
 * (mysql:8 / postgres:16, docs/concept.md §5.4). The container is
 * started with no network, hard memory/CPU/PIDs limits and the
 * itlearns practice labels, seeded over stdin and force-removed in the
 * calling action's finally (destroy()).
 *
 * Safety model — the guards of docs/concept.md §5.4 mapped onto the
 * docker runtime: the psql meta-command ban, the statement-count cap
 * and the per-statement first-keyword blacklist run before any Docker
 * call; the execution timeout is enforced twice (the Process facade
 * hard-kills the local `docker exec`, then a post-factum duration
 * check produces the friendly verdict); the result size limit caps
 * the engine output. A multi-statement solution runs in one engine
 * client session — the graded result set is the output of its last
 * instruction. Guard violations are reported through
 * ExecutionResult::error, never thrown; only provisioning failures
 * throw RuntimeException after a Failed row is persisted and the
 * half-created container removed.
 *
 * Config is read at call time (the PaymentGateway/LlmClient pattern),
 * never cached in the constructor. The manager never touches the
 * `docker` binary itself — everything goes through the DockerClient
 * interface, which keeps the unit tests daemon-free.
 */
final class DockerPracticeEnvironment implements PracticeEnvironmentManager
{
    use LogsPracticeEnvironmentEvents;

    private const DRIVER = 'docker';

    private const ERROR_META_COMMAND = 'Метакоманды клиента запрещены';

    private const ERROR_STATEMENT_KEYWORD = 'Запрос с %s запрещён';

    private const ERROR_TOO_MANY_STATEMENTS = 'Слишком много инструкций в решении';

    private const ERROR_TIMEOUT = 'Превышен таймаут исполнения запроса';

    private const ERROR_RESULT_SIZE = 'Результат превышает допустимый размер';

    private const ERROR_UNSUPPORTED_RUNTIME = 'Рантайм среды не поддерживается docker-драйвером практики';

    private const ERROR_DOCKER_CLIENT = 'Не удалось исполнить запрос из-за сбоя Docker-клиента';

    /**
     * Blacklisted first keywords per runtime: session/engine state
     * changes, privilege operations, file-system touching commands
     * and client-level exits from the SQL contract.
     *
     * @var array<string, non-empty-string>
     */
    private const KEYWORD_BLACKLIST = [
        'mysql' => '/^\s*(SET|GRANT|REVOKE|SHUTDOWN|RESET|FLUSH|KILL|SOURCE|PURGE|CREATE\s+USER|DROP\s+USER|ALTER\s+USER)\b/i',
        'postgres' => '/^\s*(SET|GRANT|REVOKE|COPY|LISTEN|NOTIFY|LOAD|ALTER\s+SYSTEM)\b/i',
    ];

    /**
     * The splitter/parser defaults keep direct instantiations (tests)
     * working; the container resolves and injects the shared
     * instances anyway.
     */
    public function __construct(
        private DockerClient $docker,
        private CanonicalResultSerializer $serializer,
        private SqlStatementSplitter $splitter = new SqlStatementSplitter,
        private DockerTableParser $parser = new DockerTableParser,
    ) {}

    public function provision(User $user, PracticeTaskInput $task): PracticeEnvironment
    {
        // A task pinned to anything but the docker SQL runtimes is a
        // content configuration error, not an infrastructure failure:
        // checked before any side effect, so no container is started
        // and no Failed row is persisted.
        if ($task->runtime !== PracticeRuntime::Mysql && $task->runtime !== PracticeRuntime::Postgres) {
            $runtimeValue = $task->runtime instanceof PracticeRuntime ? $task->runtime->value : 'null';

            throw new RuntimeException("Runtime [{$runtimeValue}] is not supported by the docker practice driver");
        }

        $runtime = $task->runtime;
        $startedAt = hrtime(true);
        $containerId = null;

        try {
            [$image, $engineArgs] = $this->runtimeConfig($runtime);

            $containerName = 'itlearns-practice-'.Str::uuid()->toString();

            $containerId = $this->docker->startContainer(
                image: $image,
                name: $containerName,
                engineArgs: $engineArgs,
                memoryMb: max(1, (int) config('practice.docker.memory_mb')),
                cpus: max(0.1, (float) config('practice.docker.cpus')),
                pidsLimit: max(1, (int) config('practice.docker.pids_limit')),
            );

            $this->awaitReadiness($containerId, $runtime);

            if ($task->seedScript !== null) {
                $seed = $this->docker->exec(
                    $containerId,
                    $this->engineClientCommand($runtime),
                    $task->seedScript."\n",
                    $this->provisionTimeoutSeconds(),
                );

                if ($seed->exitCode !== 0) {
                    throw new RuntimeException('engine seed script failed: '
                        .$this->trimEngineError($seed->errorOutput !== '' ? $seed->errorOutput : $seed->output));
                }
            }

            $environment = PracticeEnvironment::create([
                'user_id' => $user->id,
                'practice_task_id' => $task->taskId,
                'runtime' => $runtime,
                'status' => PracticeEnvironmentStatus::Ready,
                'connection_meta' => [
                    'container_id' => $containerId,
                    'container_name' => $containerName,
                    'image' => $image,
                    'runtime' => $runtime->value,
                ],
                'started_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Every failure path above leaves either a half-created
            // container or nothing at all; the container (if any) is
            // removed before the Failed row and the rethrow, so the
            // caller's destroy() has nothing left to leak.
            if (is_string($containerId) && $containerId !== '') {
                $this->docker->removeContainer($containerId);
            }

            $this->persistFailedEnvironment($user, $task, $e->getMessage());

            // The log context is capped exactly like the Failed row so
            // a runaway exception message cannot bloat the record.
            $this->logProvisioned(
                self::DRIVER,
                $runtime,
                $task->taskId,
                (hrtime(true) - $startedAt) / 1e6,
                $this->trimEngineError($e->getMessage()),
            );

            throw new RuntimeException('Unable to provision the practice environment: '.$e->getMessage(), 0, $e);
        }

        $this->logProvisioned(self::DRIVER, $runtime, $task->taskId, (hrtime(true) - $startedAt) / 1e6);

        return $environment;
    }

    public function execute(PracticeEnvironment $environment, string $code): ExecutionResult
    {
        $result = $this->runGuardedExecution($environment, $code);
        $runtime = $environment->runtime;

        // Metadata-only record (no student SQL), one per attempt.
        if ($runtime instanceof PracticeRuntime) {
            $this->logExecuted(self::DRIVER, $runtime, $environment->practice_task_id, $result->durationMs, $result->error);
        }

        return $result;
    }

    public function compare(ExecutionResult $actual, string $expectedHash): bool
    {
        if ($actual->error !== null) {
            return false;
        }

        return $this->serializer->hash($actual->rows ?? [], $actual->columns ?? []) === $expectedHash;
    }

    public function destroy(PracticeEnvironment $environment): void
    {
        $containerId = $this->containerId($environment);

        // removeContainer is idempotent (docker rm -f on a missing
        // container is suppressed inside the client), and a row with
        // broken metadata still bookends its lifecycle as Destroyed.
        if ($containerId !== '') {
            $this->docker->removeContainer($containerId);
        }

        // destroyed_at is deliberately not mass-assignable on the
        // model: the manager is its single writer, via direct
        // attribute assignment (see the PracticeEnvironment PHPDoc).
        $environment->status = PracticeEnvironmentStatus::Destroyed->value;
        $environment->destroyed_at = now()->toDateTimeString();
        $environment->save();

        $runtime = $environment->runtime;

        if ($runtime instanceof PracticeRuntime) {
            $this->logDestroyed(self::DRIVER, $runtime, $environment->practice_task_id);
        }
    }

    /**
     * The guarded execution pipeline; every outcome is an
     * ExecutionResult so execute() can never throw.
     */
    private function runGuardedExecution(PracticeEnvironment $environment, string $code): ExecutionResult
    {
        $runtime = $environment->runtime;

        // Defensive mirror of the provision guard for rows this driver
        // did not create — execute() must not throw even then.
        if ($runtime !== PracticeRuntime::Mysql && $runtime !== PracticeRuntime::Postgres) {
            return $this->rejected(self::ERROR_UNSUPPORTED_RUNTIME);
        }

        // Guard 1: psql meta-commands. In stdin mode the client
        // interprets them itself and \! reaches the shell inside the
        // container, so any line starting with a backslash is banned
        // before Docker is involved.
        $lines = preg_split('/\r\n|\r|\n/', $code);

        foreach (is_array($lines) ? $lines : [] as $line) {
            if (str_starts_with(ltrim($line), '\\')) {
                return $this->rejected(self::ERROR_META_COMMAND);
            }
        }

        // Guard 2: the statement-count cap. The shared splitter skips
        // comments and keeps literals intact, so every fragment below
        // surfaces with its real first keyword; a trailing ';' plus
        // whitespace stays tolerated as an empty fragment.
        $statements = array_values(array_filter(
            array_map(trim(...), $this->splitter->split($code)),
            static fn (string $statement): bool => $statement !== '',
        ));

        if (count($statements) > $this->maxStatements()) {
            return $this->rejected(self::ERROR_TOO_MANY_STATEMENTS);
        }

        // Guard 3: no statement may start with a blacklisted keyword
        // of the task's runtime — the check runs per statement, so a
        // banned command cannot ride in as a later instruction.
        $pattern = self::KEYWORD_BLACKLIST[$runtime->value] ?? '';

        if ($pattern !== '') {
            foreach ($statements as $statement) {
                if (preg_match($pattern, $statement, $matches) === 1) {
                    return $this->rejected(sprintf(self::ERROR_STATEMENT_KEYWORD, strtoupper($matches[1])));
                }
            }
        }

        try {
            $command = $this->engineClientCommand($runtime);
        } catch (RuntimeException $e) {
            // Only a broken practice.docker config lands here —
            // reported, not thrown, to honour the execute() contract.
            return $this->rejected($e->getMessage());
        }

        $timeoutSeconds = $this->timeoutSeconds();

        try {
            $executed = $this->docker->exec($this->containerId($environment), $command, $code."\n", $timeoutSeconds);
        } catch (Throwable $exception) {
            // The client contract already routes every failure through
            // the result object (CliDockerClient maps infra errors to
            // a non-zero exit); this shield is the last line of
            // defence against a misbehaving implementation or mock, so
            // execute() stays non-throwing no matter what.
            return new ExecutionResult(
                rows: null,
                columns: null,
                durationMs: 0.0,
                error: self::ERROR_DOCKER_CLIENT.': '.$this->trimEngineError($exception->getMessage()),
            );
        }

        $durationMs = $executed->durationMs;

        // Guard 4 (post-factum timeout), deliberately checked before
        // the exit code: a run hard-killed by the Process timeout
        // surfaces as a non-zero exit, but the budget verdict is the
        // friendly one and must win.
        if ($durationMs > $timeoutSeconds * 1000) {
            return new ExecutionResult(rows: null, columns: null, durationMs: $durationMs, error: self::ERROR_TIMEOUT);
        }

        if ($executed->exitCode !== 0) {
            return new ExecutionResult(
                rows: null,
                columns: null,
                durationMs: $durationMs,
                error: $this->trimEngineError($executed->errorOutput !== '' ? $executed->errorOutput : $executed->output),
            );
        }

        // Guard 5: the raw engine output must fit the byte budget.
        if (strlen($executed->output) > $this->maxResultBytes()) {
            return new ExecutionResult(rows: null, columns: null, durationMs: $durationMs, error: self::ERROR_RESULT_SIZE);
        }

        $parsed = $this->parser->parse(
            $executed->output,
            $runtime === PracticeRuntime::Postgres ? DockerTableParser::NULL_MARKER : null,
        );

        return new ExecutionResult(rows: $parsed['rows'], columns: $parsed['columns'], durationMs: $durationMs);
    }

    /**
     * Poll the engine client with SELECT 1 until it answers or the
     * provision budget is spent (the mysql:8 image alone needs
     * 10-30 s of boot time before the socket accepts queries).
     *
     * @throws RuntimeException when the engine never became ready
     */
    private function awaitReadiness(string $containerId, PracticeRuntime $runtime): void
    {
        $timeoutSeconds = $this->provisionTimeoutSeconds();
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $ping = $this->docker->exec($containerId, $this->engineClientCommand($runtime), 'SELECT 1;', $this->timeoutSeconds());

            if ($ping->exitCode === 0) {
                return;
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException("the {$runtime->value} engine did not become ready within {$timeoutSeconds} seconds");
            }

            sleep(1);
        }
    }

    /**
     * The argv of the in-container engine client. mysql --batch (and
     * psql -A -F "\t") emit tab-separated rows with the header first —
     * exactly what DockerTableParser consumes; -P null=<marker> makes
     * SQL NULL explicit for psql.
     *
     * @return list<string>
     *
     * @throws RuntimeException when the mysql root password cannot be derived from the config
     */
    private function engineClientCommand(PracticeRuntime $runtime): array
    {
        if ($runtime === PracticeRuntime::Postgres) {
            // Local socket connections inside the official image trust
            // the postgres OS user, so no password switch is needed.
            // ON_ERROR_STOP aborts the script on the first failed
            // statement instead of psql's default swallow-and-exit-0.
            return [
                'psql',
                '-U', 'postgres',
                '-A',
                '-F', "\t",
                '--no-psqlrc',
                '-P', 'null='.DockerTableParser::NULL_MARKER,
                '-v', 'ON_ERROR_STOP=1',
                '-q',
            ];
        }

        return ['mysql', '-uroot', '-p'.$this->mysqlRootPassword(), '--batch'];
    }

    /**
     * The mysql root password as configured for the container image.
     * It lives inside practice.docker.runtimes.mysql.engine_args (the
     * MYSQL_ROOT_PASSWORD env of the image), so it is extracted there
     * instead of being duplicated in a second config key. Every
     * `docker run` env spelling is understood: the two-token
     * ["-e", "KEY=VAL"], the single-token "-e KEY=VAL" and
     * "--env=KEY=VAL", plus the symmetric two-token ["--env", "KEY=VAL"].
     *
     * @throws RuntimeException with the accepted forms when no MYSQL_ROOT_PASSWORD assignment is found
     */
    private function mysqlRootPassword(): string
    {
        /** @var mixed $engineArgs */
        $engineArgs = config('practice.docker.runtimes.mysql.engine_args', []);

        $args = is_array($engineArgs)
            ? array_values(array_filter($engineArgs, is_string(...)))
            : [];

        foreach ($this->envAssignments($args) as $assignment) {
            if (preg_match('/^MYSQL_ROOT_PASSWORD=(.+)$/', $assignment, $matches) === 1) {
                return $matches[1];
            }
        }

        throw new RuntimeException(
            'practice.docker.runtimes.mysql requires a MYSQL_ROOT_PASSWORD engine arg '
            .'in one of the forms: ["-e", "MYSQL_ROOT_PASSWORD=value"], "-e MYSQL_ROOT_PASSWORD=value" '
            .'or "--env=MYSQL_ROOT_PASSWORD=value"',
        );
    }

    /**
     * Every KEY=VAL assignment carried by the engine args, in the
     * order of appearance, across all recognized env spellings.
     *
     * @param  list<string>  $args
     * @return list<string>
     */
    private function envAssignments(array $args): array
    {
        $assignments = [];
        $count = count($args);

        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];

            if (($arg === '-e' || $arg === '--env') && array_key_exists($i + 1, $args)) {
                $assignments[] = $args[$i + 1];
                $i++;

                continue;
            }

            if (preg_match('/^(?:-e\s+|--env=)(.+)$/', $arg, $matches) === 1) {
                $assignments[] = $matches[1];
            }
        }

        return $assignments;
    }

    /**
     * The image and docker-run args of the runtime from the config
     * whitelist; completeness is boot-validated, this is the runtime
     * re-check.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function runtimeConfig(PracticeRuntime $runtime): array
    {
        /** @var array<string, mixed> $config */
        $config = config("practice.docker.runtimes.{$runtime->value}", []);

        $image = $config['image'] ?? null;

        if (! is_string($image) || trim($image) === '') {
            throw new RuntimeException("practice.docker.runtimes.{$runtime->value}.image is not configured");
        }

        $engineArgs = $config['engine_args'] ?? [];

        $filtered = is_array($engineArgs)
            ? array_values(array_filter($engineArgs, is_string(...)))
            : [];

        return [$image, $filtered];
    }

    /**
     * The container id from connection_meta; '' when the row's
     * metadata is broken (the destroy path stays idempotent).
     */
    private function containerId(PracticeEnvironment $environment): string
    {
        $meta = $environment->connection_meta;

        if (! is_array($meta)) {
            return '';
        }

        $containerId = $meta['container_id'] ?? '';

        return is_string($containerId) ? $containerId : '';
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('practice.docker.timeout_seconds'));
    }

    private function provisionTimeoutSeconds(): int
    {
        return max(1, (int) config('practice.docker.provision_timeout_seconds'));
    }

    private function maxResultBytes(): int
    {
        return max(1, (int) config('practice.docker.max_result_bytes'));
    }

    private function maxStatements(): int
    {
        return max(1, (int) config('practice.docker.max_statements'));
    }

    /**
     * Rejection result shared by the pre-execution guards: nothing
     * ran, so the measured duration is zero.
     */
    private function rejected(string $error): ExecutionResult
    {
        return new ExecutionResult(rows: null, columns: null, durationMs: 0.0, error: $error);
    }

    /**
     * Cap an engine error to keep the attempt result small; the first
     * line of mysql/psql stderr already names the syntax position.
     */
    private function trimEngineError(string $error): string
    {
        return mb_substr(trim($error), 0, 500);
    }

    /**
     * Persist the Failed provisioning row so infrastructure failures
     * stay observable (the error text is capped to keep the row small).
     */
    private function persistFailedEnvironment(User $user, PracticeTaskInput $task, string $error): void
    {
        PracticeEnvironment::create([
            'user_id' => $user->id,
            'practice_task_id' => $task->taskId,
            'runtime' => $task->runtime,
            'status' => PracticeEnvironmentStatus::Failed,
            'started_at' => null,
            'error' => mb_substr($error, 0, 500),
        ]);
    }
}
