<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Practice;

use App\Enums\PracticeEnvironmentStatus;
use App\Enums\PracticeRuntime;
use App\Models\PracticeEnvironment;
use App\Models\User;
use App\Services\Practice\CanonicalResultSerializer;
use App\Services\Practice\Dto\ExecutionResult;
use App\Services\Practice\Dto\PracticeTaskInput;
use App\Services\Practice\LocalSqlitePracticeEnvironment;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LocalSqlitePracticeEnvironmentTest extends TestCase
{
    private const RECURSIVE_CTE_COUNT = 'WITH RECURSIVE c(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM c WHERE x < 2000000) SELECT COUNT(*) AS total FROM c';

    private string $storagePath;

    private LocalSqlitePracticeEnvironment $manager;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate the practice files in a unique temp directory per
        // test run — never in the real storage/framework/practice.
        $this->storagePath = sys_get_temp_dir().'/practice-test-'.Str::uuid()->toString();
        config(['practice.storage_path' => $this->storagePath]);

        $this->manager = new LocalSqlitePracticeEnvironment(new CanonicalResultSerializer);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);

        parent::tearDown();
    }

    public function test_provision_creates_ready_environment_with_seeded_file(): void
    {
        $environment = $this->provisionEnvironment();

        $path = $this->environmentPath($environment);

        $this->assertSame(PracticeEnvironmentStatus::Ready, $environment->status);
        $this->assertSame(PracticeRuntime::Sqlite, $environment->runtime);
        $this->assertSame(7, $environment->practice_task_id);
        $this->assertNotNull($environment->started_at);
        $this->assertFileExists($path);

        // The seed script ran: the seeded rows are visible to execute().
        $result = $this->manager->execute($environment, 'SELECT name FROM users ORDER BY id');

        $this->assertNull($result->error);
        $this->assertSame([['name' => 'Alice'], ['name' => 'Bob']], $result->rows);
        $this->assertDatabaseHas('practice_environments', [
            'user_id' => $environment->user_id,
            'status' => PracticeEnvironmentStatus::Ready->value,
            'runtime' => PracticeRuntime::Sqlite->value,
        ]);
    }

    public function test_provision_without_seed_creates_empty_ready_database(): void
    {
        $environment = $this->provisionEnvironment(seedScript: null);

        $result = $this->manager->execute($environment, 'SELECT 1 AS one');

        $this->assertNull($result->error);
        $this->assertSame([['one' => 1]], $result->rows);
    }

    public function test_provision_with_explicit_sqlite_runtime_succeeds(): void
    {
        $environment = $this->provisionEnvironment(runtime: PracticeRuntime::Sqlite);

        $this->assertSame(PracticeEnvironmentStatus::Ready, $environment->status);
        $this->assertSame(PracticeRuntime::Sqlite, $environment->runtime);
    }

    #[DataProvider('nonSqliteRuntimeProvider')]
    public function test_provision_with_non_sqlite_runtime_throws_before_any_side_effect(PracticeRuntime $runtime): void
    {
        $user = User::factory()->create();

        try {
            $this->manager->provision($user, new PracticeTaskInput(
                taskId: 7,
                seedScript: 'CREATE TABLE users (id INTEGER PRIMARY KEY);',
                runtime: $runtime,
            ));
            $this->fail('RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not supported by the local-sqlite practice driver', $e->getMessage());
            $this->assertStringContainsString($runtime->value, $e->getMessage());
        }

        // A content configuration error, not an infrastructure
        // failure: the guard fires before any side effect — no
        // practice_environments row (not even a Failed one), no
        // database file, the storage directory is never created.
        $this->assertDatabaseMissing('practice_environments', ['user_id' => $user->id]);
        $this->assertFileDoesNotExist($this->storagePath);
        $this->assertSame([], glob($this->storagePath.'/*.sqlite') ?: []);
    }

    /**
     * @return array<string, list<PracticeRuntime>>
     */
    public static function nonSqliteRuntimeProvider(): array
    {
        return [
            'mysql' => [PracticeRuntime::Mysql],
            'postgres' => [PracticeRuntime::Postgres],
        ];
    }

    public function test_provision_with_failing_seed_persists_failed_row_and_throws(): void
    {
        $user = User::factory()->create();

        try {
            $this->manager->provision($user, new PracticeTaskInput(seedScript: 'THIS IS NOT SQL'));
            $this->fail('RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Unable to provision', $e->getMessage());
        }

        $failed = PracticeEnvironment::query()->where('user_id', $user->id)->first();

        $this->assertNotNull($failed);
        $this->assertSame(PracticeEnvironmentStatus::Failed, $failed->status);
        $this->assertNotNull($failed->error);
        $this->assertNull($failed->started_at);

        // The half-created database file is removed.
        $this->assertSame([], glob($this->storagePath.'/*.sqlite') ?: []);
    }

    public function test_execute_select_returns_rows_columns_and_duration(): void
    {
        $environment = $this->provisionEnvironment();

        $result = $this->manager->execute($environment, 'SELECT name FROM users ORDER BY id');

        $this->assertNull($result->error);
        $this->assertSame([['name' => 'Alice'], ['name' => 'Bob']], $result->rows);
        $this->assertSame(['name'], $result->columns);
        $this->assertGreaterThan(0, $result->durationMs);
    }

    public function test_execute_select_without_rows_returns_empty_rows_and_columns(): void
    {
        $environment = $this->provisionEnvironment();

        $result = $this->manager->execute($environment, 'SELECT name FROM users WHERE 0');

        $this->assertNull($result->error);
        $this->assertSame([], $result->rows);
        $this->assertSame([], $result->columns);
    }

    public function test_execute_insert_and_update_return_no_rows_but_take_effect(): void
    {
        $environment = $this->provisionEnvironment();

        $insert = $this->manager->execute($environment, "INSERT INTO users (name) VALUES ('Carol')");

        $this->assertNull($insert->error);
        $this->assertNull($insert->rows);
        $this->assertNull($insert->columns);

        $update = $this->manager->execute($environment, "UPDATE users SET name = 'Dave' WHERE name = 'Alice'");

        $this->assertNull($update->error);
        $this->assertNull($update->rows);

        $count = $this->manager->execute($environment, 'SELECT COUNT(*) AS total FROM users');
        $names = $this->manager->execute($environment, 'SELECT name FROM users ORDER BY id');

        $this->assertSame([['total' => 3]], $count->rows);
        $this->assertSame([['name' => 'Dave'], ['name' => 'Bob'], ['name' => 'Carol']], $names->rows);
    }

    public function test_execute_syntax_error_returns_error_result(): void
    {
        $environment = $this->provisionEnvironment();

        $result = $this->manager->execute($environment, 'SELECT FROM users');

        $this->assertNotNull($result->error);
        $this->assertStringContainsStringIgnoringCase('syntax', $result->error);
        $this->assertNull($result->rows);
        $this->assertNull($result->columns);
    }

    #[DataProvider('attachProvider')]
    public function test_execute_rejects_attach(string $code): void
    {
        $environment = $this->provisionEnvironment();

        $result = $this->manager->execute($environment, $code);

        $this->assertSame('Запрос с ATTACH DATABASE запрещён', $result->error);
        $this->assertNull($result->rows);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function attachProvider(): array
    {
        return [
            'ATTACH DATABASE' => ["ATTACH DATABASE 'other.db' AS other"],
            'ATTACH SCHEMA' => ["ATTACH SCHEMA 'other.db' AS other"],
            'embedded after statement' => ["SELECT 1; ATTACH DATABASE 'other.db' AS other"],
        ];
    }

    #[DataProvider('blacklistedKeywordProvider')]
    public function test_execute_rejects_blacklisted_first_keyword(string $code, string $expectedError): void
    {
        $environment = $this->provisionEnvironment();

        $result = $this->manager->execute($environment, $code);

        $this->assertSame($expectedError, $result->error);
        $this->assertNull($result->rows);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function blacklistedKeywordProvider(): array
    {
        return [
            'PRAGMA' => ['PRAGMA max_page_count = 0', 'Запрос с PRAGMA запрещён'],
            'VACUUM' => ['VACUUM', 'Запрос с VACUUM запрещён'],
            'DETACH' => ['DETACH DATABASE other', 'Запрос с DETACH запрещён'],
            'REINDEX' => ['REINDEX', 'Запрос с REINDEX запрещён'],
            'bare ATTACH without DATABASE keyword' => ["ATTACH 'other.db' AS other", 'Запрос с ATTACH запрещён'],
            'block comment before PRAGMA' => ['/* x */ PRAGMA max_page_count = 0', 'Запрос с PRAGMA запрещён'],
            'line comment before VACUUM' => ["-- comment\nVACUUM", 'Запрос с VACUUM запрещён'],
            'block comment before bare ATTACH' => ["/* x */ ATTACH 'f' AS x", 'Запрос с ATTACH запрещён'],
        ];
    }

    #[DataProvider('multipleStatementsProvider')]
    public function test_execute_rejects_multiple_statements(string $code): void
    {
        $environment = $this->provisionEnvironment();

        $result = $this->manager->execute($environment, $code);

        $this->assertSame('Множественные statements запрещены', $result->error);
        $this->assertNull($result->rows);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function multipleStatementsProvider(): array
    {
        return [
            'two selects' => ['SELECT 1; SELECT 2'],
            'two inserts' => ["INSERT INTO users (name) VALUES ('X'); INSERT INTO users (name) VALUES ('Y')"],
            'destructive second statement' => ['DROP TABLE users; SELECT 1'],
            'lowercase' => ['select 1; select 2'],
        ];
    }

    public function test_execute_allows_semicolon_inside_string_literal(): void
    {
        $environment = $this->provisionEnvironment();

        $plain = $this->manager->execute($environment, "SELECT 'a;b' AS marker");
        $escapedQuote = $this->manager->execute($environment, "SELECT 'it''s;fine' AS marker");

        $this->assertNull($plain->error);
        $this->assertSame([['marker' => 'a;b']], $plain->rows);

        $this->assertNull($escapedQuote->error);
        $this->assertSame([['marker' => "it's;fine"]], $escapedQuote->rows);
    }

    public function test_execute_allows_comments_around_semicolons_and_trailing_semicolon(): void
    {
        $environment = $this->provisionEnvironment();

        $trailing = $this->manager->execute($environment, 'SELECT 1 AS one;');
        $lineComment = $this->manager->execute($environment, 'SELECT 1 AS one -- ; not a separator');
        $blockComment = $this->manager->execute($environment, '/* ; */ SELECT 1 AS one');

        foreach ([$trailing, $lineComment, $blockComment] as $result) {
            $this->assertNull($result->error);
            $this->assertSame([['one' => 1]], $result->rows);
        }
    }

    public function test_execute_rejects_query_exceeding_timeout(): void
    {
        config(['practice.sqlite.timeout_seconds' => 0]);

        $environment = $this->provisionEnvironment();

        $result = $this->manager->execute($environment, 'SELECT 1 AS one');

        $this->assertSame('Превышен таймаут исполнения запроса', $result->error);
        $this->assertNull($result->rows);
        $this->assertGreaterThanOrEqual(0, $result->durationMs);
    }

    public function test_execute_measures_duration_after_result_materialization(): void
    {
        $environment = $this->provisionEnvironment();

        // The lazy pdo_sqlite driver returns from query() almost
        // immediately and steps through the result set inside
        // fetchAll() — the 2M-row recursive CTE therefore proves the
        // duration is measured after materialization, not at the
        // query() call. Fast enough to stay under the default 5s
        // budget, slow enough to exceed the 5ms assertion floor.
        $result = $this->manager->execute($environment, self::RECURSIVE_CTE_COUNT);

        $this->assertNull($result->error);
        $this->assertSame([['total' => 2000000]], $result->rows);
        $this->assertGreaterThan(5, $result->durationMs);
    }

    public function test_execute_rejects_exhausted_timeout_on_long_running_cte(): void
    {
        config(['practice.sqlite.timeout_seconds' => 0]);

        $environment = $this->provisionEnvironment();

        $result = $this->manager->execute($environment, self::RECURSIVE_CTE_COUNT);

        $this->assertSame('Превышен таймаут исполнения запроса', $result->error);
        $this->assertNull($result->rows);
    }

    public function test_execute_rejects_result_exceeding_size_limit(): void
    {
        config(['practice.sqlite.max_result_bytes' => 8]);

        $environment = $this->provisionEnvironment();

        $result = $this->manager->execute($environment, 'SELECT name FROM users');

        $this->assertSame('Результат превышает допустимый размер', $result->error);
        $this->assertNull($result->rows);
    }

    public function test_execute_rejects_binary_result_that_cannot_be_json_encoded(): void
    {
        $environment = $this->provisionEnvironment();

        // json_encode() fails on invalid UTF-8 even for a tiny
        // payload; the guard treats the failed encode as a size
        // violation — the deterministic fail-safe branch that used
        // to bypass the limit via (string) false === ''.
        $result = $this->manager->execute($environment, "SELECT CAST(x'FF00FF' AS TEXT) AS b");

        $this->assertSame('Результат превышает допустимый размер', $result->error);
        $this->assertNull($result->rows);
    }

    public function test_execute_rejects_large_binary_result_despite_failed_json_encoding(): void
    {
        $environment = $this->provisionEnvironment();

        // randomblob(2000000) exceeds the default 1 MB result budget
        // and is binary, so json_encode() returns false — the guard
        // must report the size violation instead of letting the
        // payload through as an empty string.
        $result = $this->manager->execute($environment, 'SELECT randomblob(2000000) AS b');

        $this->assertSame('Результат превышает допустимый размер', $result->error);
        $this->assertNull($result->rows);
    }

    public function test_execute_rejects_write_beyond_database_size_limit(): void
    {
        config(['practice.sqlite.max_db_bytes' => 32768]);

        $environment = $this->provisionEnvironment(
            seedScript: 'CREATE TABLE blobs (payload BLOB);',
        );

        $result = $this->manager->execute($environment, 'INSERT INTO blobs (payload) VALUES (randomblob(65536))');

        $this->assertNotNull($result->error);
        // SQLITE_FULL surfaces as "database or disk is full".
        $this->assertStringContainsStringIgnoringCase('full', $result->error);
        $this->assertNull($result->rows);
    }

    public function test_compare_matches_canonical_hash_and_rejects_mismatches(): void
    {
        $serializer = new CanonicalResultSerializer;

        $result = new ExecutionResult(
            rows: [['name' => 'Alice'], ['name' => 'Bob']],
            columns: ['name'],
            durationMs: 1.0,
        );

        // Canonical: the same row order with different string case and
        // surrounding whitespace still hashes to the same value — but a
        // different row order would not (the expected result defines
        // the order).
        $expected = $serializer->hash([['name' => 'alice'], ['name' => 'bob ']], ['name']);

        $this->assertTrue($this->manager->compare($result, $expected));

        $different = $serializer->hash([['name' => 'carol']], ['name']);

        $this->assertFalse($this->manager->compare($result, $different));
    }

    public function test_compare_returns_false_for_error_result(): void
    {
        $serializer = new CanonicalResultSerializer;

        $expected = $serializer->hash([['name' => 'Alice']], ['name']);

        $errorResult = new ExecutionResult(rows: null, columns: null, durationMs: 0.0, error: 'boom');

        $this->assertFalse($this->manager->compare($errorResult, $expected));
    }

    public function test_destroy_removes_file_marks_row_and_is_idempotent(): void
    {
        $environment = $this->provisionEnvironment();

        $path = $this->environmentPath($environment);
        $this->assertFileExists($path);

        $this->manager->destroy($environment);

        $this->assertFileDoesNotExist($path);

        $destroyed = $environment->fresh();
        $this->assertNotNull($destroyed);
        $this->assertSame(PracticeEnvironmentStatus::Destroyed, $destroyed->status);
        $this->assertNotNull($destroyed->destroyed_at);

        // A second destroy must not fail and must stay consistent.
        $this->manager->destroy($destroyed);

        $this->assertFileDoesNotExist($path);
        $this->assertSame(PracticeEnvironmentStatus::Destroyed, $destroyed->fresh()->status);
    }

    public function test_destroy_removes_file_even_after_failed_execution(): void
    {
        $environment = $this->provisionEnvironment();

        $path = $this->environmentPath($environment);

        $failed = $this->manager->execute($environment, 'SELECT FROM users');
        $this->assertNotNull($failed->error);

        // The calling action's finally-block contract: destroy must
        // actually delete the file even after a failed execution
        // (guards the Windows + xdebug file-lock regression).
        $this->manager->destroy($environment);

        $this->assertFileDoesNotExist($path);
        $this->assertSame(PracticeEnvironmentStatus::Destroyed, $environment->fresh()->status);
    }

    private function provisionEnvironment(
        ?string $seedScript = 'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users (name) VALUES (\'Alice\'), (\'Bob\');',
        ?PracticeRuntime $runtime = null,
    ): PracticeEnvironment {
        $user = User::factory()->create();

        return $this->manager->provision($user, new PracticeTaskInput(
            taskId: 7,
            taskText: 'Выберите всех пользователей',
            seedScript: $seedScript,
            runtime: $runtime,
        ));
    }

    private function environmentPath(PracticeEnvironment $environment): string
    {
        $meta = $environment->connection_meta;

        if (! is_array($meta)) {
            $this->fail('connection_meta is expected to be an array with a path');
        }

        return (string) ($meta['path'] ?? '');
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
