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
 * (the "first K by order" rule): a lesson is completed when the user
 * has correctly answered the first K published theory tasks of the
 * lesson by `order` AND has a Passed submission for the first M
 * published practice tasks by `order`. K and M come from
 * config('progress.*_required_per_lesson') and are capped by the
 * number of published tasks (min(K, N): a lesson with fewer tasks
 * requires all of them). Tasks beyond the required window are
 * optional and never affect completion. Each part is vacuously done
 * when its required window is empty ("empty = done"); a material-only
 * lesson (both windows empty) is therefore formally completed, but no
 * user action triggers the check for it, so the observable behavior
 * does not change.
 *
 * Do not duplicate the completion rule anywhere else.
 */
class LessonCompletionChecker
{
    /**
     * Whether the user has completed the lesson's required theory and
     * practice windows (the first K published tasks of each part by order).
     */
    public function __invoke(User $user, Lesson $lesson): bool
    {
        $theoryIds = $lesson->theoryTasks()->published()->pluck('id');

        $requiredTheoryIds = $theoryIds->take($this->requiredTheoryCount());

        $theoryDone = $requiredTheoryIds->isEmpty()
            || $this->correctTheoryAnswers($user, $requiredTheoryIds) === $requiredTheoryIds->count();

        $practiceIds = $lesson->practiceTasks()->published()->pluck('id');

        $requiredPracticeIds = $practiceIds->take($this->requiredPracticeCount());

        $practiceDone = $requiredPracticeIds->isEmpty()
            || $this->passedPracticeSubmissions($user, $requiredPracticeIds) === $requiredPracticeIds->count();

        return $theoryDone && $practiceDone;
    }

    /**
     * The configured size of the required theory window. `take()`
     * applies the min(K, N) semantics, so a lesson with fewer
     * published tasks simply requires all of them.
     */
    private function requiredTheoryCount(): int
    {
        return max(0, (int) config('progress.theory_required_per_lesson', 3));
    }

    /**
     * The configured size of the required practice window (same
     * min(K, N) semantics as the theory window).
     */
    private function requiredPracticeCount(): int
    {
        return max(0, (int) config('progress.practice_required_per_lesson', 1));
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
