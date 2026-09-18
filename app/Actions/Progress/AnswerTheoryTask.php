<?php

declare(strict_types=1);

namespace App\Actions\Progress;

use App\Enums\CourseProgressStatus;
use App\Enums\CourseStatus;
use App\Enums\LessonProgressStatus;
use App\Models\TheoryTask;
use App\Models\TheoryTaskOption;
use App\Models\User;
use App\Models\UserCourseProgress;
use App\Models\UserLessonProgress;
use App\Models\UserTheoryTaskAnswer;
use App\Services\Progress\Dto\AnswerOutcome;
use App\Services\Progress\LessonCompletionChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Records the user's answer to a theory task (Stage 7). Answers are an
 * upserted latest state, not history (design, Non-goals): a re-answer
 * overwrites the previous one. The three progress rows (answer, lesson,
 * course) are written atomically; the lesson flips to Completed via
 * LessonCompletionChecker — the single place owning that rule.
 */
class AnswerTheoryTask
{
    public function __construct(
        private LessonCompletionChecker $completionChecker,
    ) {}

    /**
     * Persist the picked option and update the lesson/course progress.
     *
     * @throws NotFoundHttpException when the task, its lesson or the parent course is not published
     * @throws ValidationException when the option belongs to another task
     */
    public function execute(User $user, TheoryTask $task, TheoryTaskOption $option): AnswerOutcome
    {
        $lesson = $task->lesson;
        $course = $lesson->level->course;

        if (! $task->is_published || ! $lesson->is_published || $course->status !== CourseStatus::Published) {
            throw new NotFoundHttpException('Theory task is not available.');
        }

        if ($option->theory_task_id !== $task->id) {
            throw ValidationException::withMessages([
                'option_id' => 'Этот вариант не относится к текущему вопросу',
            ]);
        }

        $isCorrect = $option->is_correct;

        DB::transaction(function () use ($user, $task, $option, $lesson, $course, $isCorrect): void {
            UserTheoryTaskAnswer::query()->updateOrCreate(
                ['user_id' => $user->id, 'theory_task_id' => $task->id],
                ['option_id' => $option->id, 'is_correct' => $isCorrect, 'answered_at' => now()],
            );

            $lessonProgress = UserLessonProgress::query()->updateOrCreate(
                ['user_id' => $user->id, 'lesson_id' => $lesson->id],
                ['status' => LessonProgressStatus::InProgress],
            );

            if ($lessonProgress->wasRecentlyCreated) {
                $lessonProgress->update(['started_at' => now()]);
            }

            if (($this->completionChecker)($user, $lesson)) {
                $lessonProgress->update([
                    'status' => LessonProgressStatus::Completed,
                    'completed_at' => now(),
                ]);
            }

            UserCourseProgress::query()->updateOrCreate(
                ['user_id' => $user->id, 'course_id' => $course->id],
                ['status' => CourseProgressStatus::InProgress, 'current_lesson_id' => $lesson->id],
            );
        });

        return new AnswerOutcome($isCorrect, $isCorrect ? null : $option->error_text);
    }
}
