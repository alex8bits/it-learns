<?php

declare(strict_types=1);

namespace App\Actions\Practice;

use App\Enums\CourseProgressStatus;
use App\Enums\CourseStatus;
use App\Enums\LessonProgressStatus;
use App\Enums\PracticeAttemptStatus;
use App\Models\Lesson;
use App\Models\PracticeTask;
use App\Models\PracticeTaskSubmission;
use App\Models\User;
use App\Models\UserCourseProgress;
use App\Models\UserLessonProgress;
use App\Services\Practice\Dto\SubmitSolutionOutcome;
use App\Services\Progress\LessonCompletionChecker;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * User-facing submit flow of a practice task (Stage 8): publication
 * guards -> the environment cycle (RunPracticeTaskAction, unchanged
 * contract) -> one transaction persisting the attempt and advancing
 * the progress rows. The flash payload gets both the run outcome and
 * the stored submission (diff/error), so no extra read is needed.
 *
 * Unlike theory answers, attempts are an append-only history: every
 * non-Busy attempt inserts a new practice_task_submissions row. A
 * Completed lesson is never downgraded by a failed attempt — Passed
 * history cannot be un-passed, and the current completion may rely on
 * state outside this attempt (design, "не понижать Completed").
 */
final class SubmitPracticeTaskSolution
{
    public function __construct(
        private RunPracticeTaskAction $runPracticeTask,
        private LessonCompletionChecker $completionChecker,
    ) {}

    /**
     * Run the attempt and persist it together with the progress update.
     *
     * @throws NotFoundHttpException when the task, its lesson or the parent course is not published
     */
    public function execute(User $user, PracticeTask $task, string $code): SubmitSolutionOutcome
    {
        $task->loadMissing('lesson.level.course');

        $lesson = $task->lesson;
        $course = $lesson->level->course;

        if (! $task->is_published || ! $lesson->is_published || $course->status !== CourseStatus::Published) {
            throw new NotFoundHttpException('Practice task is not available.');
        }

        $outcome = $this->runPracticeTask->execute($user, $task->toInput(), $code);

        if ($outcome->status === PracticeAttemptStatus::Busy) {
            // A lost lock race means no attempt happened: nothing to store.
            return new SubmitSolutionOutcome($outcome, null);
        }

        $result = $outcome->result;
        $submission = null;

        DB::transaction(function () use ($user, $task, $lesson, $course, $outcome, $result, $code, &$submission): void {
            $submission = PracticeTaskSubmission::query()->create([
                'user_id' => $user->id,
                'practice_task_id' => $task->id,
                'code' => $code,
                'status' => $outcome->status->value,
                'duration_ms' => $result === null ? 0 : (int) round($result->durationMs),
                'error_text' => $result?->error,
                'result_diff' => $outcome->status === PracticeAttemptStatus::Failed
                    ? ['expected' => $task->expected_rows, 'actual' => $result?->rows]
                    : null,
                'created_at' => now(),
            ]);

            $this->advanceLessonProgress($user, $lesson);

            UserCourseProgress::query()->updateOrCreate(
                ['user_id' => $user->id, 'course_id' => $course->id],
                ['status' => CourseProgressStatus::InProgress, 'current_lesson_id' => $lesson->id],
            );
        });

        assert($submission instanceof PracticeTaskSubmission);

        return new SubmitSolutionOutcome($outcome, $submission);
    }

    /**
     * Upsert the lesson progress row: Completed when the completion
     * checker passes, InProgress otherwise. An existing Completed
     * status is read before writing and never downgraded by a practice
     * attempt; `started_at` is set only on creation, `completed_at`
     * only on the actual transition to Completed.
     */
    private function advanceLessonProgress(User $user, Lesson $lesson): void
    {
        $lessonProgress = UserLessonProgress::query()->firstOrNew([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
        ]);

        $wasCompleted = $lessonProgress->exists
            && $lessonProgress->status === LessonProgressStatus::Completed;

        $attributes = [];

        if (! $lessonProgress->exists) {
            $attributes['started_at'] = now();
        }

        if (($this->completionChecker)($user, $lesson)) {
            $attributes['status'] = LessonProgressStatus::Completed;

            if (! $wasCompleted) {
                $attributes['completed_at'] = now();
            }
        } elseif (! $wasCompleted) {
            $attributes['status'] = LessonProgressStatus::InProgress;
        }

        if ($attributes !== []) {
            // fill()+save(), not update(): Model::update() short-circuits
            // on a not-yet-existing row (firstOrNew) and would skip the
            // insert entirely.
            $lessonProgress->fill($attributes)->save();
        }
    }
}
