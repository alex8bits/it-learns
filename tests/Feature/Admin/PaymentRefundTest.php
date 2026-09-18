<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentRefundTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_admin_refunds_succeeded_payment_and_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        $payment = Payment::factory()->succeeded()->create();

        $response = $this->actingAs($admin)->post(route('admin.payments.refund', $payment));

        $response->assertRedirect();
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::PaymentRefunded->value,
            'admin_id' => $admin->id,
            'subject_id' => $payment->id,
        ]);
    }

    public function test_non_admin_gets_403(): void
    {
        $user = User::factory()->create();
        $payment = Payment::factory()->succeeded()->create();

        $response = $this->actingAs($user)->post(route('admin.payments.refund', $payment));

        $response->assertForbidden();
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
    }
}
