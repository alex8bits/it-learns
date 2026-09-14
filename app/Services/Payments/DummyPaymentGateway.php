<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\SubscriptionTier;
use App\Models\Payment;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Fictitious gateway for dev/tests/demo (consistent with DummyLlmClient):
 * "clicked Buy Premium -> immediately got a month-long subscription"
 * without a real payment. No network calls; persistence is delegated to
 * SubscriptionService (webhook), never done by the gateway itself.
 */
class DummyPaymentGateway implements PaymentGateway
{
    /**
     * Generate a locally resolvable checkout session: a fresh UUID as the
     * external id and the fixed return URL the checkout return action
     * handles (the route itself is registered by the user zone task).
     */
    public function createCheckoutSession(User $user, SubscriptionTier $tier): CheckoutSession
    {
        $externalId = (string) Str::uuid();

        return new CheckoutSession(
            url: '/subscription/checkout/return?session='.rawurlencode($externalId),
            externalId: $externalId,
            provider: 'dummy',
        );
    }

    /**
     * Synthetic Stage-3 webhook contract (design decision C1), the only
     * payload this gateway accepts:
     * `{"event": "payment.succeeded", "session": "<subscription external_id>",
     *   "provider_payment_id": "<provider-side payment id>"}`.
     *
     * Anything else — malformed body, missing/non-string fields, foreign
     * events — is a no-op: no real provider traffic reaches this gateway.
     *
     * Cycle-break: SubscriptionService is resolved here via the container,
     * NOT injected through the constructor. This gateway is a singleton
     * (AppServiceProvider), and constructor-injecting the service would
     * create a resolution cycle: singleton gateway -> SubscriptionService
     * -> PaymentGateway (the same singleton). Method-level resolution
     * breaks the ring.
     */
    public function handleWebhook(Request $request): void
    {
        $payload = $request->json()->all();

        if (! is_array($payload) || $payload === []) {
            return;
        }

        if (($payload['event'] ?? null) !== 'payment.succeeded') {
            return;
        }

        $session = $payload['session'] ?? null;
        $providerPaymentId = $payload['provider_payment_id'] ?? null;

        if (! is_string($session) || ! is_string($providerPaymentId)) {
            return;
        }

        app(SubscriptionService::class)->handlePaymentSucceeded($session, $providerPaymentId);
    }

    /**
     * No-op refund: always succeeds without moving real money. A real
     * refund arrives with the production gateway (Stage 3.1).
     */
    public function refund(Payment $payment): bool
    {
        return true;
    }
}
