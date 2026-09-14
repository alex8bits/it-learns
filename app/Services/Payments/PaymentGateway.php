<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\SubscriptionTier;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;

interface PaymentGateway
{
    /**
     * Create a checkout session at the provider for the given user and
     * subscription tier. Pure gateway call: it does not persist anything
     * — the caller stores externalId on the subscription afterwards.
     */
    public function createCheckoutSession(User $user, SubscriptionTier $tier): CheckoutSession;

    /**
     * Handle an incoming provider webhook request. Provider-side payload
     * is validated inside the gateway (provider input, not user input).
     */
    public function handleWebhook(Request $request): void;

    /**
     * Refund a previously succeeded payment via the provider.
     */
    public function refund(Payment $payment): bool;
}
