<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    public function test_webhook_activates_subscription_and_records_payment_idempotently(): void
    {
        $user = User::factory()->create();

        // Checkout создаёт Pending-подписку и сессию гейта (dummy).
        $this->actingAs($user)->post('/subscription/checkout');
        /** @var Subscription $subscription */
        $subscription = $user->subscriptions()->latest('id')->first();
        $this->assertNotNull($subscription->external_id);

        $payload = [
            'event' => 'payment.succeeded',
            'session' => $subscription->external_id,
            'provider_payment_id' => 'wh-test-1',
        ];

        $response = $this->postJson('/subscription/webhook', $payload);

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => SubscriptionStatus::Active->value,
        ]);
        $this->assertDatabaseHas('payments', [
            'provider' => 'dummy',
            'external_id' => 'wh-test-1',
            'status' => PaymentStatus::Succeeded->value,
        ]);

        // Повторная доставка того же вебхука не дублирует платёж.
        $this->postJson('/subscription/webhook', $payload);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_webhook_without_csrf_token_is_accepted_and_exempt_from_csrf(): void
    {
        // POST уходит без _token/X-XSRF-TOKEN: в тестах CSRF и так пропускается
        // (runningUnitTests), поэтому дополнительно проверяем сам wiring —
        // маршрут числится в CSRF-except списке из bootstrap/app.php.
        $response = $this->postJson('/subscription/webhook', [
            'event' => 'payment.succeeded',
            'session' => 'unknown-session',
            'provider_payment_id' => 'wh-test-2',
        ]);

        $response->assertOk();

        $this->assertContains(
            'subscription/webhook',
            app(PreventRequestForgery::class)->getExcludedPaths(),
        );
    }
}
