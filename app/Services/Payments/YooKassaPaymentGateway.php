<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\SubscriptionTier;
use App\Models\Payment;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Production YooKassa gateway (Stage 9): a thin direct-HTTP client of
 * the YooKassa REST API (Basic auth shopId:secretKey, mandatory
 * Idempotence-Key on POSTs) following the project's canonical HTTP
 * integration pattern — Http facade, timeout(30), ->throw(), no
 * retries, fail loud. Like the dummy gateway it never persists
 * anything: activation on webhook is delegated to SubscriptionService.
 *
 * Webhook trust model: YooKassa does not sign its notifications, so the
 * notification body is never trusted — the payment is re-fetched via
 * GET /payments/{id} and only a payment personally seen as
 * `status: succeeded` + `paid: true` activates anything.
 *
 * Cycle-break: SubscriptionService is resolved here via the container,
 * NOT injected through the constructor. This gateway is a singleton
 * (AppServiceProvider), and constructor-injecting the service would
 * create a resolution cycle: singleton gateway -> SubscriptionService
 * -> PaymentGateway (the same singleton). Method-level resolution
 * breaks the ring.
 */
final class YooKassaPaymentGateway implements PaymentGateway
{
    private const ENDPOINT_BASE = 'https://api.yookassa.ru/v3';

    private const TIMEOUT = 30;

    /**
     * Create a one-step (capture=true) payment at YooKassa and map the
     * response into the checkout DTO: `id` becomes the external id the
     * subscription is keyed by, `confirmation.confirmation_url` is where
     * the user pays. The `return_url` carries a locally generated UUID
     * that is never stored anywhere — the return path therefore cannot
     * activate anything; activation happens exclusively through the
     * verified webhook.
     *
     * @throws ConnectionException on a network failure (timeout, DNS)
     * @throws RequestException on a provider HTTP error (fail loud, no retries)
     * @throws RuntimeException on a malformed 200 response missing id or confirmation.confirmation_url
     */
    public function createCheckoutSession(User $user, SubscriptionTier $tier): CheckoutSession
    {
        /** @var array{amount_minor: int, currency: string, duration: string} $premium */
        $premium = config('payments.premium');

        $returnUuid = (string) Str::uuid();

        $payload = [
            'amount' => [
                'value' => $this->formatAmount($premium['amount_minor']),
                'currency' => $premium['currency'],
            ],
            'capture' => true,
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => rtrim((string) config('app.url'), '/').'/subscription/checkout/return?session='.rawurlencode($returnUuid),
            ],
            'description' => 'Премиум-подписка it-learns',
        ];

        $startedAt = microtime(true);

        try {
            $json = $this->client()
                ->withHeaders(['Idempotence-Key' => (string) Str::uuid()])
                ->post(self::ENDPOINT_BASE.'/payments', $payload)
                ->throw()
                ->json();

            [$id, $confirmationUrl] = $this->extractCheckoutSession($json);
        } catch (Throwable $exception) {
            $this->logCall('create', null, $startedAt, $exception);

            throw $exception;
        }

        $this->logCall('create', $id, $startedAt);

