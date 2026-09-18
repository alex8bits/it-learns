<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Models\AiTokenUsage;
use App\Models\User;
use App\Models\UserLlmLimit;
use App\Services\Ai\AiLimitExceededException;
use App\Services\Ai\AiLimitGuard;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AiLimitGuardTest extends TestCase
{
    private AiLimitGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.token_limit_per_user_per_day' => 100,
            'ai.token_limit_global_per_day' => 1000,
        ]);

        $this->guard = app(AiLimitGuard::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_passes_silently_when_no_budget_is_exhausted(): void
    {
        $this->travelTo(Carbon::parse('2026-09-14 12:00:00'));

        $user = User::factory()->create();
        $this->createUsage($user, 30, '2026-09-14 10:00:00');

        $this->guard->ensureQuotaAvailable($user);

        $this->addToAssertionCount(1);
    }

    public function test_throws_when_global_budget_is_exhausted(): void
    {
        $this->travelTo(Carbon::parse('2026-09-14 12:00:00'));

        $spender = User::factory()->create();
        $this->createUsage($spender, 700, '2026-09-14 09:00:00');
        $this->createUsage($spender, 400, '2026-09-14 10:00:00');

        // Personal budget intact — the global budget must block first.
        $freshUser = User::factory()->create();

        try {
            $this->guard->ensureQuotaAvailable($freshUser);
            $this->fail('AiLimitExceededException was not thrown.');
        } catch (AiLimitExceededException $e) {
            $this->assertTrue($e->isGlobalLimit);
            $this->assertSame('Глобальный дневной лимит токенов ИИ исчерпан', $e->getMessage());
        }
    }

    public function test_throws_when_global_remaining_is_exactly_zero(): void
    {
        $this->travelTo(Carbon::parse('2026-09-14 12:00:00'));

        $spender = User::factory()->create();
        $this->createUsage($spender, 1000, '2026-09-14 09:00:00');

        $freshUser = User::factory()->create();

        try {
            $this->guard->ensureQuotaAvailable($freshUser);
            $this->fail('AiLimitExceededException was not thrown.');
        } catch (AiLimitExceededException $e) {
            $this->assertTrue($e->isGlobalLimit);
        }
    }

    public function test_throws_when_user_budget_is_exhausted(): void
    {
        $this->travelTo(Carbon::parse('2026-09-14 12:00:00'));

        $user = User::factory()->create();
        $this->createUsage($user, 100, '2026-09-14 10:00:00');

        // Global budget is fine (900 left) — the user budget must block.
        try {
            $this->guard->ensureQuotaAvailable($user);
            $this->fail('AiLimitExceededException was not thrown.');
        } catch (AiLimitExceededException $e) {
            $this->assertFalse($e->isGlobalLimit);
            $this->assertSame('Дневной лимит токенов ИИ пользователя исчерпан', $e->getMessage());
        }
    }

    public function test_extra_tokens_lift_an_exhausted_user_budget(): void
    {
        $this->travelTo(Carbon::parse('2026-09-14 12:00:00'));

        $user = User::factory()->create();
        $this->createUsage($user, 100, '2026-09-14 10:00:00');

        // Exhausted by the default budget; an admin-granted extra keeps
        // the quota positive, so the guard must stay silent.
        UserLlmLimit::factory()->withExtra(50)->create(['user_id' => $user->id]);

        $this->guard->ensureQuotaAvailable($user);

        $this->addToAssertionCount(1);
    }

    public function test_global_check_runs_before_the_user_check(): void
    {
        $this->travelTo(Carbon::parse('2026-09-14 12:00:00'));

        $user = User::factory()->create();
        $this->createUsage($user, 100, '2026-09-14 10:00:00');
        $other = User::factory()->create();
        $this->createUsage($other, 900, '2026-09-14 11:00:00');

        // Both budgets are exhausted — the global one wins the race.
        try {
            $this->guard->ensureQuotaAvailable($user);
            $this->fail('AiLimitExceededException was not thrown.');
        } catch (AiLimitExceededException $e) {
            $this->assertTrue($e->isGlobalLimit);
        }
    }

    /**
     * Create a usage record with an explicit `created_at`. The column is
     * not mass-assignable and `$timestamps` is disabled, so the boundary
     * timestamp is written via a direct attribute update (the pattern of
     * AdminAuditLogScopeTest).
     */
    private function createUsage(User $user, int $tokens, string $createdAt): AiTokenUsage
    {
        $usage = AiTokenUsage::factory()->create([
            'user_id' => $user->id,
            'tokens' => $tokens,
        ]);

        $usage->created_at = $createdAt;
        $usage->save();

        return $usage;
    }
}
