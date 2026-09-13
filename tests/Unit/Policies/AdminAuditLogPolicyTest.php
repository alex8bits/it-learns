<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Policies\AdminAuditLogPolicy;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAuditLogPolicyTest extends TestCase
{
    private AdminAuditLogPolicy $policy;

    private User $admin;

    private User $user;

    private AdminAuditLog $log;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');

        $this->policy = new AdminAuditLogPolicy;
        $this->admin = User::factory()->admin()->create();
        $this->user = User::factory()->create();

        $this->log = new AdminAuditLog([
            'action' => AdminAuditAction::UserRoleChanged,
            'subject_type' => (new User)->getMorphClass(),
            'subject_id' => $this->user->id,
        ]);
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
        $this->assertTrue($this->policy->view($this->admin, $this->log));
    }

    public function test_view_denies_user(): void
    {
        $this->assertFalse($this->policy->view($this->user, $this->log));
    }

    public function test_create_update_delete_are_denied_for_admin(): void
    {
        $this->assertFalse(Gate::forUser($this->admin)->check('create', $this->log));
        $this->assertFalse(Gate::forUser($this->admin)->check('update', $this->log));
        $this->assertFalse(Gate::forUser($this->admin)->check('delete', $this->log));
    }

    public function test_gate_resolves_admin_audit_log_policy_by_convention(): void
    {
        $this->assertInstanceOf(AdminAuditLogPolicy::class, Gate::getPolicyFor(AdminAuditLog::class));
    }
}
