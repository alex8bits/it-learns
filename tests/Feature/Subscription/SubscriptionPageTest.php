<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Models\Subscription;
use App\Models\User;
use Tests\TestCase;

class SubscriptionPageTest extends TestCase
{
    public function test_authenticated_user_sees_premium_status_on_subscription_page(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->active()->create(['user_id' => $user]);

        $response = $this->actingAs($user)->get('/subscription');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Subscription/Index')
            ->where('isPremium', true)
            // Narrow shape only: exact match catches a regression to the raw
            // model (which would expose extra keys). 'Active' is the backing
            // value of SubscriptionStatus::Active set by the factory state.
            ->where('lastSubscription', ['status' => 'Active']));
    }
}
