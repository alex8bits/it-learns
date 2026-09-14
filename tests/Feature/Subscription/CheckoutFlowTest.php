<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Tests\TestCase;

class CheckoutFlowTest extends TestCase
{
    public function test_checkout_redirects_to_gateway_and_return_activates_subscription(): void
    {
        $user = User::factory()->create();

        $checkout = $this->actingAs($user)->post('/subscription/checkout');

        $checkout->assertRedirect();
        $location = (string) $checkout->headers->get('Location');
        $this->assertStringContainsString('/subscription/checkout/return?session=', $location);

        $return = $this->actingAs($user)->get($location);

        $return->assertOk();
        $return->assertInertia(fn ($page) => $page
            ->component('Subscription/CheckoutReturn')
            ->where('activated', true));

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'status' => SubscriptionStatus::Active->value,
        ]);
        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'status' => PaymentStatus::Succeeded->value,
        ]);
        $this->assertTrue(app(SubscriptionService::class)->isActive($user));
    }

    public function test_in_force_premium_user_is_redirected_back_without_second_checkout(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->active()->create(['user_id' => $user]);

        $response = $this->actingAs($user)->post('/subscription/checkout');

        $response->assertRedirect(route('subscription'));
        $response->assertSessionHas('status', 'У вас уже есть действующая подписка.');
    }
}
