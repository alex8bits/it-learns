<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SubscriptionInForceScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Freeze time so the `ends_at = now` boundary case (MySQL rounds
        // datetime inserts to whole seconds) is deterministic.
        $this->travelTo(Carbon::parse('2026-09-14 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_active_subscription_with_future_ends_at_is_included(): void
    {
        $subscription = Subscription::factory()->create();

        $ids = Subscription::query()->inForce()->pluck('id');

        $this->assertSame([$subscription->id], $ids->all());
    }

    public function test_cancelled_subscription_with_future_ends_at_is_included(): void
    {
        // Cancelling stops the renewal, not the paid access: until `ends_at`
        // the subscription stays in force.
        $subscription = Subscription::factory()->cancelled()->create();

        $ids = Subscription::query()->inForce()->pluck('id');

        $this->assertSame([$subscription->id], $ids->all());
    }

    public function test_active_status_with_past_ends_at_is_excluded(): void
    {
        Subscription::factory()->create([
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);

        $this->assertSame(0, Subscription::query()->inForce()->count());
    }

    public function test_cancelled_status_with_past_ends_at_is_excluded(): void
    {
        Subscription::factory()->cancelled()->create([
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);

        $this->assertSame(0, Subscription::query()->inForce()->count());
    }

    public function test_expired_status_is_excluded(): void
    {
        Subscription::factory()->expired()->create();

        $this->assertSame(0, Subscription::query()->inForce()->count());
    }

    public function test_boundary_ends_at_equal_to_now_is_excluded(): void
    {
        // `ends_at > now()` is strict: a subscription ending exactly now
        // is already over.
        Subscription::factory()->create(['ends_at' => now()]);

        $this->assertSame(0, Subscription::query()->inForce()->count());
    }

    public function test_pending_subscription_without_dates_is_excluded(): void
    {
        Subscription::factory()->pending()->create();

        $this->assertSame(0, Subscription::query()->inForce()->count());
    }
}
