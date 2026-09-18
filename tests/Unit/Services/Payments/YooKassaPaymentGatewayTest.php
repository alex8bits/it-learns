<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payments;

use App\Enums\SubscriptionTier;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\YooKassaPaymentGateway;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Unit coverage of the production YooKassa gateway: the outgoing HTTP
 * contract (Basic auth, Idempotence-Key headers, payload shape), the
 * webhook trust model (re-fetch verification — the notification body
 * is never trusted) and the refund status mapping. The YooKassa API is
 * faked with Http::fake(); SubscriptionService is mocked and bound
 * into the container (the gateway resolves it there, cycle-broken).
 */
class YooKassaPaymentGatewayTest extends TestCase
{
    private const SHOP_ID = '900001';

    private const SECRET_KEY = 'test_secret_key';

    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private YooKassaPaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://it-learns.test',
            'payments.yookassa.shop_id' => self::SHOP_ID,
            'payments.yookassa.secret_key' => self::SECRET_KEY,
        ]);

        $this->gateway = new YooKassaPaymentGateway;
    }

    public function test_create_checkout_session_sends_yookassa_payment_contract_and_maps_the_response(): void
    {
        Http::fake([
            'https://api.yookassa.ru/v3/payments' => Http::response([
                'id' => 'pt-2hw1r8',
                'status' => 'pending',
                'confirmation' => [
                    'type' => 'redirect',
                    'confirmation_url' => 'https://yoomoney.ru/checkout/payments/v2/contract?orderId=2hw1r8',
                ],
            ]),
        ]);

        $session = $this->gateway->createCheckoutSession(new User, SubscriptionTier::Premium);

        $this->assertSame('pt-2hw1r8', $session->externalId);
        $this->assertSame('https://yoomoney.ru/checkout/payments/v2/contract?orderId=2hw1r8', $session->url);
        $this->assertSame('yookassa', $session->provider);

        Http::assertSent(function (HttpClientRequest $request): bool {
            if ($request->method() !== 'POST' || $request->url() !== 'https://api.yookassa.ru/v3/payments') {
                return false;
            }

            $body = $request->data();

            return ($request->header('Authorization')[0] ?? '') === 'Basic '.base64_encode(self::SHOP_ID.':'.self::SECRET_KEY)
                && preg_match(self::UUID_V4, (string) ($request->header('Idempotence-Key')[0] ?? '')) === 1
                && ($body['amount']['value'] ?? null) === '999.00'
                && ($body['amount']['currency'] ?? null) === 'RUB'
                && ($body['capture'] ?? null) === true
                && ($body['confirmation']['type'] ?? null) === 'redirect'
                && preg_match('#^https://it-learns\.test/subscription/checkout/return\?session=[0-9a-f-]{36}$#', (string) ($body['confirmation']['return_url'] ?? '')) === 1
                && ($body['description'] ?? null) === 'Премиум-подписка it-learns';
        });
    }

    public function test_create_checkout_session_fails_loud_on_http_error(): void
    {
        Http::fake([
            'https://api.yookassa.ru/v3/payments' => Http::response(['description' => 'account not found'], 403),
        ]);

        $this->expectException(RequestException::class);

        $this->gateway->createCheckoutSession(new User, SubscriptionTier::Premium);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('malformedCheckoutResponseProvider')]
    public function test_create_checkout_session_fails_loud_on_malformed_response(array $body): void
    {
        Http::fake([
            'https://api.yookassa.ru/v3/payments' => Http::response($body),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected YooKassa API response structure');

        $this->gateway->createCheckoutSession(new User, SubscriptionTier::Premium);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function malformedCheckoutResponseProvider(): array
    {
        return [
            'empty object' => [[]],
            'id without confirmation' => [['id' => 'pt-1']],
            'confirmation without id' => [['confirmation' => ['confirmation_url' => 'https://yoomoney.ru/c']]],
            'confirmation url not a string' => [['id' => 'pt-1', 'confirmation' => ['confirmation_url' => 42]]],
        ];
    }

    public function test_handle_webhook_verifies_via_refetch_and_notifies_the_service(): void
    {
        $service = Mockery::mock(SubscriptionService::class);
        $service->shouldReceive('handlePaymentSucceeded')->once()->with('pt-77', 'pt-77');
        $this->instance(SubscriptionService::class, $service);

        Http::fake([
            'https://api.yookassa.ru/v3/payments/pt-77' => Http::response([
                'id' => 'pt-77',
                'status' => 'succeeded',
                'paid' => true,
            ]),
        ]);

        // The notification body claims the opposite of the provider
        // state: it must not be trusted, only the re-fetched payment.
        $this->gateway->handleWebhook($this->notificationRequest('pt-77', objectState: [
            'status' => 'pending',
            'paid' => false,
        ]));

        Http::assertSent(function (HttpClientRequest $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://api.yookassa.ru/v3/payments/pt-77'
                && ($request->header('Authorization')[0] ?? '') === 'Basic '.base64_encode(self::SHOP_ID.':'.self::SECRET_KEY);
        });
    }

    #[DataProvider('unverifiedPaymentStateProvider')]
    public function test_handle_webhook_ignores_payment_not_verified_as_succeeded_and_paid(string $status, bool $paid): void
    {
        $service = Mockery::mock(SubscriptionService::class);
        $service->shouldReceive('handlePaymentSucceeded')->never();
        $this->instance(SubscriptionService::class, $service);

        Http::fake([
            'https://api.yookassa.ru/v3/payments/*' => Http::response([
                'id' => 'pt-77',
                'status' => $status,
                'paid' => $paid,
            ]),
        ]);

        $this->gateway->handleWebhook($this->notificationRequest('pt-77'));

        // The re-fetch still happened — the decision came from the API,
        // not from a skipped verification.
        Http::assertSent(fn (HttpClientRequest $request): bool => $request->method() === 'GET');
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function unverifiedPaymentStateProvider(): array
    {
        return [
            'still pending' => ['pending', true],
            'succeeded but unpaid' => ['succeeded', false],
            'canceled' => ['canceled', true],
        ];
    }

    public function test_handle_webhook_is_silent_noop_when_payment_is_unknown_at_provider(): void
    {
        $service = Mockery::mock(SubscriptionService::class);
        $service->shouldReceive('handlePaymentSucceeded')->never();
        $this->instance(SubscriptionService::class, $service);

        Http::fake([
            'https://api.yookassa.ru/v3/payments/*' => Http::response(['description' => 'Not found'], 404),
        ]);

        $this->gateway->handleWebhook($this->notificationRequest('pt-missing'));

        Http::assertSent(fn (HttpClientRequest $request): bool => $request->method() === 'GET');
    }

    public function test_handle_webhook_fails_loud_on_verification_http_error(): void
    {
        $service = Mockery::mock(SubscriptionService::class);
        $service->shouldReceive('handlePaymentSucceeded')->never();
        $this->instance(SubscriptionService::class, $service);

        Http::fake([
            'https://api.yookassa.ru/v3/payments/*' => Http::response([], 500),
        ]);

        $this->expectException(RequestException::class);

        $this->gateway->handleWebhook($this->notificationRequest('pt-77'));
    }

    public function test_handle_webhook_with_foreign_event_makes_no_http_calls_and_notifies_nobody(): void
    {
        $service = Mockery::mock(SubscriptionService::class);
        $service->shouldReceive('handlePaymentSucceeded')->never();
        $this->instance(SubscriptionService::class, $service);

        Http::fake();

        $this->gateway->handleWebhook($this->notificationRequest('pt-77', event: 'payment.canceled'));

        Http::assertNothingSent();
    }

    #[DataProvider('malformedNotificationProvider')]
    public function test_handle_webhook_with_malformed_notification_makes_no_http_calls(string $body): void
    {
        $service = Mockery::mock(SubscriptionService::class);
        $service->shouldReceive('handlePaymentSucceeded')->never();
        $this->instance(SubscriptionService::class, $service);

        Http::fake();

        $this->gateway->handleWebhook($this->webhookRequest($body));

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedNotificationProvider(): array
    {
        return [
            'empty body' => [''],
            'not json' => ['not json at all'],
            'missing event' => [(string) json_encode(['object' => ['id' => 'pt-77']])],
            'missing object' => [(string) json_encode(['event' => 'payment.succeeded'])],
            'object id not a string' => [(string) json_encode(['event' => 'payment.succeeded', 'object' => ['id' => 123]])],
            'object id empty' => [(string) json_encode(['event' => 'payment.succeeded', 'object' => ['id' => '']])],
        ];
    }

    #[DataProvider('acceptedRefundStatusProvider')]
    public function test_refund_sends_refund_contract_and_returns_true_on_provider_acceptance(string $status): void
    {
        $payment = Payment::factory()->create([
            'external_id' => 'pt-99',
            'provider' => 'yookassa',
            'amount' => 99900,
            'currency' => 'RUB',
        ]);

        Http::fake([
            'https://api.yookassa.ru/v3/refunds' => Http::response(['id' => 'rf-1', 'status' => $status]),
        ]);

        $this->assertTrue($this->gateway->refund($payment));

        Http::assertSent(function (HttpClientRequest $request) use ($payment): bool {
            if ($request->method() !== 'POST' || $request->url() !== 'https://api.yookassa.ru/v3/refunds') {
                return false;
            }

            $body = $request->data();

            return ($request->header('Authorization')[0] ?? '') === 'Basic '.base64_encode(self::SHOP_ID.':'.self::SECRET_KEY)
                && ($request->header('Idempotence-Key')[0] ?? null) === 'refund-'.$payment->getKey()
                && ($body['payment_id'] ?? null) === 'pt-99'
                && ($body['amount']['value'] ?? null) === '999.00'
                && ($body['amount']['currency'] ?? null) === 'RUB';
        });
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedRefundStatusProvider(): array
    {
        return [
            'pending' => ['pending'],
            'succeeded' => ['succeeded'],
        ];
    }

    #[DataProvider('minorAmountProvider')]
    public function test_amounts_are_formatted_from_minor_units_without_float(int $minor, string $expected): void
    {
        $payment = Payment::factory()->create([
            'external_id' => 'pt-amt',
            'provider' => 'yookassa',
            'amount' => $minor,
            'currency' => 'RUB',
        ]);

        Http::fake([
            'https://api.yookassa.ru/v3/refunds' => Http::response(['id' => 'rf-1', 'status' => 'succeeded']),
        ]);

        $this->assertTrue($this->gateway->refund($payment));

        Http::assertSent(fn (HttpClientRequest $request): bool => ($request->data()['amount']['value'] ?? null) === $expected);
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function minorAmountProvider(): array
    {
        return [
            '999.00' => [99900, '999.00'],
            '10.05' => [1005, '10.05'],
            '0.05' => [5, '0.05'],
        ];
    }

    public function test_refund_canceled_by_provider_fails_loud(): void
    {
        $payment = Payment::factory()->create([
            'external_id' => 'pt-99',
            'provider' => 'yookassa',
        ]);

        Http::fake([
            'https://api.yookassa.ru/v3/refunds' => Http::response(['id' => 'rf-1', 'status' => 'canceled']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('YooKassa refund for payment [pt-99] was canceled by the provider');

        $this->gateway->refund($payment);
    }

    public function test_refund_fails_loud_on_http_error(): void
    {
        $payment = Payment::factory()->create([
            'external_id' => 'pt-99',
            'provider' => 'yookassa',
        ]);

        Http::fake([
            'https://api.yookassa.ru/v3/refunds' => Http::response([], 500),
        ]);

        $this->expectException(RequestException::class);

        $this->gateway->refund($payment);
    }

    public function test_create_call_is_logged(): void
    {
        Log::shouldReceive('info')->once()->with('payments.gateway_call', Mockery::on(function (array $context): bool {
            return $context['provider'] === 'yookassa'
                && $context['action'] === 'create'
                && $context['payment_id'] === 'pt-log'
                && is_int($context['duration_ms'])
                && $context['error'] === null;
        }));

        Http::fake([
            'https://api.yookassa.ru/v3/payments' => Http::response([
                'id' => 'pt-log',
                'confirmation' => ['confirmation_url' => 'https://yoomoney.ru/c'],
            ]),
        ]);

        $this->gateway->createCheckoutSession(new User, SubscriptionTier::Premium);
    }

    public function test_webhook_verification_call_is_logged(): void
    {
        Log::shouldReceive('info')->once()->with('payments.gateway_call', Mockery::on(function (array $context): bool {
            return $context['provider'] === 'yookassa'
                && $context['action'] === 'webhook_verify'
                && $context['payment_id'] === 'pt-77'
                && is_int($context['duration_ms'])
                && $context['error'] === null;
        }));

        $service = Mockery::mock(SubscriptionService::class);
        $service->shouldReceive('handlePaymentSucceeded')->once()->with('pt-77', 'pt-77');
        $this->instance(SubscriptionService::class, $service);

        Http::fake([
            'https://api.yookassa.ru/v3/payments/pt-77' => Http::response([
                'id' => 'pt-77',
                'status' => 'succeeded',
                'paid' => true,
            ]),
        ]);

        $this->gateway->handleWebhook($this->notificationRequest('pt-77'));
    }

    public function test_refund_call_is_logged(): void
    {
        Log::shouldReceive('info')->once()->with('payments.gateway_call', Mockery::on(function (array $context): bool {
            return $context['provider'] === 'yookassa'
                && $context['action'] === 'refund'
                && $context['payment_id'] === 'pt-99'
                && is_int($context['duration_ms'])
                && $context['error'] === null;
        }));

        $payment = Payment::factory()->create([
            'external_id' => 'pt-99',
            'provider' => 'yookassa',
        ]);

        Http::fake([
            'https://api.yookassa.ru/v3/refunds' => Http::response(['id' => 'rf-1', 'status' => 'succeeded']),
        ]);

        $this->gateway->refund($payment);
    }

    /**
     * Build a YooKassa notification body for the given payment id.
     *
     * @param  array<string, mixed>  $objectState  extra object fields (must not be trusted by the gateway)
     */
    private function notificationRequest(string $paymentId, string $event = 'payment.succeeded', array $objectState = []): Request
    {
        return $this->webhookRequest((string) json_encode([
            'type' => 'notification',
            'event' => $event,
            'object' => ['id' => $paymentId, ...$objectState],
        ]));
    }

    /**
     * Build a POST request with the given raw body and a JSON content
     * type, the way the webhook route receives provider callbacks.
     */
    private function webhookRequest(string $content): Request
    {
        return Request::create('/subscription/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $content);
    }
}
