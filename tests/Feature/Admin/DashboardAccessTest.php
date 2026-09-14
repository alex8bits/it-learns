<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_admin_gets_200(): void
    {
        $admin = User::factory()->admin()->create();
        // The payment is attached to the same subscription so the nested
        // factory default (an in-force Active subscription) does not
        // inflate `premium_active` to 2.
        $subscription = Subscription::factory()->create();
        Payment::factory()->succeeded()->create(['subscription_id' => $subscription->id]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboard')
            ->where('counters.admins_total', 1)
            ->where('counters.premium_active', 1)
            ->where('counters.payments_month', 1));
    }
}
