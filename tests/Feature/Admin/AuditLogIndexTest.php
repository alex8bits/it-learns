<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Admin\BlockUser;
use App\Actions\Admin\ChangeUserRole;
use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditLogIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_admin_sees_audit_logs(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $this->actingAs($admin);
        app(ChangeUserRole::class)->execute($user, UserRole::Admin, $admin);

        $response = $this->actingAs($admin)->get(route('admin.audit-logs.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLogs/Index')
            ->has('logs.data', 1)
            ->where('logs.data.0.action', AdminAuditAction::UserRoleChanged->value));
    }

    public function test_user_gets_403_on_audit_logs(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('admin.audit-logs.index'));

        $response->assertForbidden();
    }

    public function test_filter_by_action(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $this->actingAs($admin);
        app(ChangeUserRole::class)->execute($user, UserRole::Admin, $admin);
        app(BlockUser::class)->execute($user, $admin);

        $response = $this->actingAs($admin)->get(
            route('admin.audit-logs.index', ['action' => AdminAuditAction::UserBlocked->value]),
        );

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.action', AdminAuditAction::UserBlocked->value));
    }
}
