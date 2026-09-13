<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\CoursePolicy;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CoursePolicyTest extends TestCase
{
    private CoursePolicy $policy;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');

        $this->policy = new CoursePolicy;
        $this->admin = User::factory()->admin()->create();
        $this->user = User::factory()->create();
    }

    public function test_view_any_denies_admin(): void
    {
        $this->assertFalse($this->policy->viewAny($this->admin));
    }

    public function test_view_any_denies_user(): void
    {
        $this->assertFalse($this->policy->viewAny($this->user));
    }

    public function test_view_any_denies_guest(): void
    {
        $this->assertFalse($this->policy->viewAny(null));
    }

    public function test_view_denies_admin(): void
    {
        $this->assertFalse($this->policy->view($this->admin, null));
    }

    public function test_view_denies_user(): void
    {
        $this->assertFalse($this->policy->view($this->user, null));
    }

    public function test_view_denies_guest(): void
    {
        $this->assertFalse($this->policy->view(null, null));
    }

    public function test_create_denies_admin(): void
    {
        $this->assertFalse($this->policy->create($this->admin));
    }

    public function test_create_denies_user(): void
    {
        $this->assertFalse($this->policy->create($this->user));
    }

    public function test_create_denies_guest(): void
    {
        $this->assertFalse($this->policy->create(null));
    }

    public function test_update_denies_admin(): void
    {
        $this->assertFalse($this->policy->update($this->admin, null));
    }

    public function test_update_denies_user(): void
    {
        $this->assertFalse($this->policy->update($this->user, null));
    }

    public function test_update_denies_guest(): void
    {
        $this->assertFalse($this->policy->update(null, null));
    }

    public function test_delete_denies_admin(): void
    {
        $this->assertFalse($this->policy->delete($this->admin, null));
    }

    public function test_delete_denies_user(): void
    {
        $this->assertFalse($this->policy->delete($this->user, null));
    }

    public function test_delete_denies_guest(): void
    {
        $this->assertFalse($this->policy->delete(null, null));
    }
}
