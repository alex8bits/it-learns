<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditLogShowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_admin_sees_audit_log_entry(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        $log = AdminAuditLog::factory()->create([
            'admin_id' => $admin->id,
            'subject_id' => $target->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.audit-logs.show', $log));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLogs/Show')
            ->where('log.id', $log->id)
            ->where('actionLabel', AdminAuditAction::UserRoleChanged->label()));
    }

    public function test_non_admin_cannot_see_audit_log_entry(): void
    {
        $user = User::factory()->create();
        $log = AdminAuditLog::factory()->create();

        $this->actingAs($user)->get(route('admin.audit-logs.show', $log))->assertForbidden();
    }
}
