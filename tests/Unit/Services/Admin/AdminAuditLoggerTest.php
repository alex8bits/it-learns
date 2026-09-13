<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAuditLoggerTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::Admin->value, 'web');
        Role::findOrCreate(UserRole::User->value, 'web');

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    public function test_creates_audit_log_with_auth_context(): void
    {
        $target = User::factory()->create();
        $logger = app(AdminAuditLogger::class);

        $log = $logger->log(
            AdminAuditAction::UserRoleChanged,
            $target,
            ['old' => 'User', 'new' => 'Admin'],
        );

        $this->assertInstanceOf(AdminAuditLog::class, $log);
        $this->assertTrue($log->exists);

        $this->assertSame($this->admin->id, $log->admin_id);
        $this->assertSame(AdminAuditAction::UserRoleChanged, $log->action);
        $this->assertSame((new User)->getMorphClass(), $log->subject_type);
        $this->assertSame($target->id, $log->subject_id);
        $this->assertSame(['old' => 'User', 'new' => 'Admin'], $log->meta);

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $this->admin->id,
            'action' => 'UserRoleChanged',
            'subject_type' => (new User)->getMorphClass(),
            'subject_id' => $target->id,
        ]);
    }

    public function test_meta_array_persists_as_is(): void
    {
        $target = User::factory()->create();
        $meta = ['old' => ['User'], 'new' => 'Admin', 'note' => 'promoted by root'];

        $log = app(AdminAuditLogger::class)->log(
            AdminAuditAction::UserRoleChanged,
            $target,
            $meta,
        );

        $this->assertSame($meta, $log->meta);
    }

    public function test_handles_null_subject(): void
    {
        $log = app(AdminAuditLogger::class)->log(
            AdminAuditAction::UserRoleChanged,
            null,
            ['note' => 'system-level event'],
        );

        $this->assertTrue($log->exists);
        $this->assertNull($log->subject_type);
        $this->assertNull($log->subject_id);
        $this->assertSame(['note' => 'system-level event'], $log->meta);
    }

    public function test_captures_ip_and_user_agent_from_request(): void
    {
        $this->call(
            method: 'GET',
            uri: '/',
            server: [
                'REMOTE_ADDR' => '192.0.2.1',
                'HTTP_USER_AGENT' => 'PHPUnit/Test',
            ],
        );

        $target = User::factory()->create();
        $log = app(AdminAuditLogger::class)->log(
            AdminAuditAction::UserBlocked,
            $target,
        );

        $this->assertSame('192.0.2.1', $log->ip);
        $this->assertSame('PHPUnit/Test', $log->user_agent);
    }

    public function test_admin_id_is_null_without_auth(): void
    {
        auth()->logout();

        $target = User::factory()->create();
        $log = app(AdminAuditLogger::class)->log(
            AdminAuditAction::UserRoleChanged,
            $target,
            ['note' => 'no auth context'],
        );

        $this->assertTrue($log->exists);
        $this->assertNull($log->admin_id);
    }
}
