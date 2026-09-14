<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * Smoke test of the `subscriptions:expire` console command (HTTP-less
 * boundary). The expiry rules themselves are covered in Unit by
 * `SubscriptionServiceTest` — here only the happy path.
 */
class ExpireSubscriptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Freeze time so the fixture dates vs `now()` comparison and the
        // MySQL whole-second datetime rounding stay deterministic
        // (precedent: SubscriptionServiceTest).
        $this->travelTo(Carbon::parse('2026-09-14 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_expires_due_subscriptions(): void
    {
        $user = User::factory()->create();
        $dueActive = Subscription::factory()->create([
            'user_id' => $user,
            'ends_at' => now()->subDay(),
        ]);
        $dueCancelled = Subscription::factory()->cancelled()->create([
            'user_id' => $user,
            'ends_at' => now()->subDay(),
        ]);
        $futureActive = Subscription::factory()->create(['user_id' => $user]);
        $pending = Subscription::factory()->pending()->create(['user_id' => $user]);

        // `artisan()` is stubbed as `PendingCommand|int` by larastan; with
        // the default mocked console output it is always a PendingCommand.
        /** @var PendingCommand $command */
        $command = $this->artisan('subscriptions:expire');

        $command->expectsOutputToContain('Expired subscriptions: 2')
            ->assertExitCode(0);

        // PendingCommand runs on destruct; drop it now so the command (and
        // its expectations) execute before the database assertions below.
        unset($command);

        $this->assertDatabaseHas('subscriptions', [
            'id' => $dueActive->id,
            'status' => SubscriptionStatus::Expired->value,
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $dueCancelled->id,
            'status' => SubscriptionStatus::Expired->value,
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $futureActive->id,
            'status' => SubscriptionStatus::Active->value,
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $pending->id,
            'status' => SubscriptionStatus::Pending->value,
        ]);
    }
}
