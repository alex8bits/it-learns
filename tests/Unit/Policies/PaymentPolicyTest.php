<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\User;
use App\Policies\PaymentPolicy;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentPolicyTest extends TestCase
{
    private PaymentPolicy $policy;

    private User $admin;

    private User $user;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');

        $this->policy = new PaymentPolicy;
        $this->admin = User::factory()->admin()->create();
        $this->user = User::factory()->create();

        // An unsaved instance is enough: `view` checks the acting user's
        // role only and never reads payment attributes.
        $this->payment = new Payment;
    }

    public function test_view_any_allows_admin(): void
    {
        $this->assertTrue($this->policy->viewAny($this->admin));
    }

    public function test_view_any_denies_user(): void
    {
        $this->assertFalse($this->policy->viewAny($this->user));
    }

    public function test_view_allows_admin(): void
    {
        $this->assertTrue($this->policy->view($this->admin, $this->payment));
    }

    public function test_view_denies_user(): void
    {
        $this->assertFalse($this->policy->view($this->user, $this->payment));
    }

    public function test_refund_allows_admin(): void
    {
        $this->assertTrue($this->policy->refund($this->admin, $this->payment));
    }

    public function test_refund_denies_user(): void
    {
        $this->assertFalse($this->policy->refund($this->user, $this->payment));
    }

    public function test_create_update_delete_are_denied_for_admin(): void
    {
        $this->assertFalse(Gate::forUser($this->admin)->check('create', $this->payment));
        $this->assertFalse(Gate::forUser($this->admin)->check('update', $this->payment));
        $this->assertFalse(Gate::forUser($this->admin)->check('delete', $this->payment));
    }

    public function test_gate_resolves_payment_policy_by_convention(): void
    {
        $this->assertInstanceOf(PaymentPolicy::class, Gate::getPolicyFor(Payment::class));
    }
}
