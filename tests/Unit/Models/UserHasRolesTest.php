<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

class UserHasRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_uses_has_roles_trait(): void
    {
        $traits = class_uses_recursive(User::class);

        $this->assertContains(HasRoles::class, $traits);
    }

    public function test_user_has_assign_role_method(): void
    {
        $this->assertTrue((new ReflectionClass(User::class))->hasMethod('assignRole'));
    }

    public function test_user_has_has_role_method(): void
    {
        $this->assertTrue((new ReflectionClass(User::class))->hasMethod('hasRole'));
    }

    public function test_user_has_has_permission_to_method(): void
    {
        $this->assertTrue((new ReflectionClass(User::class))->hasMethod('hasPermissionTo'));
    }

    public function test_user_has_role_returns_false_for_nonexistent_role(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasRole('nonexistent'));
    }
}
