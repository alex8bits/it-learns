<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Enums\AiTokenUsageAction;
use App\Models\AiTokenUsage;
use App\Models\User;
use App\Models\UserLlmLimit;
use App\Services\Ai\AiTokenUsageService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiTokenUsageServiceTest extends TestCase
{
    private AiTokenUsageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.token_limit_per_user_per_day' => 100,
            'ai.token_limit_global_per_day' => 1000,
        ]);

        $this->service = app(AiTokenUsageService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[DataProvider('actions')]
    public function test_record_usage_appends_ledger_row(AiTokenUsageAction $action): void
    {
        $user = User::factory()->create();

        $this->service->recordUsage($user, 250, $action, 'gpt-4o-mini');

        $this->assertDatabaseHas('ai_token_usages', [
            'user_id' => $user->id,
            'model' => 'gpt-4o-mini',
            'tokens' => 250,
            'action' => $action->value,
        ]);
        $this->assertSame(250, $this->service->usedToday($user));
    }

    public function test_used_today_aggregates_only_current_day_of_the_user(): void
    {
        $this->travelTo(Carbon::parse('2026-09-14 12:00:00'));

        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->createUsage($user, 30, '2026-09-14 00:00:00');
        $this->createUsage($user, 20, '2026-09-13 23:59:59');
        $this->createUsage($otherUser, 40, '2026-09-14 10:00:00');

        $this->assertSame(30, $this->service->usedToday($user));
    }

    public function test_remaining_for_user_today_without_limit_row_is_default_minus_used(): void
    {
        $this->travelTo(Carbon::parse('2026-09-14 12:00:00'));

        $user = User::factory()->create();
        $this->createUsage($user, 30, '2026-09-14 10:00:00');

        $this->assertDatabaseMissing('user_llm_limits', ['user_id' => $user->id]);
        $this->assertSame(70, $this->service->remainingForUserToday($user));
    }

    public function test_remaining_for_user_today_adds_extra_tokens(): void
    {
        $this->travelTo(Carbon::parse('2026-09-14 12:00:00'));

        $user = User::factory()->create();
        UserLlmLimit::factory()->withExtra(50)->create(['user_id' => $user->id]);
        $this->createUsage($user, 30, '2026-09-14 10:00:00');

        $this->assertSame(120, $this->service->remainingForUserToday($user));
    }

    public function test_remaining_for_user_today_is_not_clamped_below_zero(): void
    {
        $this->travelTo(Carbon::parse('2026-09-14 12:00:00'));

        $user = User::factory()->create();
        $this->createUsage($user, 150, '2026-09-14 10:00:00');

        $this->assertSame(-50, $this->service->remainingForUserToday($user));
    }

    public function test_remaining_for_user_today_resets_on_a_new_day(): void
    {
        $this->travelTo(Carbon::parse('2026-09-14 12:00:00'));

        $user = User::factory()->create();
        $this->createUsage($user, 100, '2026-09-14 10:00:00');
        $this->assertSame(0, $this->service->remainingForUserToday($user));

        $this->travelTo(Carbon::parse('2026-09-15 08:00:00'));
        $this->assertSame(100, $this->service->remainingForUserToday($user));
    }

    public function test_remaining_global_today_sums_all_users_of_current_day(): void
    {
        $this->travelTo(Carbon::parse('2026-09-14 12:00:00'));

        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->createUsage($first, 200, '2026-09-14 09:00:00');
        $this->createUsage($second, 300, '2026-09-14 11:00:00');
        $this->createUsage($first, 500, '2026-09-13 23:00:00');

        $this->assertSame(500, $this->service->remainingGlobalToday());
    }

    /**
     * @return array<string, array{AiTokenUsageAction}>
     */
    public static function actions(): array
    {
        return [
            'feedback' => [AiTokenUsageAction::Feedback],
            'extra task' => [AiTokenUsageAction::ExtraTask],
        ];
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
