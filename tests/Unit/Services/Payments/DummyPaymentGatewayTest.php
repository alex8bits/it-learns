<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payments;

use App\Enums\SubscriptionTier;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\CheckoutSession;
use App\Services\Payments\DummyPaymentGateway;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class DummyPaymentGatewayTest extends TestCase
{
    private DummyPaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new DummyPaymentGateway;
    }

    public function test_creates_checkout_session_dto_for_premium_tier(): void
    {
        $session = $this->gateway->createCheckoutSession(new User, SubscriptionTier::Premium);

        $this->assertInstanceOf(CheckoutSession::class, $session);
        $this->assertSame('dummy', $session->provider);
    }

    public function test_external_id_is_a_valid_uuid_v4(): void
    {
        $session = $this->gateway->createCheckoutSession(new User, SubscriptionTier::Premium);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $session->externalId,
        );
    }

    public function test_external_id_is_unique_between_calls(): void
    {
        $first = $this->gateway->createCheckoutSession(new User, SubscriptionTier::Premium);
        $second = $this->gateway->createCheckoutSession(new User, SubscriptionTier::Premium);

        $this->assertNotSame($first->externalId, $second->externalId);
    }

    public function test_url_is_the_return_url_with_urlencoded_session_id(): void
    {
        $session = $this->gateway->createCheckoutSession(new User, SubscriptionTier::Premium);

        $this->assertSame(
            '/subscription/checkout/return?session='.rawurlencode($session->externalId),
            $session->url,
        );
    }

    public function test_refund_of_any_payment_returns_true(): void
    {
        $payment = new Payment;

        $this->assertTrue($this->gateway->refund($payment));
    }

    public function test_handle_webhook_does_not_throw(): void
    {
        $request = Request::create('/subscription/webhook', 'POST');

        $this->gateway->handleWebhook($request);

        $this->expectNotToPerformAssertions();
    }
}
