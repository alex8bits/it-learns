<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Progress;

use App\Models\Lesson;
use App\Models\PracticeTask;
use App\Models\PracticeTaskSubmission;
use App\Models\TheoryTask;
use App\Models\User;
use App\Models\UserTheoryTaskAnswer;
use App\Services\Progress\LessonCompletionChecker;
use Tests\TestCase;

class LessonCompletionCheckerTest extends TestCase
{
    public function test_lesson_without_any_tasks_is_vacuously_completed(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();

        // Both published sets are empty ("empty = done"); no user action
        // triggers the check for such a lesson, so the behavior is formal.
        $this->assertTrue((new LessonCompletionChecker)($user, $lesson));
    }

    public function test_lesson_with_only_unpublished_theory_is_vacuously_completed(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $unpublished = TheoryTask::factory()->for($lesson)->unpublished()->withOptions()->create();

        // Even a correct answer on the unpublished task must not count —
        // and neither does the unpublished task itself.
        UserTheoryTaskAnswer::factory()->for($user)->for($unpublished)->create();

        $this->assertTrue((new LessonCompletionChecker)($user, $lesson));
    }

    public function test_partially_answered_theory_is_not_completed(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $answered = TheoryTask::factory()->for($lesson)->withOptions()->create(['order' => 1]);
        TheoryTask::factory()->for($lesson)->withOptions()->create(['order' => 2]);

        UserTheoryTaskAnswer::factory()->for($user)->for($answered)->create();

        $this->assertFalse((new LessonCompletionChecker)($user, $lesson));
    }

    public function test_wrong_theory_answer_does_not_complete_the_lesson(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $task = TheoryTask::factory()->for($lesson)->withOptions()->create();
        $wrongOption = $task->options->firstWhere('is_correct', false);

        UserTheoryTaskAnswer::factory()->for($user)->for($task)->create([
            'option_id' => $wrongOption?->id,
            'is_correct' => false,
        ]);

        $this->assertFalse((new LessonCompletionChecker)($user, $lesson));
    }

    public function test_all_published_theory_answered_correctly_completes_a_theory_only_lesson(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $first = TheoryTask::factory()->for($lesson)->withOptions()->create(['order' => 1]);
        $second = TheoryTask::factory()->for($lesson)->withOptions()->create(['order' => 2]);

        UserTheoryTaskAnswer::factory()->for($user)->for($first)->create();
        UserTheoryTaskAnswer::factory()->for($user)->for($second)->create();

        $this->assertTrue((new LessonCompletionChecker)($user, $lesson));
    }

    public function test_unanswered_unpublished_theory_does_not_block_completion(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        TheoryTask::factory()->for($lesson)->unpublished()->withOptions()->create(['order' => 1]);
        $published = TheoryTask::factory()->for($lesson)->withOptions()->create(['order' => 2]);

        UserTheoryTaskAnswer::factory()->for($user)->for($published)->create();

        $this->assertTrue((new LessonCompletionChecker)($user, $lesson));
    }

    public function test_another_users_theory_answers_do_not_count(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $task = TheoryTask::factory()->for($lesson)->withOptions()->create();

        UserTheoryTaskAnswer::factory()->for($other)->for($task)->create();

        $this->assertFalse((new LessonCompletionChecker)($user, $lesson));
    }

    public function test_practice_only_lesson_without_submissions_is_not_completed(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        PracticeTask::factory()->for($lesson)->create();

        $this->assertFalse((new LessonCompletionChecker)($user, $lesson));
    }

    public function test_passed_practice_completes_a_practice_only_lesson(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $task = PracticeTask::factory()->for($lesson)->create();

        PracticeTaskSubmission::factory()->for($user)->for($task)->passed()->create();

        $this->assertTrue((new LessonCompletionChecker)($user, $lesson));
    }

    public function test_failed_practice_submission_does_not_complete_the_lesson(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $task = PracticeTask::factory()->for($lesson)->create();

        PracticeTaskSubmission::factory()->for($user)->for($task)->failed()->create();

        $this->assertFalse((new LessonCompletionChecker)($user, $lesson));
    }

    public function test_partially_passed_practice_is_not_completed(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $solved = PracticeTask::factory()->for($lesson)->create(['order' => 1]);
        PracticeTask::factory()->for($lesson)->create(['order' => 2]);

        PracticeTaskSubmission::factory()->for($user)->for($solved)->passed()->create();

        $this->assertFalse((new LessonCompletionChecker)($user, $lesson));
    }

    public function test_a_failed_attempt_followed_by_a_passed_one_counts_once(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $task = PracticeTask::factory()->for($lesson)->create();

        PracticeTaskSubmission::factory()->for($user)->for($task)->failed()->create();
        PracticeTaskSubmission::factory()->for($user)->for($task)->passed()->create();

        $this->assertTrue((new LessonCompletionChecker)($user, $lesson));
    }

    public function test_another_users_practice_submissions_do_not_count(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $task = PracticeTask::factory()->for($lesson)->create();

        PracticeTaskSubmission::factory()->for($other)->for($task)->passed()->create();

        $this->assertFalse((new LessonCompletionChecker)($user, $lesson));
    }

    public function test_passed_submission_on_unpublished_practice_neither_counts_nor_blocks(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $unpublished = PracticeTask::factory()->for($lesson)->unpublished()->create();

        PracticeTaskSubmission::factory()->for($user)->for($unpublished)->passed()->create();

        // The published practice set is empty ("empty = done").
        $this->assertTrue((new LessonCompletionChecker)($user, $lesson));
    }

    public function test_unanswered_unpublished_practice_does_not_block_completion(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        PracticeTask::factory()->for($lesson)->unpublished()->create(['order' => 1]);
        $published = PracticeTask::factory()->for($lesson)->create(['order' => 2]);

        PracticeTaskSubmission::factory()->for($user)->for($published)->passed()->create();

        $this->assertTrue((new LessonCompletionChecker)($user, $lesson));
    }

    public function test_theory_and_practice_together_must_both_be_done(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $theory = TheoryTask::factory()->for($lesson)->withOptions()->create(['order' => 1]);
        $practice = PracticeTask::factory()->for($lesson)->create(['order' => 2]);

        // Practice passed, theory not answered.
        PracticeTaskSubmission::factory()->for($user)->for($practice)->passed()->create();
        $this->assertFalse((new LessonCompletionChecker)($user, $lesson));

        // Theory answered, practice unsolved.
        UserTheoryTaskAnswer::factory()->for($user)->for($theory)->create();
        PracticeTaskSubmission::query()->delete();
        $this->assertFalse((new LessonCompletionChecker)($user, $lesson));

        // Both done.
        PracticeTaskSubmission::factory()->for($user)->for($practice)->passed()->create();
        $this->assertTrue((new LessonCompletionChecker)($user, $lesson));
    }
}
