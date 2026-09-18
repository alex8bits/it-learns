<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Practice;

use App\Actions\Practice\RunPracticeTaskAction;
use App\Enums\PracticeAttemptStatus;
use App\Models\PracticeEnvironment;
use App\Models\User;
use App\Services\Practice\Dto\ExecutionResult;
use App\Services\Practice\Dto\PracticeRunOutcome;
use App\Services\Practice\Dto\PracticeTaskInput;
use App\Services\Practice\PracticeEnvironmentManager;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class RunPracticeTaskActionTest extends TestCase
{
    private const CODE = 'SELECT name FROM clients ORDER BY name LIMIT 3';

    private const EXPECTED_HASH = '2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824';

    private const EXECUTION_ERROR = 'Превышен таймаут исполнения запроса';

    public function test_passing_attempt_resolves_to_passed(): void
    {
        $user = User::factory()->create();

        $environment = $this->createEnvironmentRow($user);
        $manager = $this->bindManagerMock();
        $manager->shouldReceive('provision')->once()->andReturn($environment);
        $manager->shouldReceive('execute')->once()->andReturn(new ExecutionResult([['name' => 'Alice']], ['name'], 1.25));
        $manager->shouldReceive('compare')->once()->andReturn(true);
        $manager->shouldReceive('destroy')->once()->with($environment);

        $outcome = $this->runAction($user, new PracticeTaskInput(expectedHash: self::EXPECTED_HASH));

        $this->assertSame(PracticeAttemptStatus::Passed, $outcome->status);
        $this->assertSame(self::EXPECTED_HASH, $outcome->expectedHash);
        $this->assertNotNull($outcome->result);
        $this->assertSame([['name' => 'Alice']], $outcome->result->rows);
    }

    public function test_hash_mismatch_resolves_to_failed(): void
    {
        $user = User::factory()->create();

        $environment = $this->createEnvironmentRow($user);
        $manager = $this->bindManagerMock();
        $manager->shouldReceive('provision')->once()->andReturn($environment);
        $manager->shouldReceive('execute')->once()->andReturn(new ExecutionResult([['name' => 'Bob']], ['name'], 0.5));
        $manager->shouldReceive('compare')->once()->andReturn(false);
        $manager->shouldReceive('destroy')->once()->with($environment);

        $outcome = $this->runAction($user, new PracticeTaskInput(expectedHash: self::EXPECTED_HASH));

        $this->assertSame(PracticeAttemptStatus::Failed, $outcome->status);
    }

    public function test_error_result_maps_to_error_status_without_compare(): void
    {
        $user = User::factory()->create();

        $environment = $this->createEnvironmentRow($user);
        $manager = $this->bindManagerMock();
        $manager->shouldReceive('provision')->once()->andReturn($environment);
        $manager->shouldReceive('execute')->once()->andReturn(new ExecutionResult(null, null, 0.4, self::EXECUTION_ERROR));
        $manager->shouldReceive('compare')->never();
        $manager->shouldReceive('destroy')->once()->with($environment);

        $outcome = $this->runAction($user, new PracticeTaskInput(expectedHash: self::EXPECTED_HASH));

        $this->assertSame(PracticeAttemptStatus::Error, $outcome->status);
        $this->assertSame(self::EXECUTION_ERROR, $outcome->result?->error);
    }

    public function test_busy_outcome_when_lock_is_already_held(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Cache::lock('practice-env:'.$user->id, 10)->get());

        $manager = $this->bindManagerMock();
        $manager->shouldReceive('provision')->never();
        $manager->shouldReceive('execute')->never();
        $manager->shouldReceive('destroy')->never();

        $outcome = $this->runAction($user, new PracticeTaskInput(expectedHash: self::EXPECTED_HASH));

        $this->assertSame(PracticeAttemptStatus::Busy, $outcome->status);
        $this->assertNull($outcome->result);
        $this->assertSame(self::EXPECTED_HASH, $outcome->expectedHash);
    }

    public function test_execute_exception_still_destroys_and_releases_the_lock(): void
    {
        $user = User::factory()->create();

        $environment = $this->createEnvironmentRow($user);
        $manager = $this->bindManagerMock();
        $manager->shouldReceive('provision')->once()->andReturn($environment);
        $manager->shouldReceive('execute')->once()->andThrow(new RuntimeException('boom'));
        $manager->shouldReceive('destroy')->once()->with($environment);

        try {
            $this->runAction($user, new PracticeTaskInput(expectedHash: self::EXPECTED_HASH));
            $this->fail('Expected RuntimeException to bubble out of the action.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $lock = Cache::lock('practice-env:'.$user->id, 10);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    /**
     * A provision() failure must not leak the environment lock: the
     * exception bubbles out as an infrastructure 500, yet the next
     * attempt of the same user is not stuck Busy until the lock TTL.
     * destroy() is skipped — the manager contract self-cleans before
     * rethrowing, so there is no environment to destroy.
     */
    public function test_provision_exception_still_releases_the_lock(): void
    {
        $user = User::factory()->create();

        $manager = $this->bindManagerMock();
        $manager->shouldReceive('provision')->once()->andThrow(new RuntimeException('boom'));
        $manager->shouldReceive('execute')->never();
        $manager->shouldReceive('destroy')->never();

        try {
            $this->runAction($user, new PracticeTaskInput(expectedHash: self::EXPECTED_HASH));
            $this->fail('Expected RuntimeException to bubble out of the action.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $lock = Cache::lock('practice-env:'.$user->id, 10);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    /**
     * Slot saturation (Stage 10): with max_environments = 1 and the
     * only slot held, the attempt answers Busy without provisioning —
     * and releases the per-user lock it already took, otherwise the
     * user's next attempt would self-deadlock until the lock TTL.
     */
    public function test_saturated_slots_answer_busy_without_provision_and_free_the_user_lock(): void
    {
        config(['practice.concurrency.max_environments' => 1]);

        $user = User::factory()->create();

        $slotGuard = Cache::lock('practice-slot:1', 10);
        $this->assertTrue($slotGuard->get());

        $environment = $this->createEnvironmentRow($user);
        $manager = $this->bindManagerMock();
        $manager->shouldReceive('provision')->once()->andReturn($environment);
        $manager->shouldReceive('execute')->once()->andReturn(new ExecutionResult([['name' => 'Alice']], ['name'], 0.5));
        $manager->shouldReceive('compare')->once()->andReturn(true);
        $manager->shouldReceive('destroy')->once()->with($environment);

        $outcome = $this->runAction($user, new PracticeTaskInput(expectedHash: self::EXPECTED_HASH));

        $this->assertSame(PracticeAttemptStatus::Busy, $outcome->status);
        $this->assertNull($outcome->result);

        $userLock = Cache::lock('practice-env:'.$user->id, 10);
        $this->assertTrue($userLock->get());
        $userLock->release();

        $slotGuard->release();

        $retry = $this->runAction($user, new PracticeTaskInput(expectedHash: self::EXPECTED_HASH));

        $this->assertSame(PracticeAttemptStatus::Passed, $retry->status);
    }

    /**
     * A leaked global slot would starve every later attempt of every
     * user until the slot TTL, so the final path must give it back
     * even when execute() blows up as an infrastructure 500.
     */
    public function test_execute_exception_releases_the_global_slot(): void
    {
        config(['practice.concurrency.max_environments' => 1]);

        $user = User::factory()->create();

        $environment = $this->createEnvironmentRow($user);
        $manager = $this->bindManagerMock();
        $manager->shouldReceive('provision')->once()->andReturn($environment);
        $manager->shouldReceive('execute')->once()->andThrow(new RuntimeException('boom'));
        $manager->shouldReceive('destroy')->once()->with($environment);

        try {
            $this->runAction($user, new PracticeTaskInput(expectedHash: self::EXPECTED_HASH));
            $this->fail('Expected RuntimeException to bubble out of the action.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $slot = Cache::lock('practice-slot:1', 10);
        $this->assertTrue($slot->get());
        $slot->release();
    }

    /**
     * Backwards compatibility of the default configuration (null =
     * no global limit): slots are never consulted, so an occupied
     * practice-slot:{i} key does not serialize two consecutive
     * attempts — the behaviour installations had before Stage 10.
     */
    public function test_default_null_limit_keeps_attempts_globally_unserialized(): void
    {
        config(['practice.concurrency.max_environments' => null]);

        $user = User::factory()->create();

        $slotGuard = Cache::lock('practice-slot:1', 10);
        $this->assertTrue($slotGuard->get());

        $environment = $this->createEnvironmentRow($user);
        $manager = $this->bindManagerMock();
        $manager->shouldReceive('provision')->twice()->andReturn($environment);
        $manager->shouldReceive('execute')->twice()->andReturn(new ExecutionResult([['name' => 'Alice']], ['name'], 0.5));
        $manager->shouldReceive('compare')->twice()->andReturn(true);
        $manager->shouldReceive('destroy')->twice()->with($environment);

        $first = $this->runAction($user, new PracticeTaskInput(expectedHash: self::EXPECTED_HASH));
        $second = $this->runAction($user, new PracticeTaskInput(expectedHash: self::EXPECTED_HASH));

        $this->assertSame(PracticeAttemptStatus::Passed, $first->status);
        $this->assertSame(PracticeAttemptStatus::Passed, $second->status);
    }

    public function test_missing_expected_hash_fails_an_error_free_result(): void
    {
        $user = User::factory()->create();

        $environment = $this->createEnvironmentRow($user);
        $manager = $this->bindManagerMock();
        $manager->shouldReceive('provision')->once()->andReturn($environment);
        $manager->shouldReceive('execute')->once()->andReturn(new ExecutionResult([['name' => 'Alice']], ['name'], 1.25));
        $manager->shouldReceive('compare')->never();
        $manager->shouldReceive('destroy')->once()->with($environment);

        $outcome = $this->runAction($user, new PracticeTaskInput);

        $this->assertSame(PracticeAttemptStatus::Failed, $outcome->status);
        $this->assertNull($outcome->expectedHash);
    }

    /**
     * Stage 8 removed the inline AI feedback from the attempt cycle
     * (design В2-B): the outcome DTO must not carry a feedback field,
     * and the action must not depend on the AI services at all.
     */
    public function test_the_outcome_no_longer_has_a_feedback_property(): void
    {
        $this->assertFalse(
            (new ReflectionClass(PracticeRunOutcome::class))->hasProperty('feedback'),
        );
    }

    /**
     * Persist the environment row the mocked provision() hands back.
     */
    private function createEnvironmentRow(User $user): PracticeEnvironment
    {
        return PracticeEnvironment::factory()->create(['user_id' => $user->id]);
    }

    /**
     * Bind a mocked PracticeEnvironmentManager and return the mock for
     * per-test expectations.
     */
    private function bindManagerMock(): MockInterface
    {
        $manager = Mockery::mock(PracticeEnvironmentManager::class);
        $this->instance(PracticeEnvironmentManager::class, $manager);

        return $manager;
    }

    /**
     * Run the action with the test's constant code payload.
     */
    private function runAction(User $user, PracticeTaskInput $task): PracticeRunOutcome
    {
        return app(RunPracticeTaskAction::class)->execute($user, $task, self::CODE);
    }
}
