<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Providers\AppServiceProvider;
use App\Services\Payments\PaymentGateway;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature-smoke реального HTTP-роута вебхука с активным провайдером
 * `yookassa` (Этап 9): уведомление верифицируется гейтом re-fetch'ем
 * через API (Http::fake), активация — SubscriptionService. Глубина
 * веток гейта покрыта Unit-тестом YooKassaPaymentGatewayTest; здесь
 * только сквозной happy-path, идемпотентность повторной доставки и
 * неверифицируемое уведомление (правило №15 — Feature = smoke).
 */
class YooKassaWebhookTest extends TestCase
{
    public function test_webhook_activates_pending_subscription_and_records_payment(): void
    {
        $this->useYooKassaGateway();

        $subscription = Subscription::factory()->pending()->create([
            'provider' => 'yookassa',
            'external_id' => 'pt-1',
        ]);

        Http::fake([
            'https://api.yookassa.ru/v3/payments/pt-1' => Http::response([
                'id' => 'pt-1',
                'status' => 'succeeded',
                'paid' => true,
            ]),
        ]);

        $response = $this->postJson('/subscription/webhook', [
            'type' => 'notification',
            'event' => 'payment.succeeded',
            'object' => [
                'id' => 'pt-1',
                'status' => 'succeeded',
                'paid' => true,
            ],
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => SubscriptionStatus::Active->value,
        ]);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', [
            'provider' => 'yookassa',
            'external_id' => 'pt-1',
            'status' => PaymentStatus::Succeeded->value,
        ]);
    }

    public function test_repeated_webhook_delivery_does_not_duplicate_payment_or_extend_the_period(): void
    {
        $this->useYooKassaGateway();

        $subscription = Subscription::factory()->pending()->create([
            'provider' => 'yookassa',
            'external_id' => 'pt-1',
        ]);

        Http::fake([
            'https://api.yookassa.ru/v3/payments/pt-1' => Http::response([
                'id' => 'pt-1',
                'status' => 'succeeded',
                'paid' => true,
            ]),
        ]);

        $payload = [
            'type' => 'notification',
            'event' => 'payment.succeeded',
            'object' => [
                'id' => 'pt-1',
                'status' => 'succeeded',
                'paid' => true,
            ],
        ];

        $this->postJson('/subscription/webhook', $payload)->assertOk();

        /** @var CarbonInterface|null $endsAt */
        $endsAt = $subscription->refresh()->ends_at;
        $this->assertNotNull($endsAt);

        // Повторная доставка того же уведомления: re-fetch снова
        // «succeeded», но платёж не дублируется (unique provider +
        // external_id + firstOrCreate), а оплаченный период не
        // продлевается второй раз.
        $this->postJson('/subscription/webhook', $payload)->assertOk();

        $this->assertDatabaseCount('payments', 1);

        /** @var CarbonInterface|null $endsAtAfterRedelivery */
        $endsAtAfterRedelivery = $subscription->refresh()->ends_at;
        $this->assertTrue($endsAt->equalTo($endsAtAfterRedelivery));
    }

    public function test_notification_refuted_by_refetch_is_a_quiet_no_op(): void
    {
        $this->useYooKassaGateway();

        $subscription = Subscription::factory()->pending()->create([
            'provider' => 'yookassa',
            'external_id' => 'pt-1',
        ]);

        // Тело уведомления не доверяется: оно утверждает успех, но
        // re-fetch через API опровергает — активации быть не должно.
        Http::fake([
            'https://api.yookassa.ru/v3/payments/pt-1' => Http::response([
                'id' => 'pt-1',
                'status' => 'pending',
                'paid' => false,
            ]),
        ]);

        $response = $this->postJson('/subscription/webhook', [
            'type' => 'notification',
            'event' => 'payment.succeeded',
            'object' => [
                'id' => 'pt-1',
                'status' => 'succeeded',
                'paid' => true,
            ],
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => SubscriptionStatus::Pending->value,
        ]);
        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * Переключить контейнер на боевой гейт ЮKassa. Синглтон
     * PaymentGateway связан на boot по PAYMENT_PROVIDER (в тестовом
     * окружении — dummy), поэтому после override конфига перерегистрируем
     * AppServiceProvider — тот же паттерн, что в
     * PaymentGatewayBindingTest.
     */
    private function useYooKassaGateway(): void
    {
        config([
            'payments.provider' => 'yookassa',
            'payments.yookassa.shop_id' => 'test-shop',
            'payments.yookassa.secret_key' => 'test-secret',
        ]);

        $this->app->forgetInstance(PaymentGateway::class);
        (new AppServiceProvider($this->app))->register();
    }
}
