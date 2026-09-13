<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditLogIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_admin_sees_audit_logs(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        AdminAuditLog::factory()->create([
            'admin_id' => $admin->id,
            'subject_id' => $target->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.audit-logs.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AuditLogs/Index')
            ->has('logs.data', 1)
            ->where('logs.data.0.action', AdminAuditAction::UserRoleChanged->value));
    }
}
