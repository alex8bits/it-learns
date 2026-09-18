<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Providers\AppServiceProvider;
use App\Services\Payments\DummyPaymentGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\YooKassaPaymentGateway;
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

    public function test_binds_yookassa_gateway_from_config(): void
    {
        config([
            'payments.provider' => 'yookassa',
            'payments.yookassa.shop_id' => '900001',
            'payments.yookassa.secret_key' => 'live_secret_key',
        ]);
        $this->app->forgetInstance(PaymentGateway::class);

        (new AppServiceProvider($this->app))->register();

        /** @var YooKassaPaymentGateway $gateway */
        $gateway = app(PaymentGateway::class);

        $this->assertInstanceOf(YooKassaPaymentGateway::class, $gateway);
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

    public function test_yookassa_provider_without_credentials_fails_loud_on_rebind(): void
    {
        config([
            'payments.provider' => 'yookassa',
            'payments.yookassa.shop_id' => null,
            'payments.yookassa.secret_key' => null,
        ]);
        $this->app->forgetInstance(PaymentGateway::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Payment gateway [yookassa] requires YOOKASSA_SHOP_ID and YOOKASSA_SECRET_KEY');

        (new AppServiceProvider($this->app))->register();
    }
}
