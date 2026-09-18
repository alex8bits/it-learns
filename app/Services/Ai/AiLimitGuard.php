<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\User;

/**
 * Blocking precondition before every LLM call (rule #16 of AGENTS.md):
 * when a daily token budget is exhausted the caller fails loudly with
 * {@see AiLimitExceededException} — no retries, no silent resets.
 *
 * The check order is fixed: the global budget is evaluated first, the
 * per-user budget second.
 */
class AiLimitGuard
{
    public function __construct(private AiTokenUsageService $usage) {}

    /**
     * Ensure both daily token budgets still have quota left for the
     * user. Returns silently when they do.
     *
     * @throws AiLimitExceededException
     */
    public function ensureQuotaAvailable(User $user): void
    {
        if ($this->usage->remainingGlobalToday() <= 0) {
            throw new AiLimitExceededException(isGlobalLimit: true);
        }

        if ($this->usage->remainingForUserToday($user) <= 0) {
            throw new AiLimitExceededException(isGlobalLimit: false);
        }
    }
}
