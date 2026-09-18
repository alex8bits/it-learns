<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\VerifyPracticeTask;
use App\Enums\PracticeAttemptStatus;
use App\Models\PracticeEnvironment;
use App\Models\User;
use App\Services\Practice\CanonicalResultSerializer;
use App\Services\Practice\Dto\ExecutionResult;
use App\Services\Practice\Dto\PracticeTaskInput;
use App\Services\Practice\PracticeEnvironmentManager;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class VerifyPracticeTaskTest extends TestCase
{
    private const CODE = 'SELECT id, title, year FROM books';

    private const SEED_SQL = 'CREATE TABLE books (id INTEGER PRIMARY KEY, title TEXT, year INTEGER);';

    /**
     * @return list<array<string, mixed>>
     */
    public static function expectedRows(): array
    {
        return [
            ['id' => 1, 'title' => 'SQL Basics', 'year' => 2020],
            ['id' => 2, 'title' => 'Advanced SQL', 'year' => 2021],
        ];
    }

    public function test_hashes_expected_rows_with_first_row_columns_and_delegates(): void
    {
        $admin = User::factory()->create();
        $rows = self::expectedRows();
        $environment = PracticeEnvironment::factory()->create(['user_id' => $admin->id]);
        $manager = $this->bindManagerMock();
        $captured = null;

        $manager->shouldReceive('provision')->once()->andReturnUsing(
            function (User $user, PracticeTaskInput $input) use (&$captured, $admin, $environment): PracticeEnvironment {
                $this->assertSame($admin, $user);
                $captured = $input;

                return $environment;
            },
        );
        $manager->shouldReceive('execute')->once()->with($environment, self::CODE)
            ->andReturn(new ExecutionResult($rows, ['id', 'title', 'year'], 1.0));
        $manager->shouldReceive('compare')->once()->andReturn(true);
        $manager->shouldReceive('destroy')->once()->with($environment);

        $outcome = app(VerifyPracticeTask::class)->execute($admin, self::CODE, self::SEED_SQL, $rows);

        assert($captured instanceof PracticeTaskInput);

        // The hash is derived exactly like the stored task's one: the
        // canonical serializer over the rows with the columns taken from
        // the first row.
        $expectedHash = (new CanonicalResultSerializer)->hash($rows, array_keys($rows[0]));
        $this->assertSame($expectedHash, $captured->expectedHash);
        $this->assertSame(self::SEED_SQL, $captured->seedScript);
        $this->assertSame($expectedHash, $outcome->expectedHash);
        $this->assertSame(PracticeAttemptStatus::Passed, $outcome->status);
    }

    public function test_input_carries_no_task_identity(): void
    {
        $admin = User::factory()->create();
        $environment = PracticeEnvironment::factory()->create(['user_id' => $admin->id]);
        $manager = $this->bindManagerMock();
        $captured = null;

        $this->stubCycle($manager, $environment, $captured);
        $manager->shouldReceive('compare')->once()->andReturn(true);

        app(VerifyPracticeTask::class)->execute($admin, self::CODE, self::SEED_SQL, [['id' => 1]]);

        assert($captured instanceof PracticeTaskInput);

        // The check is a stateless draft tool: no task id, no task text.
        $this->assertNull($captured->taskId);
        $this->assertNull($captured->taskText);
    }

    public function test_null_expected_rows_produce_null_hash_without_compare(): void
    {
        $admin = User::factory()->create();
        $environment = PracticeEnvironment::factory()->create(['user_id' => $admin->id]);
        $manager = $this->bindManagerMock();
        $captured = null;

        $this->stubCycle($manager, $environment, $captured);

        // Without a reference result there is nothing to compare against —
        // the harness contract treats a null hash informationally.
        $manager->shouldReceive('compare')->never();

        $outcome = app(VerifyPracticeTask::class)->execute($admin, self::CODE, 'SELECT 1 AS setup;', null);

        assert($captured instanceof PracticeTaskInput);
        $this->assertNull($captured->expectedHash);
        $this->assertNull($outcome->expectedHash);
        $this->assertSame(PracticeAttemptStatus::Failed, $outcome->status);
    }

    public function test_null_seed_script_is_passed_through(): void
    {
        $admin = User::factory()->create();
        $environment = PracticeEnvironment::factory()->create(['user_id' => $admin->id]);
        $manager = $this->bindManagerMock();
        $captured = null;

        $this->stubCycle($manager, $environment, $captured);
        $manager->shouldReceive('compare')->once()->andReturn(true);

        app(VerifyPracticeTask::class)->execute($admin, self::CODE, null, [['id' => 1]]);

        assert($captured instanceof PracticeTaskInput);
        $this->assertNull($captured->seedScript);
    }

    public function test_hash_mismatch_resolves_to_failed(): void
    {
        $admin = User::factory()->create();
        $environment = PracticeEnvironment::factory()->create(['user_id' => $admin->id]);
        $manager = $this->bindManagerMock();

        $manager->shouldReceive('provision')->once()->andReturn($environment);
        $manager->shouldReceive('execute')->once()->andReturn(new ExecutionResult([['id' => 9]], ['id'], 0.5));
        $manager->shouldReceive('compare')->once()->andReturn(false);
        $manager->shouldReceive('destroy')->once()->with($environment);

        $outcome = app(VerifyPracticeTask::class)->execute($admin, self::CODE, self::SEED_SQL, self::expectedRows());

        $this->assertSame(PracticeAttemptStatus::Failed, $outcome->status);
    }

    public function test_busy_lock_is_reported_with_the_expected_hash_echo(): void
    {
        $admin = User::factory()->create();
        $rows = self::expectedRows();
        $manager = $this->bindManagerMock();
        $manager->shouldReceive('provision')->never();
        $manager->shouldReceive('execute')->never();
        $manager->shouldReceive('destroy')->never();

        // Hold the per-user environment lock: the harness must return the
        // Busy outcome unchanged instead of provisioning anything.
        $this->assertTrue(Cache::lock('practice-env:'.$admin->id, 10)->get());

        $outcome = app(VerifyPracticeTask::class)->execute($admin, self::CODE, self::SEED_SQL, $rows);

        $expectedHash = (new CanonicalResultSerializer)->hash($rows, array_keys($rows[0]));
        $this->assertSame(PracticeAttemptStatus::Busy, $outcome->status);
        $this->assertNull($outcome->result);
        $this->assertSame($expectedHash, $outcome->expectedHash);
    }

    /**
     * Bind a mocked PracticeEnvironmentManager (the pattern of
     * RunPracticeTaskActionTest) and return the mock for per-test
     * expectations.
     */
    private function bindManagerMock(): MockInterface
    {
        $manager = Mockery::mock(PracticeEnvironmentManager::class);
        $this->instance(PracticeEnvironmentManager::class, $manager);

        return $manager;
    }

    /**
     * Stub the happy provision/execute/destroy cycle, capturing the
     * PracticeTaskInput handed to provision(). The `compare` expectation
     * is deliberately left to the caller — it depends on whether the
     * check runs with a reference hash at all.
     *
     * @param  PracticeTaskInput|null  $captured  by-ref capture slot
     */
    private function stubCycle(MockInterface $manager, PracticeEnvironment $environment, ?PracticeTaskInput &$captured): void
    {
        $manager->shouldReceive('provision')->once()->andReturnUsing(
            function (User $user, PracticeTaskInput $input) use (&$captured, $environment): PracticeEnvironment {
                $captured = $input;

                return $environment;
            },
        );
        $manager->shouldReceive('execute')->once()->andReturn(new ExecutionResult([['id' => 1]], ['id'], 1.0));
        $manager->shouldReceive('destroy')->once()->with($environment);
    }
}
