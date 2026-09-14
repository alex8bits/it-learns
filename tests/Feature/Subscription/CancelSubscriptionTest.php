<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use Tests\TestCase;

class CancelSubscriptionTest extends TestCase
{
    public function test_premium_user_can_cancel_active_subscription(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->active()->create(['user_id' => $user]);

        $response = $this->actingAs($user)->post('/subscription/cancel');

        $response->assertRedirect(route('subscription'));
        $response->assertSessionHas('status', 'Подписка отменена. Доступ сохранится до конца оплаченного периода.');

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => SubscriptionStatus::Cancelled->value,
        ]);
    }
}
