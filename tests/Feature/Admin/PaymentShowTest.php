<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentShowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_admin_opens_payment_card(): void
    {
        $admin = User::factory()->admin()->create();
        $payment = Payment::factory()->create([
            'payload' => ['operation' => 'pay', 'status' => 'success'],
        ]);

        $response = $this->actingAs($admin)->get(route('admin.payments.show', $payment));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Payments/Show')
            ->where('payment.id', $payment->id)
            ->has('payment.payload'));
    }
}
