<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Result of PaymentGateway::createCheckoutSession(): where to redirect
 * the user to pay, and how the session is identified at the provider.
 */
final readonly class CheckoutSession
{
    /**
     * @param  string  $url  URL the user is redirected to in order to pay (relative for the dummy gateway, absolute for production ones)
     * @param  string  $externalId  provider-side session identifier, attached to the subscription by the caller after the gateway call
     * @param  string  $provider  gateway name the session was created with (matches config('payments.provider'))
     */
    public function __construct(
        public string $url,
        public string $externalId,
        public string $provider,
    ) {}
}
