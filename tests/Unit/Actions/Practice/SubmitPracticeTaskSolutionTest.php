<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Practice;

use App\Actions\Practice\SubmitPracticeTaskSolution;
use App\Enums\CourseProgressStatus;
use App\Enums\CourseStatus;
use App\Enums\LessonProgressStatus;
use App\Enums\PracticeAttemptStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\PracticeEnvironment;
use App\Models\PracticeTask;
use App\Models\PracticeTaskSubmission;
use App\Models\TheoryTask;
use App\Models\User;
use App\Services\Practice\Dto\ExecutionResult;
use App\Services\Practice\Dto\PracticeTaskInput;
use App\Services\Practice\Dto\SubmitSolutionOutcome;
use App\Services\Practice\PracticeEnvironmentManager;
use App\Services\Progress\LessonCompletionChecker;

use function assert;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class SubmitPracticeTaskSolutionTest extends TestCase
{
    private const CODE = 'SELECT id, title, year FROM books ORDER BY year';

    private User $user;

    private Course $course;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed clock: `started_at`/`completed_at` assertions (including
        // the no-downgrade case an hour later) stay deterministic.
        $this->travelTo(Carbon::parse('2026-09-16 12:00:00'));

        $this->user = User::factory()->create();
        $this->course = Course::factory()->published()->create();
        $level = Level::factory()
            ->for($this->course)
            ->create(['order' => 1]);
        $this->lesson = Lesson::factory()->for($level)->create(['order' => 1]);
    }

    public function test_passed_attempt_is_stored_and_completes_a_practice_only_lesson(): void
    {
        $task = $this->createTask();
        $this->stubPassedAttempt(12.34);

        $result = $this->submit($task);

        $this->assertSame(PracticeAttemptStatus::Passed, $result->outcome->status);
        $this->assertInstanceOf(PracticeTaskSubmission::class, $result->submission);

        $this->assertDatabaseHas('practice_task_submissions', [
            'user_id' => $this->user->id,
            'practice_task_id' => $task->id,
            'code' => self::CODE,
            'status' => 'passed',
            'duration_ms' => 12,
            'error_text' => null,
            'result_diff' => null,
        ]);

        // No theory in the lesson: a Passed practice attempt completes it.
        $this->assertDatabaseHas('user_lesson_progress', [
            'user_id' => $this->user->id,
            'lesson_id' => $this->lesson->id,
            'status' => LessonProgressStatus::Completed->value,
            'started_at' => '2026-09-16 12:00:00',
            'completed_at' => '2026-09-16 12:00:00',
        ]);

        $this->assertDatabaseHas('user_course_progress', [
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'status' => CourseProgressStatus::InProgress->value,
            'current_lesson_id' => $this->lesson->id,
        ]);
    }

    public function test_passed_attempt_keeps_the_lesson_in_progress_until_theory_is_answered(): void
    {
        $task = $this->createTask();
        TheoryTask::factory()->for($this->lesson)->withOptions()->create(['order' => 1]);
        $this->stubPassedAttempt();

        $this->submit($task);

        $this->assertDatabaseHas('user_lesson_progress', [
            'user_id' => $this->user->id,
            'lesson_id' => $this->lesson->id,
            'status' => LessonProgressStatus::InProgress->value,
            'completed_at' => null,
        ]);
    }

    public function test_failed_attempt_stores_the_diff_and_does_not_complete_the_lesson(): void
    {
        $task = $this->createTask();
        $rows = [['id' => 1, 'title' => 'SQL Basics', 'year' => 2020]];
        $this->stubFailedAttempt($rows, 7.51);

        $result = $this->submit($task);

        $submission = $result->submission;
        assert($submission instanceof PracticeTaskSubmission);

        $this->assertNull($submission->error_text);
        $this->assertSame(
            ['expected' => $task->expected_rows, 'actual' => $rows],
            $submission->result_diff,
        );

        $this->assertDatabaseHas('practice_task_submissions', [
            'user_id' => $this->user->id,
            'practice_task_id' => $task->id,
            'status' => 'failed',
            'duration_ms' => 8,
        ]);

        $this->assertDatabaseHas('user_lesson_progress', [
            'user_id' => $this->user->id,
            'lesson_id' => $this->lesson->id,
            'status' => LessonProgressStatus::InProgress->value,
        ]);
    }

    public function test_error_attempt_stores_the_error_text_without_a_diff(): void
    {
        $task = $this->createTask();
        $this->stubErrorAttempt('Превышен таймаут исполнения запроса', 3.2);

        $result = $this->submit($task);

        $submission = $result->submission;
        assert($submission instanceof PracticeTaskSubmission);

        $this->assertSame('Превышен таймаут исполнения запроса', $submission->error_text);
        $this->assertNull($submission->result_diff);

        $this->assertDatabaseHas('user_lesson_progress', [
            'user_id' => $this->user->id,
            'lesson_id' => $this->lesson->id,
            'status' => LessonProgressStatus::InProgress->value,
        ]);
    }

    public function test_busy_outcome_writes_nothing_and_returns_no_submission(): void
    {
        $task = $this->createTask();

        // Hold the environment lock: the real runner resolves Busy without
        // provisioning anything.
        $this->assertTrue(Cache::lock('practice-env:'.$this->user->id, 10)->get());
        $this->bindManagerMock()->shouldReceive('provision')->never();

        $result = $this->submit($task);

        $this->assertSame(PracticeAttemptStatus::Busy, $result->outcome->status);
        $this->assertNull($result->submission);
        $this->assertNothingWritten();
    }

    public function test_unpublished_task_throws_and_writes_nothing(): void
    {
        $task = $this->createTask();
        $task->update(['is_published' => false]);

        $this->assertGuardBlocked($task);
    }

    public function test_unpublished_lesson_throws_and_writes_nothing(): void
    {
        $task = $this->createTask();
        $this->lesson->update(['is_published' => false]);

        $this->assertGuardBlocked($task);
    }

    public function test_draft_course_throws_and_writes_nothing(): void
    {
        $task = $this->createTask();
        $this->course->update(['status' => CourseStatus::Draft]);

        $this->assertGuardBlocked($task);
    }

    public function test_completed_lesson_is_not_downgraded_by_later_attempts(): void
    {
        $task = $this->createTask();

        $this->stubPassedAttempt();
        $this->submit($task);

        $this->travelTo(now()->addHour());

        // The user retries the solved task and fails: the Passed history
        // stays, so the lesson keeps its original completion timestamps.
        $this->stubFailedAttempt([['id' => 2]]);
        $this->submit($task);

        $this->assertDatabaseHas('user_lesson_progress', [
            'user_id' => $this->user->id,
            'lesson_id' => $this->lesson->id,
            'status' => LessonProgressStatus::Completed->value,
            'started_at' => '2026-09-16 12:00:00',
            'completed_at' => '2026-09-16 12:00:00',
        ]);

        $this->assertSame(2, PracticeTaskSubmission::query()->count());
    }

    public function test_started_at_is_set_once_and_kept_on_subsequent_attempts(): void
    {
        $task = $this->createTask();
        TheoryTask::factory()->for($this->lesson)->withOptions()->create(['order' => 1]);

        $this->stubFailedAttempt([['id' => 1]]);
        $this->submit($task);

        $this->travelTo(now()->addHour());

        $this->stubFailedAttempt([['id' => 2]]);
        $this->submit($task);

        $this->assertDatabaseHas('user_lesson_progress', [
            'user_id' => $this->user->id,
            'lesson_id' => $this->lesson->id,
            'status' => LessonProgressStatus::InProgress->value,
            'started_at' => '2026-09-16 12:00:00',
            'completed_at' => null,
        ]);
    }

    public function test_the_task_input_is_assembled_from_the_model(): void
    {
        $task = PracticeTask::factory()->for($this->lesson)->withSeedScript()->create();

        $captured = null;
        $environment = PracticeEnvironment::factory()->create(['user_id' => $this->user->id]);
        $manager = $this->bindManagerMock();
        $manager->shouldReceive('provision')->once()->andReturnUsing(
            function (User $user, PracticeTaskInput $input) use (&$captured, $environment): PracticeEnvironment {
                $captured = $input;

                return $environment;
            },
        );
        $manager->shouldReceive('execute')->once()->andReturn(new ExecutionResult([['id' => 1]], ['id'], 5.0));
        $manager->shouldReceive('compare')->once()->andReturn(true);
        $manager->shouldReceive('destroy')->once()->with($environment);

        $this->submit($task);

        assert($captured instanceof PracticeTaskInput);
        $this->assertSame($task->id, $captured->taskId);
        $this->assertSame($task->statement, $captured->taskText);
        $this->assertSame($task->seed_sql, $captured->seedScript);
        $this->assertSame($task->expected_hash, $captured->expectedHash);
    }

    public function test_failure_inside_the_transaction_writes_nothing(): void
    {
        $task = $this->createTask();
        $this->stubPassedAttempt();

        $checker = Mockery::mock(LessonCompletionChecker::class);
        $checker->shouldReceive('__invoke')->andThrow(new RuntimeException('Completion check failed.'));
        $this->instance(LessonCompletionChecker::class, $checker);

        try {
            $this->submit($task);
            $this->fail('Expected the completion-check failure to bubble up.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Completion check failed.', $exception->getMessage());
        }

        $this->assertNothingWritten();
    }

    /**
     * Create a published practice task in the test's lesson.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createTask(array $attributes = []): PracticeTask
    {
        return PracticeTask::factory()->for($this->lesson)->create($attributes);
    }

    /**
     * Bind a mocked PracticeEnvironmentManager and return the mock for
     * per-test expectations (the RunPracticeTaskActionTest pattern).
     */
    private function bindManagerMock(): MockInterface
    {
        $manager = Mockery::mock(PracticeEnvironmentManager::class);
        $this->instance(PracticeEnvironmentManager::class, $manager);

        return $manager;
    }

    /**
     * Stub the manager so the real RunPracticeTaskAction resolves a
     * Passed attempt with the given duration.
     */
    private function stubPassedAttempt(float $durationMs = 10.0): void
    {
        $environment = PracticeEnvironment::factory()->create(['user_id' => $this->user->id]);
        $manager = $this->bindManagerMock();
        $manager->shouldReceive('provision')->once()->andReturn($environment);
        $manager->shouldReceive('execute')->once()->andReturn(new ExecutionResult([['id' => 1]], ['id'], $durationMs));
        $manager->shouldReceive('compare')->once()->andReturn(true);
        $manager->shouldReceive('destroy')->once()->with($environment);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function stubFailedAttempt(array $rows, float $durationMs = 10.0): void
    {
        $environment = PracticeEnvironment::factory()->create(['user_id' => $this->user->id]);
        $manager = $this->bindManagerMock();
        $manager->shouldReceive('provision')->once()->andReturn($environment);
        $manager->shouldReceive('execute')->once()->andReturn(new ExecutionResult($rows, array_keys($rows[0]), $durationMs));
        $manager->shouldReceive('compare')->once()->andReturn(false);
        $manager->shouldReceive('destroy')->once()->with($environment);
    }

    private function stubErrorAttempt(string $error, float $durationMs = 3.0): void
    {
        $environment = PracticeEnvironment::factory()->create(['user_id' => $this->user->id]);
        $manager = $this->bindManagerMock();
        $manager->shouldReceive('provision')->once()->andReturn($environment);
        $manager->shouldReceive('execute')->once()->andReturn(new ExecutionResult(null, null, $durationMs, $error));
        $manager->shouldReceive('compare')->never();
        $manager->shouldReceive('destroy')->once()->with($environment);
    }

    private function submit(PracticeTask $task): SubmitSolutionOutcome
    {
        return app(SubmitPracticeTaskSolution::class)->execute($this->user, $task, self::CODE);
    }

    /**
     * Publication guards must fail before the run, with no rows written.
     */
    private function assertGuardBlocked(PracticeTask $task): void
    {
        $this->bindManagerMock()->shouldReceive('provision')->never();

        try {
            $this->submit($task);
            $this->fail('Expected NotFoundHttpException for unavailable content.');
        } catch (NotFoundHttpException) {
            // Expected: 404 semantics for hidden content.
        }

        $this->assertNothingWritten();
    }

    /**
     * Guards must fail before any write, and a mid-transaction failure
     * must roll back everything already written.
     */
    private function assertNothingWritten(): void
    {
        $this->assertDatabaseCount('practice_task_submissions', 0);
        $this->assertDatabaseCount('user_lesson_progress', 0);
        $this->assertDatabaseCount('user_course_progress', 0);
    }
}
