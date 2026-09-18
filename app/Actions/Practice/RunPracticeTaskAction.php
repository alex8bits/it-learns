<?php

declare(strict_types=1);

namespace App\Actions\Practice;

use App\Enums\PracticeAttemptStatus;
use App\Models\User;
use App\Services\Practice\Dto\ExecutionResult;
use App\Services\Practice\Dto\PracticeRunOutcome;
use App\Services\Practice\Dto\PracticeTaskInput;
use App\Services\Practice\PracticeConcurrencyLimiter;
use App\Services\Practice\PracticeEnvironmentManager;
use Illuminate\Support\Facades\Cache;

/**
 * Orchestrator of the full practice attempt cycle (Stage 5): the
 * per-user anti-DoS lock (one concurrent environment per user) -> the
 * global concurrency slot (Stage 10: at most
 * practice.concurrency.max_environments environments across all
 * users) -> provision -> execute -> compare. Stage 8 decoupled the AI
 * feedback from the attempt: premium feedback became an explicit route
 * over the stored submission, so this action no longer touches the AI
 * services at all.
 *
 * Invariant (AGENTS.md §5.7-10, a BLOCKER rule): destroy() and the
 * releases of BOTH the global slot and the per-user lock run in the
 * `finally` block on every path out of the attempt — a normal
 * outcome, a guard-reported result and an infrastructure exception
 * alike. Provisioning happens inside the try on a nullable
 * environment: a provision() exception must still release the locks
 * (a leaked one keeps the user Busy until the TTL expires), and
 * destroy() is null-guarded because the manager contract self-cleans
 * — unlinking the file and marking the row Failed — before
 * rethrowing from provision(). The slot is acquired after the
 * per-user lock, so a lost race never holds a slot without the user
 * lock; slot saturation itself releases the user lock before
 * answering Busy (a leaked one would self-deadlock the user's next
 * attempt until the TTL). The lock TTL
 * (practice.lock_ttl_seconds — the worst-case cycle duration) also
 * self-expires a key leaked by a hard crash.
 *
 * Infrastructure exceptions from provision()/execute() are deliberately
 * not caught — they bubble up as HTTP 500 (the PracticeEnvironmentManager
 * contract); an attempt never gets a wrong status from a broken runtime.
 * The cycle writes only to practice_environments, so no DB::transaction
 * is needed (rule #6 covers 2+ table writes; Stage 5 design decision #2).
 */
final class RunPracticeTaskAction
{
    public function __construct(
        private PracticeEnvironmentManager $environments,
        private PracticeConcurrencyLimiter $concurrency,
    ) {}

    /**
     * Run one attempt of the task's code and return the final outcome.
     * Returns the Busy status — without provisioning anything — when
     * another attempt of the same user still holds the environment
     * lock, or when every global concurrency slot is taken.
     */
    public function execute(User $user, PracticeTaskInput $task, string $code): PracticeRunOutcome
    {
        $lock = Cache::lock(
            'practice-env:'.$user->id,
            (int) config('practice.lock_ttl_seconds', 120),
        );

        if (! $lock->get()) {
            return new PracticeRunOutcome(PracticeAttemptStatus::Busy, null, $task->expectedHash);
        }

        $slot = $this->concurrency->acquire();

        if ($slot === null) {
            $lock->release();

            return new PracticeRunOutcome(PracticeAttemptStatus::Busy, null, $task->expectedHash);
        }

        $environment = null;

        try {
            $environment = $this->environments->provision($user, $task);

            $result = $this->environments->execute($environment, $code);

            $status = $this->resolveStatus($result, $task);

            return new PracticeRunOutcome($status, $result, $task->expectedHash);
        } finally {
            if ($environment !== null) {
                $this->environments->destroy($environment);
            }

            $slot->release();
            $lock->release();
        }
    }

    /**
     * Map a raw execution result to the attempt status: a result
     * carrying an error is Error, a confirmed hash match is Passed,
     * everything else is Failed — including an error-free result with
     * no expected hash, where there is nothing to confirm the match
     * against.
     */
    private function resolveStatus(ExecutionResult $result, PracticeTaskInput $task): PracticeAttemptStatus
    {
        if ($result->error !== null) {
            return PracticeAttemptStatus::Error;
        }

        if ($task->expectedHash !== null && $this->environments->compare($result, $task->expectedHash)) {
            return PracticeAttemptStatus::Passed;
        }

        return PracticeAttemptStatus::Failed;
    }
}
