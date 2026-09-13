<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserRoleChangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_admin_can_change_user_role_and_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $user->assignRole(UserRole::User->value);
        $this->assertTrue($user->hasRole(UserRole::User->value));

        $response = $this->actingAs($admin)->patch(
            route('admin.users.update', $user),
            ['role' => UserRole::Admin->value],
        );

        $response->assertRedirect(route('admin.users.show', $user));
        $this->assertTrue($user->fresh()->hasRole(UserRole::Admin->value));

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::UserRoleChanged->value,
            'admin_id' => $admin->id,
            'subject_id' => $user->id,
        ]);
        // Проверяем meta через выборку (JSON-каст не работает в assertDatabaseHas)
        $log = AdminAuditLog::where('subject_id', $user->id)->latest('created_at')->first();
        $this->assertNotNull($log);
        $meta = $log->meta;
        $this->assertIsArray($meta);
        $this->assertSame([UserRole::User->value], $meta['old']);
        $this->assertSame(UserRole::Admin->value, $meta['new']);
    }
}
