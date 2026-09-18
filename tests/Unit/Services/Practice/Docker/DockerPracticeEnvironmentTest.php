<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Practice\Docker;

use App\Enums\PracticeEnvironmentStatus;
use App\Enums\PracticeRuntime;
use App\Models\PracticeEnvironment;
use App\Models\User;
use App\Services\Practice\CanonicalResultSerializer;
use App\Services\Practice\Docker\DockerClient;
use App\Services\Practice\Docker\DockerPracticeEnvironment;
use App\Services\Practice\Docker\DockerTableParser;
use App\Services\Practice\Docker\Dto\DockerExecResult;
use App\Services\Practice\Dto\ExecutionResult;
use App\Services\Practice\Dto\PracticeTaskInput;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Unit coverage of the docker practice driver with the DockerClient
 * mocked (the RunPracticeTaskActionTest binding pattern): the
 * provision lifecycle (start + readiness + seed + Ready row, every
 * failure path cleaning the container up and persisting a Failed
 * row), the execution guards before any Docker call, the outcome
 * mapping of engine output, the hash comparison and the idempotent
 * destroy. No Docker daemon is needed.
 */
class DockerPracticeEnvironmentTest extends TestCase
{
    private const CODE = 'SELECT id, name FROM clients ORDER BY id;';

    private const MYSQL_CLIENT = ['mysql', '-uroot', '-ppractice', '--batch'];

    private const PSQL_CLIENT = [
        'psql',
        '-U', 'postgres',
        '-A',
        '-F', "\t",
        '--no-psqlrc',
        '-P', 'null='.DockerTableParser::NULL_MARKER,
        '-v', 'ON_ERROR_STOP=1',
        '-q',
    ];

    private MockInterface $docker;

    private DockerPracticeEnvironment $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->docker = Mockery::mock(DockerClient::class);
        $this->instance(DockerClient::class, $this->docker);

