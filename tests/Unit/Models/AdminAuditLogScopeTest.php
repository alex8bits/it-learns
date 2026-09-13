<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAuditLogScopeTest extends TestCase
{
    private User $admin;

    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::Admin->value, 'web');
        Role::findOrCreate(UserRole::User->value, 'web');

        $this->admin = User::factory()->admin()->create();
        $this->otherAdmin = User::factory()->admin()->create();
    }

    public function test_filtered_without_filters_returns_all_logs(): void
    {
        $this->createLog();
        $this->createLog();

        $this->assertSame(2, AdminAuditLog::query()->filtered()->count());
    }

    public function test_filtered_by_action_returns_only_matching_action(): void
    {
        $this->createLog(action: AdminAuditAction::UserRoleChanged);
        $this->createLog(action: AdminAuditAction::UserBlocked);

        $actions = AdminAuditLog::query()
            ->filtered(action: AdminAuditAction::UserBlocked)
            ->pluck('action');

        $this->assertSame([AdminAuditAction::UserBlocked], $actions->all());
    }

    public function test_filtered_by_admin_id_returns_only_that_admin(): void
    {
        $this->createLog(adminId: $this->admin->id);
        $this->createLog(adminId: $this->otherAdmin->id);

        $adminIds = AdminAuditLog::query()
            ->filtered(adminId: $this->admin->id)
            ->pluck('admin_id');

        $this->assertSame([$this->admin->id], $adminIds->all());
    }

    public function test_filtered_by_date_from_includes_whole_starting_day(): void
    {
        $dayBefore = $this->createLog(createdAt: '2026-09-12 21:30:00');
        $this->createLog(createdAt: '2026-09-13 00:00:00');
        $this->createLog(createdAt: '2026-09-13 09:45:00');

        $ids = AdminAuditLog::query()->filtered(dateFrom: '2026-09-13')->pluck('id');

        $this->assertSame(2, $ids->count());
        $this->assertNotContains($dayBefore->id, $ids);
    }

    public function test_filtered_by_date_to_includes_entries_written_later_that_day(): void
    {
        // Regression: a bare `date_to` used to compare against midnight and
        // drop every entry written after 00:00:00 of the boundary day.
        $this->createLog(createdAt: '2026-09-13 00:00:00');
        $noon = $this->createLog(createdAt: '2026-09-13 12:00:00');
        $this->createLog(createdAt: '2026-09-13 23:59:59');

        $ids = AdminAuditLog::query()->filtered(dateTo: '2026-09-13')->pluck('id');

        $this->assertSame(3, $ids->count());
        $this->assertContains($noon->id, $ids);
    }

    public function test_filtered_by_date_to_excludes_next_day(): void
    {
        $lastMinute = $this->createLog(createdAt: '2026-09-13 23:59:59');
        $nextDay = $this->createLog(createdAt: '2026-09-14 00:00:00');

        $ids = AdminAuditLog::query()->filtered(dateTo: '2026-09-13')->pluck('id');

        $this->assertSame([$lastMinute->id], $ids->all());
        $this->assertNotContains($nextDay->id, $ids);
    }

    public function test_filtered_combines_all_filters(): void
    {
        $match = $this->createLog(
            createdAt: '2026-09-13 12:00:00',
            action: AdminAuditAction::UserBlocked,
            adminId: $this->admin->id,
        );
        $this->createLog(
            createdAt: '2026-09-13 12:00:00',
            action: AdminAuditAction::UserUnblocked,
            adminId: $this->admin->id,
        );
        $this->createLog(
            createdAt: '2026-09-13 12:00:00',
            action: AdminAuditAction::UserBlocked,
            adminId: $this->otherAdmin->id,
        );
        $this->createLog(
            createdAt: '2026-09-15 12:00:00',
            action: AdminAuditAction::UserBlocked,
            adminId: $this->admin->id,
        );

        $ids = AdminAuditLog::query()->filtered(
            action: AdminAuditAction::UserBlocked,
            adminId: $this->admin->id,
            dateFrom: '2026-09-13',
            dateTo: '2026-09-14',
        )->pluck('id');

        $this->assertSame([$match->id], $ids->all());
    }

    /**
     * Create a log entry with an explicit `created_at`. The column is not
     * mass-assignable and `$timestamps` is disabled, so the boundary
     * timestamp is written via a direct attribute update.
     */
    private function createLog(
        string $createdAt = '2026-09-13 12:00:00',
        AdminAuditAction $action = AdminAuditAction::UserRoleChanged,
        ?int $adminId = null,
    ): AdminAuditLog {
        $log = AdminAuditLog::factory()->create([
            'action' => $action,
            'admin_id' => $adminId ?? $this->admin->id,
        ]);

        $log->created_at = $createdAt;
        $log->save();

        return $log;
    }
}
