<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use Tests\TestCase;

class PricingTest extends TestCase
{
    public function test_guest_can_view_pricing_page(): void
    {
        $response = $this->get('/pricing');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Pricing')
            ->where('premium.amount_minor', 99900)
            ->where('premium.currency', 'RUB'));
    }
}
