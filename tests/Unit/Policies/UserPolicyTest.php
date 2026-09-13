<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    private UserPolicy $policy;

    private User $admin;

    private User $otherAdmin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');

        $this->policy = new UserPolicy;
        $this->admin = User::factory()->admin()->create();
        $this->otherAdmin = User::factory()->admin()->create();
        $this->user = User::factory()->create();
    }

    public function test_view_any_allows_admin(): void
    {
        $this->assertTrue($this->policy->viewAny($this->admin));
    }

    public function test_view_any_denies_user(): void
    {
        $this->assertFalse($this->policy->viewAny($this->user));
    }

    public function test_view_allows_admin_for_any_user(): void
    {
        $this->assertTrue($this->policy->view($this->admin, $this->user));
        $this->assertTrue($this->policy->view($this->admin, $this->otherAdmin));
    }

    public function test_view_denies_user(): void
    {
        $this->assertFalse($this->policy->view($this->user, $this->admin));
    }

    public function test_update_allows_admin_for_other_user(): void
    {
        $this->assertTrue($this->policy->update($this->otherAdmin, $this->user));
    }

    public function test_update_denies_admin_for_self(): void
    {
        $this->assertFalse($this->policy->update($this->admin, $this->admin));
    }

    public function test_update_denies_user(): void
    {
        $this->assertFalse($this->policy->update($this->user, $this->admin));
    }

    public function test_change_role_allows_admin_for_other_user(): void
    {
        $this->assertTrue($this->policy->changeRole($this->admin, $this->user));
    }

    public function test_change_role_denies_self(): void
    {
        $this->assertFalse($this->policy->changeRole($this->admin, $this->admin));
    }

    public function test_change_role_denies_user(): void
    {
        $this->assertFalse($this->policy->changeRole($this->user, $this->user));
    }

    public function test_block_allows_admin_for_other_user(): void
    {
        $this->assertTrue($this->policy->block($this->otherAdmin, $this->user));
    }

    public function test_block_denies_self(): void
    {
        $this->assertFalse($this->policy->block($this->admin, $this->admin));
    }

    public function test_block_denies_user(): void
    {
        $this->assertFalse($this->policy->block($this->user, $this->admin));
    }

    public function test_unblock_allows_admin_for_other_user(): void
    {
        $this->assertTrue($this->policy->unblock($this->otherAdmin, $this->user));
    }

    public function test_unblock_denies_self(): void
    {
        $this->assertFalse($this->policy->unblock($this->admin, $this->admin));
    }

    public function test_delete_allows_admin_for_other_user(): void
    {
        $this->assertTrue($this->policy->delete($this->otherAdmin, $this->user));
    }

    public function test_delete_denies_self(): void
    {
        $this->assertFalse($this->policy->delete($this->admin, $this->admin));
    }

    public function test_gate_resolves_user_policy_by_convention(): void
    {
        $this->assertInstanceOf(UserPolicy::class, Gate::getPolicyFor(User::class));
    }
}
