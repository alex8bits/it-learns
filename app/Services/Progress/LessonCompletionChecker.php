<?php

declare(strict_types=1);

namespace App\Services\Progress;

use App\Enums\PracticeAttemptStatus;
use App\Models\Lesson;
use App\Models\PracticeTaskSubmission;
use App\Models\User;
use App\Models\UserTheoryTaskAnswer;
use Illuminate\Support\Collection;

/**
 * The single place defining when a lesson counts as completed
 * (Stage 8 rule): a lesson is completed when the user has correctly
 * answered every published theory task of the lesson AND has a Passed
 * submission for every published practice task. Each part is
 * vacuously done when its published set is empty ("empty = done");
 * a material-only lesson (both sets empty) is therefore formally
 * completed, but no user action triggers the check for it, so the
 * observable behavior does not change.
 *
 * Do not duplicate the completion rule anywhere else.
 */
class LessonCompletionChecker
{
    /**
     * Whether the user has completed the lesson's published theory and practice.
     */
    public function __invoke(User $user, Lesson $lesson): bool
    {
        $theoryIds = $lesson->theoryTasks()->published()->pluck('id');

        $theoryDone = $theoryIds->isEmpty()
            || $this->correctTheoryAnswers($user, $theoryIds) === $theoryIds->count();

        $practiceIds = $lesson->practiceTasks()->published()->pluck('id');

        $practiceDone = $practiceIds->isEmpty()
            || $this->passedPracticeSubmissions($user, $practiceIds) === $practiceIds->count();

        return $theoryDone && $practiceDone;
    }

    /**
     * Count the user's correct answers among the given theory tasks.
     *
     * @param  Collection<int, int>  $theoryIds
     */
    private function correctTheoryAnswers(User $user, Collection $theoryIds): int
    {
        return UserTheoryTaskAnswer::query()
            ->where('user_id', $user->id)
            ->whereIn('theory_task_id', $theoryIds)
            ->where('is_correct', true)
            ->count();
    }

    /**
     * Count the distinct practice tasks the user has ever passed —
     * attempts are an append-only history, so a Passed submission
     * keeps counting no matter what later attempts did.
     *
     * @param  Collection<int, int>  $practiceIds
     */
    private function passedPracticeSubmissions(User $user, Collection $practiceIds): int
    {
        return PracticeTaskSubmission::query()
            ->where('user_id', $user->id)
            ->whereIn('practice_task_id', $practiceIds)
            ->where('status', PracticeAttemptStatus::Passed->value)
            ->distinct()
            ->count('practice_task_id');
    }
}
