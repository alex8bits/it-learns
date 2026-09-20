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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LessonCompletionCheckerTest extends TestCase
{
    public function test_lesson_without_any_tasks_is_vacuously_completed(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();

        // Both required windows are empty ("empty = done"); no user
        // action triggers the check for such a lesson, so the behavior
        // is formal.
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

    /**
     * The default theory window is the first three published tasks by
     * order; the tasks beyond it (orders 4-5) are optional.
     *
     * @param  array<int, int>  $correctOrders
     * @param  array<int, int>  $wrongOrders
     */
    #[DataProvider('theoryWindowProvider')]
    public function test_theory_window_covers_only_the_first_three_published_tasks_by_order(
        array $correctOrders,
        array $wrongOrders,
        bool $expected,
    ): void {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $tasks = $this->createTheoryTasks($lesson, [1, 2, 3, 4, 5]);

        foreach ($correctOrders as $order) {
            UserTheoryTaskAnswer::factory()->for($user)->for($tasks[$order])->create();
        }

        foreach ($wrongOrders as $order) {
            $this->answerWrong($user, $tasks[$order]);
        }

        $this->assertSame($expected, (new LessonCompletionChecker)($user, $lesson));
    }

    public function test_required_task_reanswered_wrong_breaks_the_completed_minimum(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $tasks = $this->createTheoryTasks($lesson, [1, 2, 3]);

        foreach ($tasks as $task) {
            UserTheoryTaskAnswer::factory()->for($user)->for($task)->create();
        }

        $this->assertTrue((new LessonCompletionChecker)($user, $lesson));

        // A re-answer overwrites the latest state, so a wrong answer on
        // a task inside the required window breaks the minimum again —
        // the Completed -> InProgress downgrade path of AnswerTheoryTask.
        UserTheoryTaskAnswer::query()
            ->where('user_id', $user->id)
            ->where('theory_task_id', $tasks[1]->id)
            ->update(['is_correct' => false]);

        $this->assertFalse((new LessonCompletionChecker)($user, $lesson));
    }

    public function test_theory_threshold_above_task_count_requires_all_tasks(): void
    {
        config(['progress.theory_required_per_lesson' => 5]);

        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $first = TheoryTask::factory()->for($lesson)->withOptions()->create(['order' => 1]);
        $second = TheoryTask::factory()->for($lesson)->withOptions()->create(['order' => 2]);

        // min(5, 2) = 2: with fewer tasks than the threshold, all of
        // them are required.
        UserTheoryTaskAnswer::factory()->for($user)->for($first)->create();

        $this->assertFalse((new LessonCompletionChecker)($user, $lesson));

        UserTheoryTaskAnswer::factory()->for($user)->for($second)->create();

        $this->assertTrue((new LessonCompletionChecker)($user, $lesson));
    }

    public function test_unpublished_tasks_are_excluded_from_the_required_window_before_it_is_taken(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        TheoryTask::factory()->for($lesson)->unpublished()->withOptions()->create(['order' => 1]);
        $tasks = $this->createTheoryTasks($lesson, [2, 3, 4]);

        foreach ($tasks as $task) {
            UserTheoryTaskAnswer::factory()->for($user)->for($task)->create();
        }

        // The window is the first three published tasks (orders 2-4),
        // not "orders 1-3": the unpublished order 1 never enters it.
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

    public function test_first_required_practice_passed_completes_while_optional_one_is_not(): void
    {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $required = PracticeTask::factory()->for($lesson)->create(['order' => 1]);
        PracticeTask::factory()->for($lesson)->create(['order' => 2]);

        PracticeTaskSubmission::factory()->for($user)->for($required)->passed()->create();

        // Only the first practice task — the required window,
        // min(1, 2) = 1 — needs a Passed submission; the second one is
        // optional and never blocks completion.
        $this->assertTrue((new LessonCompletionChecker)($user, $lesson));
    }

    /**
     * The default practice window is the first published task by
     * order; the tasks beyond it are optional.
     *
     * @param  array<int, int>  $passedOrders
     */
    #[DataProvider('practiceWindowProvider')]
    public function test_practice_window_covers_only_the_first_required_task_by_order(
        array $passedOrders,
        bool $expected,
    ): void {
        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $tasks = $this->createPracticeTasks($lesson, [1, 2, 3]);

        foreach ($passedOrders as $order) {
            PracticeTaskSubmission::factory()->for($user)->for($tasks[$order])->passed()->create();
        }

        $this->assertSame($expected, (new LessonCompletionChecker)($user, $lesson));
    }

    public function test_practice_threshold_is_read_from_config(): void
    {
        config(['progress.practice_required_per_lesson' => 2]);

        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $tasks = $this->createPracticeTasks($lesson, [1, 2, 3]);

        PracticeTaskSubmission::factory()->for($user)->for($tasks[1])->passed()->create();

        // The window is the first two tasks now: one Passed is not enough.
        $this->assertFalse((new LessonCompletionChecker)($user, $lesson));

        PracticeTaskSubmission::factory()->for($user)->for($tasks[2])->passed()->create();

        $this->assertTrue((new LessonCompletionChecker)($user, $lesson));
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

    /**
     * A threshold of 0 or less empties the required window, making the
     * part vacuously done (a degenerate env value, not a supported
     * mode); the other part must still satisfy its default window.
     */
    #[DataProvider('degenerateThresholdProvider')]
    public function test_degenerate_threshold_makes_the_part_vacuously_done(string $thresholdKey, int $threshold): void
    {
        config([$thresholdKey => $threshold]);

        $user = User::factory()->create();
        $lesson = Lesson::factory()->create();
        $theoryTasks = $this->createTheoryTasks($lesson, [1, 2, 3]);
        $practiceTasks = $this->createPracticeTasks($lesson, [1, 2]);

        if ($thresholdKey === 'progress.theory_required_per_lesson') {
            // The theory window is empty: unanswered theory is vacuously
            // done, and the default practice window (first task) is passed.
            PracticeTaskSubmission::factory()->for($user)->for($practiceTasks[1])->passed()->create();
        } else {
            // The practice window is empty: unsolved practice is vacuously
            // done, and the default theory window (first three tasks) is
            // answered.
            foreach ($theoryTasks as $task) {
                UserTheoryTaskAnswer::factory()->for($user)->for($task)->create();
            }
        }

        $this->assertTrue((new LessonCompletionChecker)($user, $lesson));
    }

    /**
     * @return array<string, array{0: array<int, int>, 1: array<int, int>, 2: bool}>
     */
    public static function theoryWindowProvider(): array
    {
        return [
            'first three answered correctly completes' => [[1, 2, 3], [], true],
            'only two of the window answered does not complete' => [[1, 2], [], false],
            'wrong latest answer inside the window does not complete' => [[1, 2], [3], false],
            'gap inside the window does not complete' => [[1, 3], [], false],
            'wrong answer on an optional task does not block completion' => [[1, 2, 3], [4], true],
        ];
    }

    /**
     * @return array<string, array{0: array<int, int>, 1: bool}>
     */
    public static function practiceWindowProvider(): array
    {
        return [
            'first task passed completes' => [[1], true],
            'optional task passed instead of the first does not complete' => [[2], false],
            'last task passed does not complete' => [[3], false],
            'optional passes without the first do not complete' => [[2, 3], false],
            'first task plus optional passes completes' => [[1, 3], true],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function degenerateThresholdProvider(): array
    {
        return [
            'theory threshold of zero' => ['progress.theory_required_per_lesson', 0],
            'negative theory threshold' => ['progress.theory_required_per_lesson', -2],
            'practice threshold of zero' => ['progress.practice_required_per_lesson', 0],
            'negative practice threshold' => ['progress.practice_required_per_lesson', -2],
        ];
    }

    /**
     * Create published theory tasks with options for the given orders.
     *
     * @param  array<int, int>  $orders
     * @return array<int, TheoryTask> the tasks keyed by their order
     */
    private function createTheoryTasks(Lesson $lesson, array $orders): array
    {
        $tasks = [];

        foreach ($orders as $order) {
            $tasks[$order] = TheoryTask::factory()->for($lesson)->withOptions()->create(['order' => $order]);
        }

        return $tasks;
    }

    /**
     * Create published practice tasks for the given orders.
     *
     * @param  array<int, int>  $orders
     * @return array<int, PracticeTask> the tasks keyed by their order
     */
    private function createPracticeTasks(Lesson $lesson, array $orders): array
    {
        $tasks = [];

        foreach ($orders as $order) {
            $tasks[$order] = PracticeTask::factory()->for($lesson)->create(['order' => $order]);
        }

        return $tasks;
    }

    /**
     * Record a wrong answer for the task — mirrors the latest-state
     * upsert of AnswerTheoryTask with an incorrect option.
     */
    private function answerWrong(User $user, TheoryTask $task): void
    {
        $wrongOption = $task->options->firstWhere('is_correct', false);

        UserTheoryTaskAnswer::factory()->for($user)->for($task)->create([
            'option_id' => $wrongOption?->id,
            'is_correct' => false,
        ]);
    }
}
