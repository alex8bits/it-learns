<?php

declare(strict_types=1);

namespace App\Services\Practice;

use App\Enums\PracticeEnvironmentStatus;
use App\Enums\PracticeRuntime;
use App\Models\PracticeEnvironment;
use App\Models\User;
use App\Services\Practice\Dto\ExecutionResult;
use App\Services\Practice\Dto\PracticeTaskInput;
use App\Support\Practice\SqlStatementSplitter;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Stage 5 practice runtime: a disposable per-attempt SQLite file under
 * config('practice.storage_path') (docs/concept.md §5.4, variant A).
 *
 * Safety model — the six mandatory guards of docs/concept.md §5.4:
 * the ATTACH ban, single-statement enforcement, the result size
 * limit and the post-factum timeout live in execute(); the database
 * size limit is enforced on the engine level via PRAGMA
 * max_page_count (re-applied on every connection, because a fresh
 * PDO handle starts without it and a student must not be able to
 * lift it between calls). Extension loading is enforced by
 * construction: PHP's PDO exposes no SQLite extension-loading API,
 * so enableLoadExtension() is unreachable — the design risk note
 * recording why no explicit call exists.
 *
 * Guard violations are reported through ExecutionResult::error,
 * never thrown; only provisioning failures throw RuntimeException.
 * Config is read at call time (the PaymentGateway/LlmClient
 * pattern), never cached in the constructor.
 *
 * Windows + xdebug caveat, decisive for the error handling style:
 * when an exception is thrown while a PDO instance is in scope,
 * xdebug's throw hook snapshots the local scope and keeps the
 * connection referenced for the rest of the request — the SQLite
 * file stays locked and every later unlink (including destroy() in
 * the calling action's finally) silently fails. Statements that may
 * legitimately fail (the seed script, the student's query) therefore
 * run in PDO::ERRMODE_SILENT with the failure reason read from
 * errorInfo(); a rejected query is a normal attempt outcome, not an
 * exception. Connections are released before any exception can be
 * thrown next to them.
 */
final class LocalSqlitePracticeEnvironment implements PracticeEnvironmentManager
{
    private const ERROR_ATTACH = 'Запрос с ATTACH DATABASE запрещён';

    private const ERROR_STATEMENT_KEYWORD = 'Запрос с %s запрещён';

    private const ERROR_MULTIPLE_STATEMENTS = 'Множественные statements запрещены';

    private const ERROR_TIMEOUT = 'Превышен таймаут исполнения запроса';

    private const ERROR_RESULT_SIZE = 'Результат превышает допустимый размер';

    /**
     * The splitter default keeps direct instantiations (tests) working;
     * the container resolves and injects the shared instance anyway.
     */
    public function __construct(
        private CanonicalResultSerializer $serializer,
        private SqlStatementSplitter $splitter = new SqlStatementSplitter,
    ) {}

    public function provision(User $user, PracticeTaskInput $task): PracticeEnvironment
    {
        // A task pinned to a non-SQLite runtime is a content
        // configuration error, not an infrastructure failure: checked
        // before any side effect, so no Failed row is persisted and
        // no database file is created.
        if ($task->runtime !== null && $task->runtime !== PracticeRuntime::Sqlite) {
            throw new RuntimeException("Runtime [{$task->runtime->value}] is not supported by the local-sqlite practice driver");
        }

        $dir = (string) config('practice.storage_path');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir.'/'.Str::uuid()->toString().'.sqlite';

        $pdo = null;

        try {
            $pdo = $this->openPdo($path);

            $seedError = $task->seedScript !== null
                ? $this->executeSeedScript($pdo, $task->seedScript)
                : null;
        } catch (Throwable $e) {
            // Only openPdo() can throw here — when the PDO constructor
            // fails no live connection exists — so the cleanup unlink
            // below is safe despite the exception being in flight.
            $pdo = null;

            $this->removeDatabaseFile($path);

            $this->persistFailedEnvironment($user, $task, $e->getMessage());

            throw new RuntimeException('Unable to provision the practice environment: '.$e->getMessage(), 0, $e);
        }

        // Release the connection before anything can throw next to
        // it — see the class PHPDoc on the Windows + xdebug file lock.
        $pdo = null;

        if ($seedError !== null) {
            $this->removeDatabaseFile($path);

            $this->persistFailedEnvironment($user, $task, 'SQLite seed script failed: '.$seedError);

            throw new RuntimeException('Unable to provision the practice environment: SQLite seed script failed: '.$seedError);
        }

        try {
            return PracticeEnvironment::create([
                'user_id' => $user->id,
                'practice_task_id' => $task->taskId,
                'runtime' => PracticeRuntime::Sqlite,
                'status' => PracticeEnvironmentStatus::Ready,
                'connection_meta' => ['path' => $path],
                'started_at' => now(),
            ]);
        } catch (Throwable $e) {
            // The connection is already released above, so the file
            // can be removed even with the exception in flight.
            $this->removeDatabaseFile($path);

            $this->persistFailedEnvironment($user, $task, $e->getMessage());

            throw new RuntimeException('Unable to provision the practice environment: '.$e->getMessage(), 0, $e);
        }
    }

    public function execute(PracticeEnvironment $environment, string $code): ExecutionResult
    {
        // Guard 1: ATTACH anywhere in the code (SQLite-specific, the
        // risk is reading arbitrary files).
        if (preg_match('/\bATTACH\s+(?:DATABASE|SCHEMA)\b/i', $code) === 1) {
            return $this->rejected(self::ERROR_ATTACH);
        }

        // Quote-aware statement split. The scanner skips -- line
        // comments and block comments instead of copying them, so
        // each statement surfaces with its real first keyword:
        // SQLite executes a comment-prefixed statement, so keeping
        // the comment would let "comment + PRAGMA …" slip past the
        // keyword guard below. Semicolons inside '…'/"…" literals
        // stay literal text.
        $statements = array_values(array_filter(
            array_map(trim(...), $this->splitter->split($code)),
            static fn (string $statement): bool => $statement !== '',
        ));

        $sql = $statements[0] ?? '';

        // Guard 2: statements must not start with a blacklisted
        // keyword. PRAGMA would let a student lift max_page_count,
        // VACUUM rewrites the file, ATTACH/DETACH/REINDEX touch files
        // outside the sandbox contract.
        if (preg_match('/^\s*(PRAGMA|VACUUM|ATTACH|DETACH|REINDEX)\b/i', $sql, $matches) === 1) {
            return $this->rejected(sprintf(self::ERROR_STATEMENT_KEYWORD, strtoupper($matches[1])));
        }

        // Guard 3: a single statement only; a single trailing ';'
        // (plus whitespace) is tolerated as an empty fragment.
        if (count($statements) > 1) {
            return $this->rejected(self::ERROR_MULTIPLE_STATEMENTS);
        }

        $startedAt = hrtime(true);

        try {
            $pdo = $this->openPdo($this->environmentPath($environment));
        } catch (PDOException $e) {
            // The connection could not be opened at all (missing
            // file, unreadable directory): no handle exists, nothing
            // to pin — reporting via the error result is safe.
            return new ExecutionResult(
                rows: null,
                columns: null,
                durationMs: (hrtime(true) - $startedAt) / 1e6,
                error: $e->getMessage(),
            );
        }

        // The student's query must not throw while the connection is
        // alive (class PHPDoc: the Windows + xdebug file lock) — the
        // failure reason is read from errorInfo() instead.
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        $stmt = $pdo->query($sql);

        if ($stmt === false) {
            return new ExecutionResult(
                rows: null,
                columns: null,
                durationMs: (hrtime(true) - $startedAt) / 1e6,
                error: $this->lastError($pdo),
            );
        }

        if ($stmt->columnCount() > 0) {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $columns = $rows !== [] ? array_keys($rows[0]) : [];
        } else {
            $rows = null;
            $columns = null;
        }

        // The duration is measured after the rows are materialized:
        // the lazy pdo_sqlite driver steps through the result set
        // inside fetchAll(), not query(), so measuring at query()
        // would let a long-running SELECT escape the timeout budget
        // of guard 4 below.
        $durationMs = (hrtime(true) - $startedAt) / 1e6;

        // Guard 4 (post-factum): the timeout is measured after the
        // run because a synchronous PDO call cannot be interrupted
        // from PHP — the result is discarded once the budget is spent.
        $timeoutMs = ((int) config('practice.sqlite.timeout_seconds')) * 1000;

        if ($durationMs > $timeoutMs) {
            return new ExecutionResult(
                rows: null,
                columns: null,
                durationMs: $durationMs,
                error: self::ERROR_TIMEOUT,
            );
        }

        // Guard 5: the serialized result set must fit the byte budget.
        // json_encode() returns false on binary and invalid-UTF-8
        // payloads (CAST(x'…' AS TEXT), randomblob()); casting that
        // false to a string would produce '' and bypass the limit
        // entirely, so a failed encode is treated as a size
        // violation — the fail-safe outcome.
        $encoded = $rows !== null ? json_encode($rows, JSON_UNESCAPED_UNICODE) : '';

        if ($encoded === false || strlen($encoded) > (int) config('practice.sqlite.max_result_bytes')) {
            return new ExecutionResult(
                rows: null,
                columns: null,
                durationMs: $durationMs,
                error: self::ERROR_RESULT_SIZE,
            );
        }

        return new ExecutionResult(rows: $rows, columns: $columns, durationMs: $durationMs);
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
        $this->removeDatabaseFile($this->environmentPath($environment));

        // destroyed_at is deliberately not mass-assignable on the
        // model: the manager is its single writer, via direct
        // attribute assignment (see the PracticeEnvironment PHPDoc).
        // The backed-enum and datetime casts accept the stored scalar
        // forms and hydrate them back on read.
        $environment->status = PracticeEnvironmentStatus::Destroyed->value;
        $environment->destroyed_at = now()->toDateTimeString();
        $environment->save();
    }

    /**
     * Open the practice database with exceptions enabled and the
     * engine-level size limit applied — a fresh connection starts
     * without the limit, so every open re-applies it (guard 6: a
     * student must not fill the disk).
     */
    private function openPdo(string $path): PDO
    {
        $pdo = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pageSizeStatement = $pdo->query('PRAGMA page_size');

        $pageSize = $pageSizeStatement === false ? 0 : (int) $pageSizeStatement->fetchColumn();

        $maxBytes = max(1, (int) config('practice.sqlite.max_db_bytes'));

        $maxPageCount = (int) ceil($maxBytes / max(1, $pageSize));

        $pdo->exec('PRAGMA max_page_count = '.$maxPageCount);

        return $pdo;
    }

    /**
     * Execute the trusted seed script (multiple statements allowed)
     * and report the failure reason instead of throwing.
     *
     * Silent mode + errorInfo() instead of the naive
     * ERRMODE_EXCEPTION exec is deliberate: a PDOException carries
     * the PDO instance in its stack trace (the throwing PDO::exec
     * frame), and with xdebug loaded the throw hook snapshots the
     * local scope — the connection stays referenced, the SQLite file
     * stays locked on Windows, and the cleanup unlink in provision()
     * silently fails. The RuntimeException for a failed seed is
     * thrown from provision() only after the connection is released.
     *
     * @return string|null SQLite error message on failure, null on success
     */
    private function executeSeedScript(PDO $pdo, string $seedScript): ?string
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        return $pdo->exec($seedScript) !== false ? null : $this->lastError($pdo);
    }

    /**
     * Human-readable SQLite error of the last failed statement.
     */
    private function lastError(PDO $pdo): string
    {
        $errorInfo = $pdo->errorInfo();

        return isset($errorInfo[2]) && is_string($errorInfo[2])
            ? $errorInfo[2]
            : 'unknown SQLite error';
    }

    /**
     * Absolute path of the environment database file from
     * connection_meta (the only metadata the local runtime stores —
     * no secrets).
     */
    private function environmentPath(PracticeEnvironment $environment): string
    {
        $meta = $environment->connection_meta;

        if (! is_array($meta)) {
            return '';
        }

        $path = $meta['path'] ?? '';

        return is_string($path) ? $path : '';
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
     * Delete the database file if it still exists. Failures are
     * suppressed: the file may already be gone (idempotent destroy)
     * or held by a handle that outlived the release discipline above.
     */
    private function removeDatabaseFile(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
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
            'runtime' => PracticeRuntime::Sqlite,
            'status' => PracticeEnvironmentStatus::Failed,
            'started_at' => null,
            'error' => mb_substr($error, 0, 500),
        ]);
    }
}
