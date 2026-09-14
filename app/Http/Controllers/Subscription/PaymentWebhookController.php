<?php

declare(strict_types=1);

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public endpoint the payment provider calls after a payment. The request
 * is provider input, not user input: the gateway validates the payload
 * shape itself (rule №2 exception for provider-side data) and delegates
 * the bookkeeping to `SubscriptionService` through the gateway contract.
 * Exempt from CSRF (bootstrap/app.php) and rate-limited by
 * `throttle:payment-webhook`.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $gateway->handleWebhook($request);

        return response()->json(['ok' => true]);
    }
}
