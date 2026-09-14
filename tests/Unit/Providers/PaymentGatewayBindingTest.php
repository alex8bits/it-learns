<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Providers\AppServiceProvider;
use App\Services\Payments\DummyPaymentGateway;
use App\Services\Payments\PaymentGateway;
use RuntimeException;
use Tests\TestCase;

class PaymentGatewayBindingTest extends TestCase
{
    public function test_binds_dummy_gateway_from_config(): void
    {
        config(['payments.provider' => 'dummy']);

        $gateway = app(PaymentGateway::class);

        $this->assertInstanceOf(DummyPaymentGateway::class, $gateway);
        $this->assertSame($gateway, app(PaymentGateway::class));
    }

    public function test_unknown_provider_fails_loud_on_rebind(): void
    {
        config(['payments.provider' => 'bogus']);
        $this->app->forgetInstance(PaymentGateway::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Payment gateway [bogus] is not whitelisted in config/payments.php');

        (new AppServiceProvider($this->app))->register();
    }
}
