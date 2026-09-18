<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiTokenUsageAction;
use App\Models\AiTokenUsage;
use App\Models\User;
use App\Models\UserLlmLimit;
use Illuminate\Support\Carbon;

/**
 * Append-only ledger of LLM token spends and the daily budget
 * calculations built on top of it (rule #16 of AGENTS.md).
 *
 * Day boundaries use `startOfDay()`/`endOfDay()` range comparisons in
 * the application timezone instead of `whereDate()`, which would wrap
 * `created_at` in a function and defeat the index on it (the same
 * contract as AdminAuditLog::scopeFiltered).
 */
class AiTokenUsageService
{
    /**
     * Append one token spend to the ledger. `created_at` is filled by
     * the database default; all methods write a single table, so no
     * transaction is needed.
     */
    public function recordUsage(User $user, int $tokens, AiTokenUsageAction $action, string $model): void
    {
        AiTokenUsage::create([
            'user_id' => $user->id,
            'model' => $model,
            'tokens' => $tokens,
            'action' => $action,
        ]);
    }

    /**
     * Tokens spent by the user since the start of the current day.
     */
    public function usedToday(User $user): int
    {
        // A single "now" keeps both day boundaries inside the same day even
        // when a call straddles midnight in production; each boundary gets
        // its own copy because startOfDay()/endOfDay() mutate in place.
        $now = Carbon::now();

        return (int) AiTokenUsage::query()
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])
            ->sum('tokens');
    }

    /**
     * Tokens left in the user's daily budget. A missing `user_llm_limits`
     * row counts as `extra_tokens = 0` — users registered before Stage 4
     * are not migrated, the calculation is tolerant to the absent row.
     *
     * The result is not clamped: a negative value means the budget is
     * overdrawn (the guard treats anything `<= 0` as exhausted).
     */
    public function remainingForUserToday(User $user): int
    {
        // A missing `user_llm_limits` row yields null here and counts
        // as `extra_tokens = 0`.
        $extraTokens = (int) (UserLlmLimit::query()
            ->where('user_id', $user->id)
            ->value('extra_tokens') ?? 0);

        return (int) config('ai.token_limit_per_user_per_day') + $extraTokens - $this->usedToday($user);
    }

    /**
     * Tokens left in the platform-wide daily budget across all users.
     */
    public function remainingGlobalToday(): int
    {
        $now = Carbon::now();

        return (int) config('ai.token_limit_global_per_day')
            - (int) AiTokenUsage::query()
                ->whereBetween('created_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])
                ->sum('tokens');
    }
}