        $this->manager = app(DockerPracticeEnvironment::class);
    }

    public function test_provision_mysql_happy_path_persists_a_ready_environment(): void
    {
        $user = User::factory()->create();

        $this->docker->shouldReceive('startContainer')->once()->with(
            'mysql:8',
            Mockery::pattern('/^itlearns-practice-[0-9a-f-]{36}$/'),
            ['-e', 'MYSQL_ROOT_PASSWORD=practice'],
            512,
            0.5,
            128,
        )->andReturn('cid-1');

        $this->expectMysqlReadiness('cid-1');
        $this->docker->shouldReceive('exec')->once()->with(
            'cid-1',
            self::MYSQL_CLIENT,
            "CREATE TABLE clients (id INT, name TEXT);\n",
            60,
        )->andReturn(new DockerExecResult(0, '', '', 30.0));

        $environment = $this->manager->provision($user, new PracticeTaskInput(
            taskId: 7,
            seedScript: 'CREATE TABLE clients (id INT, name TEXT);',
            runtime: PracticeRuntime::Mysql,
        ));

        $this->assertSame(PracticeEnvironmentStatus::Ready, $environment->status);
        $this->assertSame(PracticeRuntime::Mysql, $environment->runtime);
        $this->assertNotNull($environment->started_at);

        $meta = $environment->connection_meta;
        $this->assertIsArray($meta);

        $this->assertSame('cid-1', $meta['container_id']);
        $this->assertSame('mysql:8', $meta['image']);
        $this->assertSame('mysql', $meta['runtime']);
        $this->assertMatchesRegularExpression('/^itlearns-practice-[0-9a-f-]{36}$/', $meta['container_name']);
    }

    public function test_provision_postgres_uses_the_psql_client(): void
    {
        $user = User::factory()->create();

        $this->docker->shouldReceive('startContainer')->once()->with(
            'postgres:16',
            Mockery::pattern('/^itlearns-practice-/'),
            ['-e', 'POSTGRES_PASSWORD=practice'],
            512,
            0.5,
            128,
        )->andReturn('cid-pg');

        $this->docker->shouldReceive('exec')->once()->with(
            'cid-pg',
            self::PSQL_CLIENT,
            'SELECT 1;',
            20,
        )->andReturn(new DockerExecResult(0, "1\n", '', 2.0));

        $environment = $this->manager->provision($user, new PracticeTaskInput(runtime: PracticeRuntime::Postgres));

        $this->assertSame(PracticeEnvironmentStatus::Ready, $environment->status);
        $this->assertSame(PracticeRuntime::Postgres, $environment->runtime);

        $meta = $environment->connection_meta;
        $this->assertIsArray($meta);
        $this->assertSame('cid-pg', $meta['container_id']);
    }

    /**
     * @param  list<string>  $engineArgs  the practice.docker.runtimes.mysql.engine_args spelling
     * @param  string  $password  the MYSQL_ROOT_PASSWORD the mysql client must receive
     */
    #[DataProvider('mysqlEnvFormProvider')]
    public function test_provision_reads_the_mysql_root_password_from_every_env_form(array $engineArgs, string $password): void
    {
        config(['practice.docker.runtimes.mysql.engine_args' => $engineArgs]);

        $this->docker->shouldReceive('startContainer')->once()->andReturn('cid-pw');
        $this->docker->shouldReceive('exec')->once()->with(
            'cid-pw',
            ['mysql', '-uroot', '-p'.$password, '--batch'],
            'SELECT 1;',
            20,
        )->andReturn(new DockerExecResult(0, "1\n", '', 1.0));

        $environment = $this->manager->provision(User::factory()->create(), new PracticeTaskInput(runtime: PracticeRuntime::Mysql));

        $this->assertSame(PracticeEnvironmentStatus::Ready, $environment->status);
    }

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function mysqlEnvFormProvider(): array
    {
        return [
            'two-token -e' => [['-e', 'MYSQL_ROOT_PASSWORD=practice'], 'practice'],
            'single-token -e with space' => [['-e MYSQL_ROOT_PASSWORD=practice'], 'practice'],
            'long --env= form' => [['--env=MYSQL_ROOT_PASSWORD=practice'], 'practice'],
            'two-token --env' => [['--env', 'MYSQL_ROOT_PASSWORD=practice'], 'practice'],
            'mixed with other envs' => [['-e', 'MYSQL_DATABASE=learn', '-e', 'MYSQL_ROOT_PASSWORD=s3cret'], 's3cret'],
        ];
    }

    public function test_provision_names_the_accepted_env_forms_when_the_password_is_not_recognized(): void
    {
        // A bare assignment without a docker flag is not a valid env
        // arg — the error must name the accepted forms precisely.
        config(['practice.docker.runtimes.mysql.engine_args' => ['MYSQL_ROOT_PASSWORD=practice']]);

        $user = User::factory()->create();

        $this->docker->shouldReceive('startContainer')->once()->andReturn('cid-nopw');
        $this->docker->shouldReceive('removeContainer')->once()->with('cid-nopw');

        try {
            $this->manager->provision($user, new PracticeTaskInput(runtime: PracticeRuntime::Mysql));
            $this->fail('Expected a RuntimeException for the unrecognized env form.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('requires a MYSQL_ROOT_PASSWORD engine arg', $exception->getMessage());
            $this->assertStringContainsString('"-e", "MYSQL_ROOT_PASSWORD=value"', $exception->getMessage());
            $this->assertStringContainsString('"-e MYSQL_ROOT_PASSWORD=value"', $exception->getMessage());
            $this->assertStringContainsString('"--env=MYSQL_ROOT_PASSWORD=value"', $exception->getMessage());
        }
    }

    /**
     * @param  PracticeRuntime|null  $runtime  a runtime the docker driver must refuse
     */
    #[DataProvider('unsupportedRuntimeProvider')]
    public function test_provision_rejects_non_docker_runtimes_before_any_side_effect(?PracticeRuntime $runtime): void
    {
        $user = User::factory()->create();

        try {
            $this->manager->provision($user, new PracticeTaskInput(runtime: $runtime));
            $this->fail('Expected a RuntimeException for the unsupported runtime.');
        } catch (RuntimeException $exception) {
            $runtimeValue = $runtime instanceof PracticeRuntime ? $runtime->value : 'null';

            $this->assertStringContainsString(
                "Runtime [{$runtimeValue}] is not supported by the docker practice driver",
                $exception->getMessage(),
            );
        }

        // The strict mock fails the test if any Docker call happens,
        // and no Failed row may be persisted for a content error.
        $this->assertSame(0, PracticeEnvironment::count());
    }

    /**
     * @return array<string, array{0: PracticeRuntime|null}>
     */
    public static function unsupportedRuntimeProvider(): array
    {
        return [
            'sqlite runtime' => [PracticeRuntime::Sqlite],
            'runtime not pinned' => [null],
        ];
    }

    public function test_provision_readiness_timeout_cleans_up_and_persists_a_failed_row(): void
    {
        config(['practice.docker.provision_timeout_seconds' => 1]);

        $user = User::factory()->create();

        $this->docker->shouldReceive('startContainer')->once()->andReturn('cid-slow');
        $this->docker->shouldReceive('exec')->atLeast()->once()->andReturn(new DockerExecResult(1, '', 'engine booting', 0.1));
        $this->docker->shouldReceive('removeContainer')->once()->with('cid-slow');

        try {
            $this->manager->provision($user, new PracticeTaskInput(runtime: PracticeRuntime::Mysql));
            $this->fail('Expected a RuntimeException from the exhausted readiness budget.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('did not become ready within 1 seconds', $exception->getMessage());
            $this->assertStringContainsString('Unable to provision the practice environment', $exception->getMessage());
        }

        $failed = PracticeEnvironment::query()->where('status', PracticeEnvironmentStatus::Failed)->first();

        $this->assertNotNull($failed);
        $this->assertSame(PracticeRuntime::Mysql, $failed->runtime);
        $this->assertStringContainsString('did not become ready', (string) $failed->error);
    }

    public function test_provision_seed_failure_cleans_up_and_persists_a_failed_row(): void
    {
        $user = User::factory()->create();

        $this->docker->shouldReceive('startContainer')->once()->andReturn('cid-seed');
        $this->expectMysqlReadiness('cid-seed');
        $this->docker->shouldReceive('exec')->once()->with(
            'cid-seed',
            self::MYSQL_CLIENT,
            "CREATE BROKEN;\n",
            60,
        )->andReturn(new DockerExecResult(1, '', 'ERROR 1064: syntax error', 0.4));
        $this->docker->shouldReceive('removeContainer')->once()->with('cid-seed');

        try {
            $this->manager->provision($user, new PracticeTaskInput(
                seedScript: 'CREATE BROKEN;',
                runtime: PracticeRuntime::Mysql,
            ));
            $this->fail('Expected a RuntimeException from the failed seed script.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('engine seed script failed', $exception->getMessage());
            $this->assertStringContainsString('ERROR 1064', $exception->getMessage());
        }

        $failed = PracticeEnvironment::query()->where('status', PracticeEnvironmentStatus::Failed)->first();

        $this->assertNotNull($failed);
        $this->assertStringContainsString('seed script failed', (string) $failed->error);
    }

    public function test_provision_row_create_failure_cleans_up_and_persists_a_failed_row(): void
    {
        $user = User::factory()->create();

        $this->docker->shouldReceive('startContainer')->once()->andReturn('cid-db');
        $this->expectMysqlReadiness('cid-db');
        $this->docker->shouldReceive('removeContainer')->once()->with('cid-db');

        // The first create (the Ready row) fails like a transient DB
        // hiccup; the Failed-row create must still succeed.
        $creates = 0;

        PracticeEnvironment::creating(function () use (&$creates): void {
            if (++$creates === 1) {
                throw new RuntimeException('database unavailable');
            }
        });

        try {
            $this->manager->provision($user, new PracticeTaskInput(runtime: PracticeRuntime::Mysql));
            $this->fail('Expected a RuntimeException from the failed environment row.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Unable to provision the practice environment: database unavailable', $exception->getMessage());
        } finally {
            PracticeEnvironment::flushEventListeners();
        }

        $this->assertSame(
            1,
            PracticeEnvironment::query()->where('status', PracticeEnvironmentStatus::Failed)->count(),
        );
    }

    public function test_execute_maps_the_engine_tsv_output_onto_rows_and_columns(): void
    {
        $environment = $this->createDockerEnvironment('cid-exec', PracticeRuntime::Mysql);

        $this->docker->shouldReceive('exec')->once()->with(
            'cid-exec',
            self::MYSQL_CLIENT,
            self::CODE."\n",
            20,
        )->andReturn(new DockerExecResult(0, "id\tname\n1\tAlice\n2\tBob\n", '', 12.5));

        $result = $this->manager->execute($environment, self::CODE);

        $this->assertNull($result->error);
        $this->assertSame(['id', 'name'], $result->columns);
        $this->assertSame([
            ['id' => '1', 'name' => 'Alice'],
            ['id' => '2', 'name' => 'Bob'],
        ], $result->rows);
        $this->assertSame(12.5, $result->durationMs);
    }

    public function test_execute_uses_the_psql_client_and_the_null_marker_for_postgres(): void
    {
        $environment = $this->createDockerEnvironment('cid-pg', PracticeRuntime::Postgres);

        $this->docker->shouldReceive('exec')->once()->with(
            'cid-pg',
            self::PSQL_CLIENT,
            self::CODE."\n",
            20,
        )->andReturn(new DockerExecResult(
            0,
            "id\tname\n1\t".DockerTableParser::NULL_MARKER."\n2\tBob\n",
            '',
            8.0,
        ));

        $result = $this->manager->execute($environment, self::CODE);

        $this->assertNull($result->error);
        $this->assertNull($result->rows[0]['name']);
        $this->assertSame('Bob', $result->rows[1]['name']);
    }

    /**
     * @param  string  $errorOutput  the engine stream carrying the failure text
     * @param  string  $output  the other stream
     */
    #[DataProvider('engineErrorStreamProvider')]
    public function test_execute_reports_the_engine_error_for_a_non_zero_exit(string $errorOutput, string $output): void
    {
        $environment = $this->createDockerEnvironment('cid-err', PracticeRuntime::Mysql);

        $this->docker->shouldReceive('exec')->once()->andReturn(
            new DockerExecResult(1, $output, $errorOutput, 3.0),
        );

        $result = $this->manager->execute($environment, 'SELECT boom FROM missing;');

        $this->assertSame('ERROR 1054 (42S22): Unknown column', $result->error);
        $this->assertNull($result->rows);
        $this->assertNull($result->columns);
        $this->assertSame(3.0, $result->durationMs);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function engineErrorStreamProvider(): array
    {
        return [
            'stderr carries the error' => ['ERROR 1054 (42S22): Unknown column', 'irrelevant stdout'],
            'stdout fallback' => ['', 'ERROR 1054 (42S22): Unknown column'],
        ];
    }

    public function test_execute_rejects_a_long_engine_error_to_five_hundred_chars(): void
    {
        $environment = $this->createDockerEnvironment('cid-err', PracticeRuntime::Mysql);

        $this->docker->shouldReceive('exec')->once()->andReturn(
            new DockerExecResult(1, '', 'x'.str_repeat('a', 900), 1.0),
        );

        $result = $this->manager->execute($environment, 'SELECT boom;');

        $this->assertSame(500, mb_strlen($result->error ?? ''));
    }

    #[DataProvider('metaCommandProvider')]
    public function test_execute_rejects_psql_meta_commands_before_any_docker_call(string $code): void
    {
        $environment = $this->createDockerEnvironment('cid-meta', PracticeRuntime::Postgres);

        $result = $this->manager->execute($environment, $code);

        $this->assertSame('Метакоманды клиента запрещены', $result->error);
        $this->assertSame(0.0, $result->durationMs);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function metaCommandProvider(): array
    {
        return [
            'shell escape' => ["SELECT 1;\n\\! rm -rf /"],
            'client quit' => ['\q'],
            'indented meta command' => ["  \\copy t FROM '/etc/passwd'"],
            'crlf separated' => ["SELECT 1;\r\n\\timing"],
        ];
    }

    public function test_execute_runs_multiple_statements_in_one_exec(): void
    {
        $environment = $this->createDockerEnvironment('cid-multi', PracticeRuntime::Mysql);

        $code = "INSERT INTO t (id) VALUES (1);\nSELECT id FROM t;";

        // One docker exec with the raw code: the whole script shares a
        // single engine client session, and the TSV output of the last
        // instruction is the graded result.
        $this->docker->shouldReceive('exec')->once()->with(
            'cid-multi',
            self::MYSQL_CLIENT,
            $code."\n",
            20,
        )->andReturn(new DockerExecResult(0, "id\n1\n", '', 1.0));

        $result = $this->manager->execute($environment, $code);

        $this->assertNull($result->error);
        $this->assertSame(['id'], $result->columns);
        $this->assertSame([['id' => '1']], $result->rows);
    }

    /**
     * @param  PracticeRuntime  $runtime  the environment's engine
     * @param  string  $code  the rejected statement
     * @param  string  $keyword  the expected blacklisted keyword in the message
     */
    #[DataProvider('blacklistProvider')]
    public function test_execute_rejects_blacklisted_first_keywords_per_runtime(PracticeRuntime $runtime, string $code, string $keyword): void
    {
        $environment = $this->createDockerEnvironment('cid-bl', $runtime);

        $result = $this->manager->execute($environment, $code);

        $this->assertSame(sprintf('Запрос с %s запрещён', $keyword), $result->error);
        $this->assertSame(0.0, $result->durationMs);
    }

    /**
     * @return array<string, array{0: PracticeRuntime, 1: string, 2: string}>
     */
    public static function blacklistProvider(): array
    {
        return [
            'mysql SET' => [PracticeRuntime::Mysql, 'SET GLOBAL max_connections = 1000', 'SET'],
            'mysql GRANT' => [PracticeRuntime::Mysql, 'grant all on *.* to student', 'GRANT'],
            'mysql CREATE USER' => [PracticeRuntime::Mysql, 'create user student identified by \'x\'', 'CREATE USER'],
            'mysql DROP USER' => [PracticeRuntime::Mysql, 'DROP USER student', 'DROP USER'],
            'mysql FLUSH' => [PracticeRuntime::Mysql, 'FLUSH TABLES', 'FLUSH'],
            'mysql SHUTDOWN' => [PracticeRuntime::Mysql, 'SHUTDOWN', 'SHUTDOWN'],
            'postgres SET' => [PracticeRuntime::Postgres, 'SET statement_timeout = 0', 'SET'],
            'postgres COPY' => [PracticeRuntime::Postgres, "COPY t FROM '/etc/passwd'", 'COPY'],
            'postgres NOTIFY' => [PracticeRuntime::Postgres, 'NOTIFY channel', 'NOTIFY'],
            'postgres ALTER SYSTEM' => [PracticeRuntime::Postgres, 'alter system set fsync = off', 'ALTER SYSTEM'],
            'postgres LOAD' => [PracticeRuntime::Postgres, "LOAD 'evil.so'", 'LOAD'],
        ];
    }

    /**
     * @param  PracticeRuntime  $runtime  the environment's engine
     * @param  string  $code  the solution whose later statement carries the banned keyword
     * @param  string  $keyword  the expected blacklisted keyword in the message
     */
    #[DataProvider('blacklistInAnyStatementProvider')]
    public function test_execute_rejects_blacklisted_keywords_in_any_statement(PracticeRuntime $runtime, string $code, string $keyword): void
    {
        $environment = $this->createDockerEnvironment('cid-bl2', $runtime);

        $result = $this->manager->execute($environment, $code);

        $this->assertSame(sprintf('Запрос с %s запрещён', $keyword), $result->error);
        $this->assertSame(0.0, $result->durationMs);
    }

    /**
     * @return array<string, array{0: PracticeRuntime, 1: string, 2: string}>
     */
    public static function blacklistInAnyStatementProvider(): array
    {
        return [
            'mysql SET as the second statement' => [
                PracticeRuntime::Mysql,
                'SELECT 1; SET GLOBAL max_connections = 1000',
                'SET',
            ],
            'postgres NOTIFY as the second statement' => [
                PracticeRuntime::Postgres,
                'SELECT 1; NOTIFY channel',
                'NOTIFY',
            ],
            'mysql SET after an empty leading statement' => [
                PracticeRuntime::Mysql,
                '; SET GLOBAL max_connections = 1000',
                'SET',
            ],
        ];
    }

    public function test_execute_rejects_too_many_statements(): void
    {
        config(['practice.docker.max_statements' => 2]);

        $environment = $this->createDockerEnvironment('cid-cap', PracticeRuntime::Mysql);

        // Three statements against a cap of two: rejected before any
        // Docker call (the strict mock fails the test on an exec).
        $result = $this->manager->execute($environment, 'SELECT 1; SELECT 2; SELECT 3');

        $this->assertSame('Слишком много инструкций в решении', $result->error);
        $this->assertSame(0.0, $result->durationMs);

        // The boundary case: exactly at the cap the script still runs.
        $this->docker->shouldReceive('exec')->once()->with(
            'cid-cap',
            self::MYSQL_CLIENT,
            "SELECT 1; SELECT 2\n",
            20,
        )->andReturn(new DockerExecResult(0, "1\n2\n", '', 1.0));

        $boundary = $this->manager->execute($environment, 'SELECT 1; SELECT 2');

        $this->assertNull($boundary->error);
    }

    public function test_execute_allows_a_trailing_semicolon_and_comment_masks(): void
    {
        $environment = $this->createDockerEnvironment('cid-ok', PracticeRuntime::Mysql);

        $code = "-- harmless comment\nSELECT 1;";

        $this->docker->shouldReceive('exec')->once()->with(
            'cid-ok',
            self::MYSQL_CLIENT,
            $code."\n",
            20,
        )->andReturn(new DockerExecResult(0, "1\n1\n", '', 1.0));

        $result = $this->manager->execute($environment, $code);

        $this->assertNull($result->error);
        $this->assertSame(['1'], $result->columns);
        $this->assertSame([['1' => '1']], $result->rows);
    }

    public function test_execute_surfaces_the_friendly_timeout_for_a_slow_query(): void
    {
        $environment = $this->createDockerEnvironment('cid-slowq', PracticeRuntime::Mysql);

        $this->docker->shouldReceive('exec')->once()->andReturn(new DockerExecResult(0, "1\n", '', 25_000.0));

        $result = $this->manager->execute($environment, 'SELECT SLEEP(999);');

        $this->assertSame('Превышен таймаут исполнения запроса', $result->error);
        $this->assertSame(25_000.0, $result->durationMs);
    }

    public function test_execute_surfaces_the_friendly_timeout_for_a_hard_killed_run(): void
    {
        $environment = $this->createDockerEnvironment('cid-killed', PracticeRuntime::Mysql);

        // A Process-timeout kill arrives as exit code 124 with the raw
        // timeout text — the budget verdict must still win.
        $this->docker->shouldReceive('exec')->once()->andReturn(
            new DockerExecResult(124, '', 'The process "docker" exceeded the timeout of 25 seconds.', 25_500.0),
        );

        $result = $this->manager->execute($environment, 'SELECT SLEEP(999);');

        $this->assertSame('Превышен таймаут исполнения запроса', $result->error);
    }

    public function test_execute_enforces_the_result_size_limit(): void
    {
        config(['practice.docker.max_result_bytes' => 16]);

        $environment = $this->createDockerEnvironment('cid-big', PracticeRuntime::Mysql);

        $this->docker->shouldReceive('exec')->once()->andReturn(new DockerExecResult(0, str_repeat('x', 32), '', 1.0));

        $result = $this->manager->execute($environment, 'SELECT repeat;');

        $this->assertSame('Результат превышает допустимый размер', $result->error);
        $this->assertNull($result->rows);
    }

    public function test_execute_rejects_a_row_of_a_foreign_driver(): void
    {
        $environment = PracticeEnvironment::factory()->create([
            'runtime' => PracticeRuntime::Sqlite,
            'connection_meta' => ['path' => '/tmp/foreign.sqlite'],
        ]);

        $result = $this->manager->execute($environment, 'SELECT 1;');

        $this->assertSame('Рантайм среды не поддерживается docker-драйвером практики', $result->error);
    }

    /**
     * The client contract routes every failure through the result, but
     * a misbehaving implementation (or mock) that throws anyway must
     * still not break the execute()-never-throws guarantee.
     */
    public function test_execute_returns_an_error_result_when_the_docker_client_throws(): void
    {
        $environment = $this->createDockerEnvironment('cid-throw', PracticeRuntime::Mysql);

        $this->docker->shouldReceive('exec')->once()->andThrow(new RuntimeException('docker daemon exploded'));

        $result = $this->manager->execute($environment, 'SELECT 1;');

        $this->assertSame(
            'Не удалось исполнить запрос из-за сбоя Docker-клиента: docker daemon exploded',
            $result->error,
        );
        $this->assertNull($result->rows);
        $this->assertNull($result->columns);
        $this->assertSame(0.0, $result->durationMs);
    }

    public function test_compare_uses_the_canonical_hash(): void
    {
        $serializer = new CanonicalResultSerializer;

        $rows = [['id' => '1', 'name' => 'Alice']];
        $columns = ['id', 'name'];

        $passing = new ExecutionResult($rows, $columns, 1.0);
        $failing = new ExecutionResult([['id' => '2', 'name' => 'Bob']], $columns, 1.0);
        $errored = new ExecutionResult(null, null, 1.0, 'Превышен таймаут исполнения запроса');

        $this->assertTrue($this->manager->compare($passing, $serializer->hash($rows, $columns)));
        $this->assertFalse($this->manager->compare($failing, $serializer->hash($rows, $columns)));
        $this->assertFalse($this->manager->compare($errored, $serializer->hash($rows, $columns)));
    }

    public function test_destroy_removes_the_container_and_bookends_the_row(): void
    {
        $environment = $this->createDockerEnvironment('cid-destroy', PracticeRuntime::Mysql);

        $this->docker->shouldReceive('removeContainer')->once()->with('cid-destroy');

        $this->manager->destroy($environment);

        $environment->refresh();

        $this->assertSame(PracticeEnvironmentStatus::Destroyed, $environment->status);
        $this->assertNotNull($environment->destroyed_at);
    }

    public function test_destroy_is_idempotent(): void
    {
        $environment = $this->createDockerEnvironment('cid-twice', PracticeRuntime::Postgres);

        $this->docker->shouldReceive('removeContainer')->twice()->with('cid-twice');

        $this->manager->destroy($environment);
        $this->manager->destroy($environment);

        $environment->refresh();

        $this->assertSame(PracticeEnvironmentStatus::Destroyed, $environment->status);
    }

    public function test_destroy_tolerates_broken_metadata(): void
    {
        $environment = PracticeEnvironment::factory()->create([
            'runtime' => PracticeRuntime::Mysql,
            'connection_meta' => null,
        ]);

        // The strict mock fails the test if removeContainer runs.
        $this->manager->destroy($environment);

        $environment->refresh();

        $this->assertSame(PracticeEnvironmentStatus::Destroyed, $environment->status);
    }

    public function test_provisioned_event_is_logged_with_metadata_only(): void
    {
        Log::shouldReceive('info')->once()->with('practice.environment_provisioned', Mockery::on(function (array $context): bool {
            return $context['driver'] === 'docker'
                && $context['runtime'] === 'mysql'
                && $context['task_id'] === 7
                && is_int($context['duration_ms'])
                && $context['error'] === null;
        }));

        $this->docker->shouldReceive('startContainer')->once()->andReturn('cid-log');
        $this->expectMysqlReadiness('cid-log');

        $this->manager->provision(User::factory()->create(), new PracticeTaskInput(
            taskId: 7,
            runtime: PracticeRuntime::Mysql,
        ));
    }

    public function test_provisioned_failure_log_caps_the_error_to_five_hundred_chars(): void
    {
        Log::shouldReceive('info')->once()->with('practice.environment_provisioned', Mockery::on(function (array $context): bool {
            return mb_strlen((string) $context['error']) <= 500
                && str_starts_with((string) $context['error'], 'boom');
        }));

        $this->docker->shouldReceive('startContainer')->once()->andThrow(
            new RuntimeException('boom'.str_repeat('x', 900)),
        );

        try {
            $this->manager->provision(User::factory()->create(), new PracticeTaskInput(runtime: PracticeRuntime::Mysql));
        } catch (RuntimeException) {
            // Expected — the capped log record is what is asserted here.
        }
    }

    public function test_executed_event_is_logged_with_the_error_reason(): void
    {
        Log::shouldReceive('info')->once()->with('practice.environment_executed', Mockery::on(function (array $context): bool {
            return $context['driver'] === 'docker'
                && $context['runtime'] === 'mysql'
                && $context['task_id'] === 9
                && is_int($context['duration_ms'])
                && $context['error'] === 'ERROR 1064: syntax';
        }));

        $environment = $this->createDockerEnvironment('cid-log2', PracticeRuntime::Mysql, 9);

        $this->docker->shouldReceive('exec')->once()->andReturn(new DockerExecResult(1, '', 'ERROR 1064: syntax', 2.0));

        $result = $this->manager->execute($environment, 'SELECT boom;');

        $this->assertSame('ERROR 1064: syntax', $result->error);
    }

    public function test_destroyed_event_is_logged(): void
    {
        Log::shouldReceive('info')->once()->with('practice.environment_destroyed', Mockery::on(function (array $context): bool {
            return $context['driver'] === 'docker'
                && $context['runtime'] === 'postgres'
                && $context['task_id'] === 5;
        }));

        $environment = $this->createDockerEnvironment('cid-log3', PracticeRuntime::Postgres, 5);

        $this->docker->shouldReceive('removeContainer')->once();

        $this->manager->destroy($environment);
    }

    /**
     * Expect the successful readiness probe of a mysql container.
     */
    private function expectMysqlReadiness(string $containerId): void
    {
        $this->docker->shouldReceive('exec')->once()->with(
            $containerId,
            self::MYSQL_CLIENT,
            'SELECT 1;',
            20,
        )->andReturn(new DockerExecResult(0, "1\n", '', 5.0));
    }

    /**
     * Persist a Ready docker environment row for execute/destroy tests.
     */
    private function createDockerEnvironment(string $containerId, PracticeRuntime $runtime, ?int $taskId = null): PracticeEnvironment
    {
        return PracticeEnvironment::factory()->create([
            'practice_task_id' => $taskId,
            'runtime' => $runtime,
            'connection_meta' => [
                'container_id' => $containerId,
                'container_name' => 'itlearns-practice-test',
                'image' => $runtime === PracticeRuntime::Mysql ? 'mysql:8' : 'postgres:16',
                'runtime' => $runtime->value,
            ],
        ]);
    }
}