        return new CheckoutSession(
            url: $confirmationUrl,
            externalId: $id,
            provider: 'yookassa',
        );
    }

    /**
     * Handle a YooKassa notification `{type: "notification", event,
     * object: {...}}`. Foreign events, malformed bodies and a missing
     * object.id are a quiet no-op (mirrors the dummy gateway). The
     * notification body itself is never trusted: the payment is
     * re-fetched through the API, and only a payment personally seen as
     * `succeeded` + `paid` reaches SubscriptionService — both as the
     * session (subscription external_id = payment.id) and as the
     * provider payment id.
     *
     * @throws ConnectionException on a network failure (timeout, DNS)
     * @throws RequestException on a provider HTTP error other than a 404 unknown payment (fail loud)
     */
    public function handleWebhook(Request $request): void
    {
        $payload = $request->json()->all();

        if (! is_array($payload) || ($payload['event'] ?? null) !== 'payment.succeeded') {
            return;
        }

        $object = is_array($payload['object'] ?? null) ? $payload['object'] : null;
        $paymentId = $object !== null ? ($object['id'] ?? null) : null;

        if (! is_string($paymentId) || $paymentId === '') {
            return;
        }

        if (! $this->paymentVerifiedAsSucceeded($paymentId)) {
            return;
        }

        app(SubscriptionService::class)->handlePaymentSucceeded($paymentId, $paymentId);
    }

    /**
     * Refund a previously succeeded payment through the provider. The
     * idempotence key is derived from the local payment id, so retrying
     * the whole refund action after a mid-flight failure reuses the
     * same provider refund instead of moving the money twice.
     * `pending`/`succeeded` mean the refund was accepted; `canceled`
     * fails loud.
     *
     * @throws ConnectionException on a network failure (timeout, DNS)
     * @throws RequestException on a provider HTTP error (fail loud, no retries)
     * @throws RuntimeException when the provider canceled the refund
     */
    public function refund(Payment $payment): bool
    {
        $externalId = (string) $payment->external_id;
        $amount = (int) $payment->amount;

        /** @var string $currency */
        $currency = $payment->currency;

        $payload = [
            'payment_id' => $externalId,
            'amount' => [
                'value' => $this->formatAmount($amount),
                'currency' => $currency,
            ],
        ];

        $startedAt = microtime(true);

        try {
            $json = $this->client()
                ->withHeaders(['Idempotence-Key' => 'refund-'.$payment->getKey()])
                ->post(self::ENDPOINT_BASE.'/refunds', $payload)
                ->throw()
                ->json();
        } catch (Throwable $exception) {
            $this->logCall('refund', $externalId, $startedAt, $exception);

            throw $exception;
        }

        $this->logCall('refund', $externalId, $startedAt);

        $status = is_array($json) ? ($json['status'] ?? null) : null;

        if ($status === 'canceled') {
            throw new RuntimeException("YooKassa refund for payment [{$externalId}] was canceled by the provider");
        }

        return in_array($status, ['pending', 'succeeded'], true);
    }

    /**
     * Re-fetch the payment through the YooKassa API (the webhook body is
     * untrusted) and verify it personally: `status === succeeded` AND
     * `paid === true`. A payment the provider does not know (HTTP 404)
     * is a quiet `false`; every other transport/API error fails loud.
     *
     * @throws ConnectionException on a network failure (timeout, DNS)
     * @throws RequestException on a provider HTTP error other than 404 (fail loud)
     */
    private function paymentVerifiedAsSucceeded(string $paymentId): bool
    {
        $startedAt = microtime(true);

        try {
            $payment = $this->client()
                ->get(self::ENDPOINT_BASE.'/payments/'.$paymentId)
                ->throw()
                ->json();
        } catch (Throwable $exception) {
            $this->logCall('webhook_verify', $paymentId, $startedAt, $exception);

            if ($exception instanceof RequestException && $exception->response->status() === 404) {
                return false;
            }

            throw $exception;
        }

        $this->logCall('webhook_verify', $paymentId, $startedAt);

        return is_array($payment)
            && ($payment['status'] ?? null) === 'succeeded'
            && ($payment['paid'] ?? null) === true;
    }

    /**
     * Extract id + confirmation.confirmation_url from the provider
     * payload, failing loud when the expected path is missing.
     *
     * @param  mixed  $json  decoded response body
     * @return array{0: string, 1: string} [payment id, confirmation url]
     *
     * @throws RuntimeException when the response structure is malformed
     */
    private function extractCheckoutSession(mixed $json): array
    {
        $id = is_array($json) ? ($json['id'] ?? null) : null;
        $confirmation = is_array($json) ? ($json['confirmation'] ?? null) : null;
        $confirmationUrl = is_array($confirmation) ? ($confirmation['confirmation_url'] ?? null) : null;

        if (! is_string($id) || $id === '' || ! is_string($confirmationUrl) || $confirmationUrl === '') {
            throw new RuntimeException('Unexpected YooKassa API response structure: missing id or confirmation.confirmation_url');
        }

        return [$id, $confirmationUrl];
    }

    /**
     * Basic-auth client for the YooKassa API. Credentials are read at
     * call time, not cached in the constructor (the LLM clients'
     * pattern): config changes take effect without re-registering the
     * singleton.
     */
    private function client(): PendingRequest
    {
        /** @var string $shopId */
        $shopId = config('payments.yookassa.shop_id');

        /** @var string $secretKey */
        $secretKey = config('payments.yookassa.secret_key');

        return Http::withBasicAuth($shopId, $secretKey)->timeout(self::TIMEOUT);
    }

    /**
     * Format a minor-unit integer as a decimal string (99900 ->
     * "999.00") using integer arithmetic only — money never touches a
     * float (config/payments.php invariant).
     */
    private function formatAmount(int $minor): string
    {
        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }

    /**
     * Write the shared payments.gateway_call record (the payment-side
     * sibling of ai.llm_call): who was called, for what and how long it
     * took; `error` carries the exception when the call failed.
     *
     * @param  float  $startedAt  microtime of the HTTP call start
     */
    private function logCall(string $action, ?string $paymentId, float $startedAt, ?Throwable $error = null): void
    {
        Log::info('payments.gateway_call', [
            'provider' => 'yookassa',
            'action' => $action,
            'payment_id' => $paymentId,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error' => $error === null ? null : $error->getMessage(),
        ]);
    }
}
