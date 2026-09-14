<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTier;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Payments\CheckoutSession;
use App\Services\Payments\PaymentGateway;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;

/**
 * Domain service for the whole subscription lifecycle: checkout, activation,
 * cancellation, expiry and payment bookkeeping.
 *
 * All writes to `subscriptions`/`payments` go through this service (rule №3:
 * business operations live in services, not controllers/models). Every
 * mutation touching 2+ tables runs inside `DB::transaction` (rule №6).
 */
class SubscriptionService
{
    public function __construct(private PaymentGateway $gateway) {}

    /**
     * Start a checkout for the given tier: refuse when the user already
     * has in-force access, otherwise sweep the user's stale `Pending`
     * attempts to `Cancelled`, create a fresh `Pending` subscription, then
     * ask the gateway for a checkout session and attach its external id
     * (persist -> call -> attach, design decision B1: a gateway failure
     * leaves a harmless `Pending` that the next checkout sweeps away).
     *
     * The guard, the stale-sweep and the create run atomically in one
     * transaction, serialized by a pessimistic `SELECT ... FOR UPDATE` on
     * the user's own row: that row always exists, so two concurrent
     * checkouts of the same user cannot both pass the guard (the
     * empty-subscription phantom case is covered by locking a real row)
     * and there is never a second `Active` subscription or a second
     * charge from a race. The gateway is still called outside the
     * transaction (B1).
     *
     * The sweep stamps `cancelled_at = now` alongside the status, keeping
     * one meaning of `cancelled_at` across the lifecycle: the moment the
     * row left its state — `Active` cancelled by the user (`cancel()`) or
     * a stale `Pending` swept by the system here.
     *
     * Returns `null` — without writing anything and without calling the
     * gateway — when the user already has an in-force subscription
     * (`Active`, or `Cancelled` with a future `ends_at`): while the paid
     * access lasts there must never be a second checkout, a second
     * `Active` subscription, or a second charge.
     */
    public function startCheckout(User $user, SubscriptionTier $tier = SubscriptionTier::Premium): ?CheckoutSession
    {
        $provider = $this->provider();

        /** @var Subscription|null $subscription */
        $subscription = DB::transaction(function () use ($user, $tier, $provider): ?Subscription {
            // Serialize concurrent checkouts of the same user: the users
            // row always exists, so the gap/phantom case of an empty
            // subscription set is covered too (SELECT ... FOR UPDATE on a
            // real row).
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (Subscription::query()->where('user_id', $user->id)->inForce()->exists()) {
                return null;
            }

            Subscription::query()
                ->where('user_id', $user->id)
                ->where('status', SubscriptionStatus::Pending->value)
                ->where('provider', $provider)
                ->update([
                    'status' => SubscriptionStatus::Cancelled->value,
                    'cancelled_at' => now(),
                ]);

            return Subscription::query()->create([
                'user_id' => $user->id,
                'tier' => $tier,
                'status' => SubscriptionStatus::Pending,
                'provider' => $provider,
                'external_id' => null,
            ]);
        });

        if ($subscription === null) {
            return null;
        }

        $session = $this->gateway->createCheckoutSession($user, $tier);

        $subscription->external_id = $session->externalId;
        $subscription->save();

        return $session;
    }

    /**
     * Activate a `Pending` subscription from the checkout return route.
     *
     * Idempotent: a session that is unknown, belongs to another user, comes
     * from another provider, or is no longer `Pending` (e.g. the webhook
     * activated it first) yields `null` without side effects.
     */
    public function activateFromCheckout(User $user, string $session): ?Subscription
    {
        $subscription = Subscription::query()
            ->where('external_id', $session)
            ->where('user_id', $user->id)
            ->where('provider', $this->provider())
            ->where('status', SubscriptionStatus::Pending->value)
            ->first();

        if ($subscription === null) {
            return null;
        }

        $this->activate($subscription, "checkout-{$session}");

        return $subscription;
    }

    /**
     * Process a `payment.succeeded` webhook for a checkout session. The
     * webhook is unauthenticated, so the user is resolved through the
     * subscription, never from the payload.
     *
     * Returns `true` when the session is known (activated now or earlier —
     * the payment is recorded idempotently by `(provider, external_id)`),
     * `false` when the session is unknown altogether.
     */
    public function handlePaymentSucceeded(string $session, string $providerPaymentId): bool
    {
        $subscription = Subscription::query()
            ->where('external_id', $session)
            ->where('provider', $this->provider())
            ->first();

        if ($subscription === null) {
            return false;
        }

        if ($subscription->status === SubscriptionStatus::Pending) {
            $this->activate($subscription, $providerPaymentId);

            return true;
        }

        // Already activated (e.g. the return route won the race): record the
        // provider-side payment id without touching the subscription period.
        $this->recordPayment($subscription, $providerPaymentId);

        return true;
    }

