<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_admin_sees_payments_list(): void
    {
        $admin = User::factory()->admin()->create();
        $payer = User::factory()->create(['email' => 'payer@example.com']);
        Payment::factory()->count(3)->create(['user_id' => $payer->id]);

        $response = $this->actingAs($admin)->get(route('admin.payments.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Payments/Index')
            ->has('payments.data', 3)
            ->has('statuses'));
    }

    public function test_non_admin_gets_403(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('admin.payments.index'));

        $response->assertForbidden();
    }
}
