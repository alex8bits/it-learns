<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Progress;

use App\Actions\Progress\AnswerTheoryTask;
use App\Enums\CourseProgressStatus;
use App\Enums\CourseStatus;
use App\Enums\LessonProgressStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\TheoryTask;
use App\Models\TheoryTaskOption;
use App\Models\User;
use App\Services\Progress\Dto\AnswerOutcome;
use App\Services\Progress\LessonCompletionChecker;

use function assert;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class AnswerTheoryTaskTest extends TestCase
{
    private User $user;

    private Course $course;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed clock: `started_at`/`completed_at` assertions (including the
        // re-answer case moving an hour forward) stay deterministic.
        $this->travelTo(Carbon::parse('2026-09-16 12:00:00'));

        $this->user = User::factory()->create();
        $this->course = Course::factory()->published()->create();
        $level = Level::factory()
            ->for($this->course)
            ->create(['order' => 1]);
        $this->lesson = Lesson::factory()->for($level)->create(['order' => 1]);
    }

    public function test_correct_answer_is_stored_and_lesson_stays_in_progress(): void
    {
        // Two tasks: answering the first one correctly must not yet
        // complete the lesson.
        $task = $this->createTask(['order' => 1]);
        $this->createTask(['order' => 2]);
        $correctOption = $this->correctOption($task);

        $outcome = $this->answer($task, $correctOption);

        $this->assertTrue($outcome->isCorrect);
        $this->assertNull($outcome->errorText);

        $this->assertDatabaseHas('user_theory_task_answers', [
            'user_id' => $this->user->id,
            'theory_task_id' => $task->id,
            'option_id' => $correctOption->id,
            'is_correct' => true,
        ]);

        $this->assertDatabaseHas('user_lesson_progress', [
            'user_id' => $this->user->id,
            'lesson_id' => $this->lesson->id,
            'status' => LessonProgressStatus::InProgress->value,
            'started_at' => '2026-09-16 12:00:00',
        ]);

        $this->assertDatabaseHas('user_course_progress', [
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'status' => CourseProgressStatus::InProgress->value,
            'current_lesson_id' => $this->lesson->id,
        ]);
    }

    public function test_wrong_answer_returns_the_option_error_text(): void
    {
        $task = $this->createTask();
        $wrongOption = $this->wrongOption($task);

        $outcome = $this->answer($task, $wrongOption);

        $this->assertFalse($outcome->isCorrect);
        $this->assertSame($wrongOption->error_text, $outcome->errorText);

        $this->assertDatabaseHas('user_theory_task_answers', [
            'user_id' => $this->user->id,
            'theory_task_id' => $task->id,
            'option_id' => $wrongOption->id,
            'is_correct' => false,
        ]);

        $this->assertDatabaseHas('user_lesson_progress', [
            'user_id' => $this->user->id,
            'lesson_id' => $this->lesson->id,
            'status' => LessonProgressStatus::InProgress->value,
        ]);
    }

    public function test_last_correct_answer_completes_the_lesson_and_points_the_course_at_it(): void
    {
        $first = $this->createTask(['order' => 1]);
        $second = $this->createTask(['order' => 2]);

        $this->answer($first, $this->correctOption($first));

        // Only the first task is answered: the lesson is not completed yet.
        $this->assertDatabaseHas('user_lesson_progress', [
            'user_id' => $this->user->id,
            'lesson_id' => $this->lesson->id,
            'status' => LessonProgressStatus::InProgress->value,
        ]);

        $outcome = $this->answer($second, $this->correctOption($second));

        $this->assertTrue($outcome->isCorrect);

        $this->assertDatabaseHas('user_lesson_progress', [
            'user_id' => $this->user->id,
            'lesson_id' => $this->lesson->id,
            'status' => LessonProgressStatus::Completed->value,
            'completed_at' => '2026-09-16 12:00:00',
        ]);

        $this->assertDatabaseHas('user_course_progress', [
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'status' => CourseProgressStatus::InProgress->value,
            'current_lesson_id' => $this->lesson->id,
        ]);
    }

    public function test_reanswer_overwrites_the_previous_answer_and_keeps_started_at(): void
    {
        $task = $this->createTask();
        $correctOption = $this->correctOption($task);

        $this->travelTo(now()->subHour());
        $this->answer($task, $this->wrongOption($task));

        $this->travelTo(now()->addHour());
        $outcome = $this->answer($task, $correctOption);

        $this->assertTrue($outcome->isCorrect);
        $this->assertDatabaseCount('user_theory_task_answers', 1);
        $this->assertDatabaseHas('user_theory_task_answers', [
            'user_id' => $this->user->id,
            'theory_task_id' => $task->id,
            'option_id' => $correctOption->id,
            'is_correct' => true,
        ]);

        // The lesson completes once the answer becomes correct, and the
        // re-answer does not reset `started_at` (an hour earlier).
        $this->assertDatabaseHas('user_lesson_progress', [
            'user_id' => $this->user->id,
            'lesson_id' => $this->lesson->id,
            'status' => LessonProgressStatus::Completed->value,
            'started_at' => '2026-09-16 11:00:00',
            'completed_at' => '2026-09-16 12:00:00',
        ]);
    }

    public function test_option_of_another_task_is_rejected_and_writes_nothing(): void
    {
        $task = $this->createTask(['order' => 1]);
        $otherTask = $this->createTask(['order' => 2]);

        try {
            $this->answer($task, $this->correctOption($otherTask));
            $this->fail('Expected ValidationException for a foreign option.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['option_id' => ['Этот вариант не относится к текущему вопросу']],
                $exception->errors(),
            );
        }

        $this->assertNothingWritten();
    }

    public function test_unpublished_task_throws_and_writes_nothing(): void
    {
        $task = $this->createTask();
        $task->update(['is_published' => false]);

        try {
            $this->answer($task, $this->correctOption($task));
            $this->fail('Expected NotFoundHttpException for an unpublished task.');
        } catch (NotFoundHttpException) {
            // Expected: 404 semantics for hidden content.
        }

        $this->assertNothingWritten();
    }

    public function test_unpublished_lesson_throws_and_writes_nothing(): void
    {
        $task = $this->createTask();
        $this->lesson->update(['is_published' => false]);

        try {
            $this->answer($task, $this->correctOption($task));
            $this->fail('Expected NotFoundHttpException for an unpublished lesson.');
        } catch (NotFoundHttpException) {
            // Expected: 404 semantics for hidden content.
        }

        $this->assertNothingWritten();
    }

    public function test_draft_course_throws_and_writes_nothing(): void
    {
        $task = $this->createTask();
        $this->course->update(['status' => CourseStatus::Draft]);

        try {
            $this->answer($task, $this->correctOption($task));
            $this->fail('Expected NotFoundHttpException for a draft course.');
        } catch (NotFoundHttpException) {
            // Expected: 404 semantics for hidden content.
        }

        $this->assertNothingWritten();
    }

    public function test_failure_inside_the_transaction_writes_nothing(): void
    {
        $task = $this->createTask();

        $checker = Mockery::mock(LessonCompletionChecker::class);
        $checker->shouldReceive('__invoke')->andThrow(new RuntimeException('Completion check failed.'));
        $this->instance(LessonCompletionChecker::class, $checker);

        try {
            $this->answer($task, $this->correctOption($task));
            $this->fail('Expected the completion-check failure to bubble up.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Completion check failed.', $exception->getMessage());
        }

        $this->assertNothingWritten();
    }

    /**
     * Create a published task with the standard option set in the test's
     * lesson.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createTask(array $attributes = []): TheoryTask
    {
        return TheoryTask::factory()
            ->for($this->lesson)
            ->withOptions()
            ->create($attributes);
    }

    private function answer(TheoryTask $task, TheoryTaskOption $option): AnswerOutcome
    {
        return app(AnswerTheoryTask::class)->execute($this->user, $task, $option);
    }

    private function correctOption(TheoryTask $task): TheoryTaskOption
    {
        $option = $task->options->firstWhere('is_correct', true);
        assert($option instanceof TheoryTaskOption);

        return $option;
    }

    private function wrongOption(TheoryTask $task): TheoryTaskOption
    {
        $option = $task->options->firstWhere('is_correct', false);
        assert($option instanceof TheoryTaskOption);

        return $option;
    }

    /**
     * Guards must fail before any write, and a mid-transaction failure
     * must roll back everything already written.
     */
    private function assertNothingWritten(): void
    {
        $this->assertDatabaseCount('user_theory_task_answers', 0);
        $this->assertDatabaseCount('user_lesson_progress', 0);
        $this->assertDatabaseCount('user_course_progress', 0);
    }
}