    /**
     * Cancel the user's active subscription: `Active` -> `Cancelled`,
     * `cancelled_at = now`. Access persists until `ends_at` (see
     * `isActive()`), there is just no renewal. Returns `null` when there is
     * nothing cancellable.
     */
    public function cancel(User $user): ?Subscription
    {
        $subscription = Subscription::query()
            ->where('user_id', $user->id)
            ->where('status', SubscriptionStatus::Active->value)
            ->where('ends_at', '>', now())
            ->first();

        if ($subscription === null) {
            return null;
        }

        return DB::transaction(function () use ($subscription): Subscription {
            $subscription->fill([
                'status' => SubscriptionStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();

            return $subscription;
        });
    }

    /**
     * Does the user have premium access right now? A subscription is in
     * force while its paid period lasts: `Active`, or `Cancelled` with a
     * future `ends_at` (cancelling stops the renewal, not the paid access —
     * design decision, EnsurePremium gates through this method). Direct
     * exists() query, not a lazy-loaded relation (preventLazyLoading).
     */
    public function isActive(User $user): bool
    {
        return Subscription::query()
            ->where('user_id', $user->id)
            ->inForce()
            ->exists();
    }

    /**
     * When the user's in-force period ends (`null` when they have none).
     * The `Subscription::scopeInForce` predicate keeps dateless rows
     * (stale-swept `Pending`) from shadowing a real period.
     */
    public function expiresAt(User $user): ?CarbonInterface
    {
        /** @var CarbonInterface|null $endsAt */
        $endsAt = Subscription::query()
            ->where('user_id', $user->id)
            ->inForce()
            ->latest('id')
            ->value('ends_at');

        return $endsAt;
    }

    /**
     * Mark in-force subscriptions whose period has ended as `Expired`
     * (daily `subscriptions:expire`). Single-table bulk update — no
     * `DB::transaction` needed (rule №6 covers 2+ table writes). Returns
     * the number of affected rows.
     */
    public function expireDue(?CarbonInterface $now = null): int
    {
        return Subscription::query()
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Cancelled->value,
            ])
            ->where('ends_at', '<=', $now ?? now())
            ->update(['status' => SubscriptionStatus::Expired->value]);
    }

    /**
     * Flip a `Pending` subscription to `Active` for one tariff period and
     * record its `Succeeded` payment — atomically, so a payment failure
     * leaves the subscription `Pending`.
     */
    private function activate(Subscription $subscription, string $paymentExternalId): void
    {
        DB::transaction(function () use ($subscription, $paymentExternalId): void {
            $subscription->fill([
                'status' => SubscriptionStatus::Active,
                'starts_at' => now(),
                'ends_at' => $this->periodEnd(),
            ])->save();

            $this->recordPayment($subscription, $paymentExternalId);
        });
    }

    /**
     * Record the tariff payment keyed by the unique `(provider,
     * external_id)` pair — `firstOrCreate` keeps repeated webhook deliveries
     * and re-deliveries idempotent.
     */
    private function recordPayment(Subscription $subscription, string $paymentExternalId): Payment
    {
        $premium = $this->premiumConfig();

        return Payment::query()->firstOrCreate(
            [
                'provider' => $this->provider(),
                'external_id' => $paymentExternalId,
            ],
            [
                'user_id' => $subscription->user_id,
                'subscription_id' => $subscription->id,
                'amount' => $premium['amount_minor'],
                'currency' => $premium['currency'],
                'status' => PaymentStatus::Succeeded,
            ],
        );
    }

    /**
     * Period end from the current moment, derived from the configured
     * tariff duration (`config('payments.premium.duration')`).
     */
    private function periodEnd(): CarbonInterface
    {
        return now()->add(CarbonInterval::fromString($this->premiumConfig()['duration']));
    }

    private function provider(): string
    {
        /** @var string $provider */
        $provider = config('payments.provider');

        return $provider;
    }

    /**
     * @return array{amount_minor: int, currency: string, duration: string}
     */
    private function premiumConfig(): array
    {
        /** @var array{amount_minor: int, currency: string, duration: string} $premium */
        $premium = config('payments.premium');

        return $premium;
    }
}
