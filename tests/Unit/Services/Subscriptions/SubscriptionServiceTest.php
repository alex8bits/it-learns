<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Subscriptions;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTier;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Freeze time so period boundaries (`ends_at` vs `now()`) and the
        // MySQL whole-second datetime rounding are deterministic
        // (precedent: SubscriptionActiveScopeTest).
        $this->travelTo(Carbon::parse('2026-09-14 12:00:00'));
    }

    protected function tearDown(): void
    {
        // The activation-rollback test registers a throwing `creating`
        // hook; drop it so it does not leak into other tests.
        Payment::flushEventListeners();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_start_checkout_creates_pending_subscription_and_attaches_external_id(): void
    {
        $user = User::factory()->create();

        $session = app(SubscriptionService::class)->startCheckout($user);

        $this->assertNotNull($session);
        $this->assertSame('dummy', $session->provider);
        $this->assertNotSame('', $session->externalId);
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'tier' => SubscriptionTier::Premium->value,
            'status' => SubscriptionStatus::Pending->value,
            'provider' => 'dummy',
            'external_id' => $session->externalId,
        ]);
    }

    public function test_start_checkout_sweeps_stale_pending_to_cancelled(): void
    {
        $user = User::factory()->create();
        $stale = Subscription::factory()->pending()->create(['user_id' => $user]);

        $this->assertNotNull(app(SubscriptionService::class)->startCheckout($user));

        $this->assertSame(SubscriptionStatus::Cancelled, $stale->fresh()->status);
        // The sweep stamps `cancelled_at`: the moment the row left its
        // state, same semantics as a user-driven `cancel()`.
        $this->assertDatabaseHas('subscriptions', [
            'id' => $stale->id,
            'cancelled_at' => now()->format('Y-m-d H:i:s'),
        ]);
        $this->assertSame(1, Subscription::query()
            ->where('user_id', $user->id)
            ->where('status', SubscriptionStatus::Pending->value)
            ->count());
    }

    public function test_start_checkout_with_explicit_free_tier_creates_pending_with_that_tier(): void
    {
        $user = User::factory()->create();

        $session = app(SubscriptionService::class)->startCheckout($user, SubscriptionTier::Free);

        $this->assertNotNull($session);
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'tier' => SubscriptionTier::Free->value,
            'status' => SubscriptionStatus::Pending->value,
            'provider' => 'dummy',
            'external_id' => $session->externalId,
        ]);
    }

    public function test_start_checkout_evaluates_guard_inside_the_create_transaction(): void
    {
        // TOCTOU-fix semantics, pinned deterministically (real concurrency
        // is not testable in PHPUnit): the pessimistic lock on the user row
        // and the `Pending` create must share one transaction, so the guard
        // decision and the row it protects cannot drift apart. Model hooks
        // observe the transaction level at both moments.
        $user = User::factory()->create();

        $trace = [];
        User::retrieved(static function () use (&$trace): void {
            $trace[] = ['user-lock', DB::transactionLevel()];
        });
        Subscription::creating(static function () use (&$trace): void {
            $trace[] = ['create', DB::transactionLevel()];
        });

        // RefreshDatabase keeps the whole test in its own transaction, so
        // the baseline level is captured instead of hardcoded.
        $baselineLevel = DB::transactionLevel();

        try {
            $this->assertNotNull(app(SubscriptionService::class)->startCheckout($user));
        } finally {
            User::flushEventListeners();
            Subscription::flushEventListeners();
        }

        $this->assertSame(
            [['user-lock', $baselineLevel + 1], ['create', $baselineLevel + 1]],
            $trace,
        );
    }

    public function test_start_checkout_stale_sweep_ignores_other_providers(): void
    {
        $user = User::factory()->create();
        $foreign = Subscription::factory()->pending()->create([
            'user_id' => $user,
            'provider' => 'stripe',
        ]);

        $this->assertNotNull(app(SubscriptionService::class)->startCheckout($user));

        $this->assertSame(SubscriptionStatus::Pending, $foreign->fresh()->status);
    }

    public function test_repeated_start_checkout_leaves_a_single_pending_subscription(): void
    {
        $user = User::factory()->create();
        $service = app(SubscriptionService::class);

        $first = $service->startCheckout($user);
        $second = $service->startCheckout($user);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first->externalId, $second->externalId);
        $this->assertSame(1, Subscription::query()
            ->where('user_id', $user->id)
            ->where('status', SubscriptionStatus::Pending->value)
            ->count());
    }

    public function test_start_checkout_gateway_failure_leaves_harmless_pending_without_payment(): void
    {
        $user = User::factory()->create();

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('createCheckoutSession')
            ->once()
            ->andThrow(new RuntimeException('boom'));
        $this->instance(PaymentGateway::class, $gateway);

        try {
            app(SubscriptionService::class)->startCheckout($user);
            $this->fail('Expected RuntimeException to bubble out of the service.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'status' => SubscriptionStatus::Pending->value,
            'external_id' => null,
        ]);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_start_checkout_returns_null_for_user_with_active_subscription(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->create(['user_id' => $user]);

        // The guard must short-circuit before any gateway interaction.
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('createCheckoutSession')->never();
        $this->instance(PaymentGateway::class, $gateway);

        $session = app(SubscriptionService::class)->startCheckout($user);

        $this->assertNull($session);
        $this->assertSame(1, Subscription::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_start_checkout_returns_null_for_user_with_cancelled_in_force_subscription(): void
    {
        // Cancelling stops the renewal, not the paid access: while `ends_at`
        // is in the future, a repeat checkout is refused.
        $user = User::factory()->create();
        Subscription::factory()->cancelled()->create(['user_id' => $user]);

        $this->assertNull(app(SubscriptionService::class)->startCheckout($user));
        $this->assertSame(1, Subscription::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_start_checkout_works_again_after_access_expired(): void
    {
        $user = User::factory()->create();
        $service = app(SubscriptionService::class);

        $first = $service->startCheckout($user);
        $this->assertNotNull($first);
        $service->activateFromCheckout($user, $first->externalId);

        // Move past `ends_at` and run the daily sweeper: the access is
        // gone, so a new checkout must be possible again.
        $this->travelTo(now()->addMonth()->addDay());
        $service->expireDue();

        $second = $service->startCheckout($user);

        $this->assertNotNull($second);
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'status' => SubscriptionStatus::Pending->value,
            'external_id' => $second->externalId,
        ]);
    }

    public function test_repeated_checkout_while_active_returns_null_keeping_single_active_and_single_payment(): void
    {
        $user = User::factory()->create();
        $service = app(SubscriptionService::class);

        $first = $service->startCheckout($user);
        $this->assertNotNull($first);
        $service->activateFromCheckout($user, $first->externalId);

        $this->assertNull($service->startCheckout($user));

        $this->assertSame(1, Subscription::query()
            ->where('user_id', $user->id)
            ->where('status', SubscriptionStatus::Active->value)
            ->count());
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_activate_from_checkout_activates_pending_and_records_succeeded_payment(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->pending()->create(['user_id' => $user]);

        $activated = app(SubscriptionService::class)
            ->activateFromCheckout($user, $subscription->external_id);

        $this->assertNotNull($activated);
        $this->assertSame(SubscriptionStatus::Active, $activated->status);
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);

        $endsAt = Carbon::parse($subscription->fresh()->ends_at);
        $this->assertTrue(
            $endsAt->between(now()->addDays(29), now()->addDays(31)),
            'ends_at must be about one month after activation.',
        );

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'starts_at' => now()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addMonth()->format('Y-m-d H:i:s'),
        ]);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'provider' => 'dummy',
            'external_id' => "checkout-{$subscription->external_id}",
            'amount' => 99900,
            'currency' => 'RUB',
            'status' => PaymentStatus::Succeeded->value,
        ]);
    }

    public function test_activate_from_checkout_is_idempotent_for_the_same_session(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->pending()->create(['user_id' => $user]);
        $service = app(SubscriptionService::class);

        $first = $service->activateFromCheckout($user, $subscription->external_id);
        $second = $service->activateFromCheckout($user, $subscription->external_id);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_activate_from_checkout_rejects_session_of_another_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $subscription = Subscription::factory()->pending()->create(['user_id' => $owner]);

        $result = app(SubscriptionService::class)
            ->activateFromCheckout($intruder, $subscription->external_id);

        $this->assertNull($result);
        $this->assertSame(SubscriptionStatus::Pending, $subscription->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_activate_from_checkout_returns_null_for_unknown_session(): void
    {
        $user = User::factory()->create();

        $this->assertNull(app(SubscriptionService::class)->activateFromCheckout($user, 'no-such-session'));
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_activate_from_checkout_rejects_session_of_another_provider(): void
    {
        $user = User::factory()->create();
        $foreign = Subscription::factory()->pending()->create([
            'user_id' => $user,
            'provider' => 'stripe',
        ]);

        $result = app(SubscriptionService::class)->activateFromCheckout($user, $foreign->external_id);

        $this->assertNull($result);
        $this->assertSame(SubscriptionStatus::Pending, $foreign->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_handle_payment_succeeded_activates_pending_with_provider_payment_id(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->pending()->create(['user_id' => $user]);

        $result = app(SubscriptionService::class)
            ->handlePaymentSucceeded($subscription->external_id, 'wh-42');

        $this->assertTrue($result);
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', [
            'provider' => 'dummy',
            'external_id' => 'wh-42',
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'status' => PaymentStatus::Succeeded->value,
        ]);
    }

    public function test_handle_payment_succeeded_does_not_duplicate_the_same_provider_payment(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->pending()->create(['user_id' => $user]);
        $service = app(SubscriptionService::class);

        $this->assertTrue($service->handlePaymentSucceeded($subscription->external_id, 'wh-42'));
        $this->assertTrue($service->handlePaymentSucceeded($subscription->external_id, 'wh-42'));

        $this->assertSame(1, Payment::query()->where('external_id', 'wh-42')->count());
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
    }

    public function test_handle_payment_succeeded_returns_false_for_unknown_session(): void
    {
        $user = User::factory()->create();
        $foreign = Subscription::factory()->pending()->create([
            'user_id' => $user,
            'provider' => 'stripe',
        ]);

        $service = app(SubscriptionService::class);

        $this->assertFalse($service->handlePaymentSucceeded('no-such-session', 'wh-1'));
        $this->assertFalse($service->handlePaymentSucceeded($foreign->external_id, 'wh-1'));
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(SubscriptionStatus::Pending, $foreign->fresh()->status);
    }

    public function test_handle_payment_succeeded_after_activation_records_payment_without_extending_period(): void
    {
        $user = User::factory()->create();
        $service = app(SubscriptionService::class);

        $session = $service->startCheckout($user);
        $this->assertNotNull($session);
        $service->activateFromCheckout($user, $session->externalId);

        $subscription = Subscription::query()->where('external_id', $session->externalId)->firstOrFail();

        $result = $service->handlePaymentSucceeded($session->externalId, 'wh-42');

        $this->assertTrue($result);
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'ends_at' => now()->addMonth()->format('Y-m-d H:i:s'),
        ]);
        $this->assertSame(2, Payment::query()->where('subscription_id', $subscription->id)->count());
        $this->assertDatabaseHas('payments', [
            'external_id' => "checkout-{$session->externalId}",
        ]);
        $this->assertDatabaseHas('payments', [
            'external_id' => 'wh-42',
        ]);
    }

    public function test_handle_payment_succeeded_for_cancelled_subscription_records_payment_without_touching_period(): void
    {
        // A late webhook for a subscription the user has already cancelled:
        // the payment is still recorded, but the paid period must not move.
        $user = User::factory()->create();
        $subscription = Subscription::factory()->cancelled()->create([
            'user_id' => $user,
            'external_id' => 'sess-cancelled',
        ]);

        $result = app(SubscriptionService::class)->handlePaymentSucceeded('sess-cancelled', 'wh-42');

        $this->assertTrue($result);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => SubscriptionStatus::Cancelled->value,
            'ends_at' => now()->addMonth()->format('Y-m-d H:i:s'),
        ]);
        $this->assertSame(1, Payment::query()->where('external_id', 'wh-42')->count());
        $this->assertDatabaseHas('payments', [
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'status' => PaymentStatus::Succeeded->value,
        ]);
    }

    public function test_handle_payment_succeeded_for_expired_subscription_records_payment_without_touching_period(): void
    {
        // Same for an already-expired subscription: bookkeeping only, no
        // status or period changes.
        $user = User::factory()->create();
        $subscription = Subscription::factory()->expired()->create([
            'user_id' => $user,
            'external_id' => 'sess-expired',
        ]);

        $result = app(SubscriptionService::class)->handlePaymentSucceeded('sess-expired', 'wh-42');

        $this->assertTrue($result);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => SubscriptionStatus::Expired->value,
            'ends_at' => now()->subDay()->format('Y-m-d H:i:s'),
        ]);
        $this->assertSame(1, Payment::query()->where('external_id', 'wh-42')->count());
        $this->assertDatabaseHas('payments', [
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'status' => PaymentStatus::Succeeded->value,
        ]);
    }

    public function test_cancel_marks_active_subscription_cancelled_and_keeps_access_until_ends_at(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->create(['user_id' => $user]);

        $cancelled = app(SubscriptionService::class)->cancel($user);

        $this->assertNotNull($cancelled);
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->fresh()->status);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'cancelled_at' => now()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addMonth()->format('Y-m-d H:i:s'),
        ]);
        $this->assertTrue(app(SubscriptionService::class)->isActive($user));
    }

    public function test_cancel_returns_null_without_subscriptions(): void
    {
        $user = User::factory()->create();

        $this->assertNull(app(SubscriptionService::class)->cancel($user));
    }

    public function test_cancel_returns_null_for_pending_only(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->pending()->create(['user_id' => $user]);

        $this->assertNull(app(SubscriptionService::class)->cancel($user));
        $this->assertSame(SubscriptionStatus::Pending, $subscription->fresh()->status);
    }

    public function test_cancel_returns_null_when_already_cancelled(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->cancelled()->create(['user_id' => $user]);

        $this->assertNull(app(SubscriptionService::class)->cancel($user));
    }

    public function test_cancel_returns_null_when_active_period_already_ended(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->create([
            'user_id' => $user,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);

        $this->assertNull(app(SubscriptionService::class)->cancel($user));
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
    }

    public function test_is_active_true_for_active_with_future_ends_at(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->create(['user_id' => $user]);

        $this->assertTrue(app(SubscriptionService::class)->isActive($user));
    }

    public function test_is_active_false_without_subscriptions(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(app(SubscriptionService::class)->isActive($user));
    }

    public function test_is_active_false_for_expired_and_pending(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->expired()->create(['user_id' => $user]);
        Subscription::factory()->pending()->create(['user_id' => $user]);

        $this->assertFalse(app(SubscriptionService::class)->isActive($user));
    }

    public function test_is_active_true_for_cancelled_with_future_ends_at(): void
    {
        // Cancelling stops the renewal, not the paid access: until `ends_at`
        // the user keeps premium (design decision, EnsurePremium relies on it).
        $user = User::factory()->create();
        Subscription::factory()->cancelled()->create(['user_id' => $user]);

        $this->assertTrue(app(SubscriptionService::class)->isActive($user));
    }

    public function test_is_active_boundary_ends_at_equal_to_now_is_false(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->create([
            'user_id' => $user,
            'starts_at' => now()->subMonth(),
            'ends_at' => now(),
        ]);

        $this->assertFalse(app(SubscriptionService::class)->isActive($user));
    }

    public function test_expires_at_returns_ends_at_of_latest_subscription_with_period(): void
    {
        $user = User::factory()->create();
        $active = Subscription::factory()->create([
            'user_id' => $user,
            'ends_at' => now()->addMonth(),
        ]);

        // A later stale-swept row has no dates and must not shadow the period.
        $stale = Subscription::factory()->pending()->create(['user_id' => $user]);
        Subscription::query()
            ->whereKey($stale->id)
            ->update(['status' => SubscriptionStatus::Cancelled->value]);

        $expiresAt = app(SubscriptionService::class)->expiresAt($user);

        $this->assertSame(
            now()->addMonth()->format('Y-m-d H:i:s'),
            $expiresAt?->format('Y-m-d H:i:s'),
        );
    }

    public function test_expires_at_prefers_latest_row_when_two_in_force_periods_overlap(): void
    {
        // With two in-force rows the `latest('id')` ordering decides: the
        // row created last wins, not the one with the longer period.
        $user = User::factory()->create();
        $older = Subscription::factory()->create([
            'user_id' => $user,
            'ends_at' => now()->addDays(10),
        ]);
        $newer = Subscription::factory()->create([
            'user_id' => $user,
            'ends_at' => now()->addMonth(),
        ]);

        $this->assertGreaterThan($older->id, $newer->id);

        $expiresAt = app(SubscriptionService::class)->expiresAt($user);

        $this->assertSame(
            now()->addMonth()->format('Y-m-d H:i:s'),
            $expiresAt?->format('Y-m-d H:i:s'),
        );
    }

    public function test_expires_at_null_without_subscriptions(): void
    {
        $user = User::factory()->create();

        $this->assertNull(app(SubscriptionService::class)->expiresAt($user));
    }

    public function test_expires_at_null_for_dateless_rows_only(): void
    {
        $user = User::factory()->create();
        $stale = Subscription::factory()->pending()->create(['user_id' => $user]);
        Subscription::query()
            ->whereKey($stale->id)
            ->update(['status' => SubscriptionStatus::Cancelled->value]);

        $this->assertNull(app(SubscriptionService::class)->expiresAt($user));
    }

    public function test_expire_due_marks_past_active_and_cancelled_as_expired(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $dueActive = Subscription::factory()->create([
            'user_id' => $user,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);
        $dueCancelled = Subscription::factory()->cancelled()->create([
            'user_id' => $user,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);
        $future = Subscription::factory()->create(['user_id' => $other]);
        $pending = Subscription::factory()->pending()->create(['user_id' => $user]);
        $alreadyExpired = Subscription::factory()->expired()->create(['user_id' => $other]);

        $count = app(SubscriptionService::class)->expireDue();

        $this->assertSame(2, $count);
        $this->assertSame(SubscriptionStatus::Expired, $dueActive->fresh()->status);
        $this->assertSame(SubscriptionStatus::Expired, $dueCancelled->fresh()->status);
        $this->assertSame(SubscriptionStatus::Active, $future->fresh()->status);
        $this->assertSame(SubscriptionStatus::Pending, $pending->fresh()->status);
        $this->assertSame(SubscriptionStatus::Expired, $alreadyExpired->fresh()->status);
    }

    public function test_expire_due_boundary_ends_at_exactly_now_expires(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->create([
            'user_id' => $user,
            'starts_at' => now()->subMonth(),
            'ends_at' => now(),
        ]);

        $count = app(SubscriptionService::class)->expireDue();

        $this->assertSame(1, $count);
        $this->assertSame(SubscriptionStatus::Expired, $subscription->fresh()->status);
    }

    public function test_expire_due_honors_explicit_now_argument(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->create([
            'user_id' => $user,
            'ends_at' => now()->addDay(),
        ]);

        $this->assertSame(0, app(SubscriptionService::class)->expireDue(now()));
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);

        $this->assertSame(1, app(SubscriptionService::class)->expireDue(now()->addDays(2)));
        $this->assertSame(SubscriptionStatus::Expired, $subscription->fresh()->status);
    }

    public function test_activation_rolls_back_subscription_when_payment_persist_fails(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->pending()->create(['user_id' => $user]);

        Payment::creating(static function (): void {
            throw new RuntimeException('boom');
        });

        try {
            app(SubscriptionService::class)->activateFromCheckout($user, $subscription->external_id);
            $this->fail('Expected RuntimeException to bubble out of the service.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => SubscriptionStatus::Pending->value,
            'starts_at' => null,
            'ends_at' => null,
        ]);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_full_lifecycle_never_produces_two_active_subscriptions(): void
    {
        $user = User::factory()->create();
        $service = app(SubscriptionService::class);

        $first = $service->startCheckout($user);
        $this->assertNotNull($first);
        $service->activateFromCheckout($user, $first->externalId);
        $service->cancel($user);

        // While the cancelled period is still in force, a repeat checkout
        // is refused: no second `Active`, no second charge.
        $refused = $service->startCheckout($user);
        $this->assertNull($refused);

        // Once the period has lapsed and been expired, checkout works again.
        $this->travelTo(now()->addMonth()->addDay());
        $service->expireDue();

        $second = $service->startCheckout($user);
        $this->assertNotNull($second);
        $service->activateFromCheckout($user, $second->externalId);

        $this->assertSame(1, Subscription::query()
            ->where('user_id', $user->id)
            ->where('status', SubscriptionStatus::Active->value)
            ->count());
    }

    public function test_webhook_with_valid_payload_activates_pending_subscription(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->pending()->create(['user_id' => $user]);

        app(PaymentGateway::class)->handleWebhook($this->webhookRequest((string) json_encode([
            'event' => 'payment.succeeded',
            'session' => $subscription->external_id,
            'provider_payment_id' => 'wh-77',
        ])));

        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'provider' => 'dummy',
            'external_id' => 'wh-77',
            'subscription_id' => $subscription->id,
            'status' => PaymentStatus::Succeeded->value,
        ]);
    }

    public function test_webhook_with_malformed_payloads_is_noop(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->pending()->create(['user_id' => $user]);
        $gateway = app(PaymentGateway::class);

        $emptyBody = Request::create('/subscription/webhook', 'POST');
        $gateway->handleWebhook($emptyBody);

        $notJson = $this->webhookRequest('not json at all');
        $gateway->handleWebhook($notJson);

        $numericSession = $this->webhookRequest((string) json_encode([
            'event' => 'payment.succeeded',
            'session' => 12345,
            'provider_payment_id' => 'wh-1',
        ]));
        $gateway->handleWebhook($numericSession);

        $this->assertSame(SubscriptionStatus::Pending, $subscription->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_webhook_with_foreign_event_is_noop(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->pending()->create(['user_id' => $user]);

        app(PaymentGateway::class)->handleWebhook($this->webhookRequest((string) json_encode([
            'event' => 'payment.failed',
            'session' => $subscription->external_id,
            'provider_payment_id' => 'wh-1',
        ])));

        $this->assertSame(SubscriptionStatus::Pending, $subscription->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * Build a POST request with the given raw body and a JSON content type,
     * the way the webhook route receives provider callbacks.
     */
    private function webhookRequest(string $content): Request
    {
        return Request::create('/subscription/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $content);
    }
}
