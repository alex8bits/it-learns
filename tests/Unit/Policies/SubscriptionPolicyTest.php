<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Subscription;
use App\Models\User;
use App\Policies\SubscriptionPolicy;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class SubscriptionPolicyTest extends TestCase
{
    private SubscriptionPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = app(SubscriptionPolicy::class);
    }

    public function test_access_premium_allows_user_with_active_subscription(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->active()->create(['user_id' => $user]);

        $this->assertTrue($this->policy->accessPremium($user));
    }

    public function test_access_premium_denies_user_without_subscriptions(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->policy->accessPremium($user));
    }

    public function test_access_premium_denies_user_with_expired_subscription(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->expired()->create(['user_id' => $user]);

        $this->assertFalse($this->policy->accessPremium($user));
    }

    public function test_access_premium_denies_active_subscription_with_past_ends_at(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->active()->create([
            'user_id' => $user,
            'ends_at' => now()->subMinute(),
        ]);

        $this->assertFalse($this->policy->accessPremium($user));
    }

    public function test_access_premium_allows_cancelled_subscription_with_future_ends_at(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->cancelled()->create([
            'user_id' => $user,
            'ends_at' => now()->addDays(3),
        ]);

        $this->assertTrue($this->policy->accessPremium($user));
    }

    public function test_access_premium_denies_cancelled_subscription_with_past_ends_at(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->cancelled()->create([
            'user_id' => $user,
            'ends_at' => now()->subMinute(),
        ]);

        $this->assertFalse($this->policy->accessPremium($user));
    }

    public function test_gate_resolves_subscription_policy_by_convention(): void
    {
        $this->assertInstanceOf(SubscriptionPolicy::class, Gate::getPolicyFor(Subscription::class));
    }

    public function test_gate_allows_access_premium_end_to_end(): void
    {
        $free = User::factory()->create();
        $premium = User::factory()->create();
        Subscription::factory()->active()->create(['user_id' => $premium]);

        $this->assertTrue(Gate::forUser($premium)->allows('accessPremium', Subscription::class));
        $this->assertFalse(Gate::forUser($free)->allows('accessPremium', Subscription::class));
    }
}
