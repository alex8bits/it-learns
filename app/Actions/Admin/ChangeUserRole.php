<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Support\Facades\DB;

class ChangeUserRole
{
    public function __construct(private AdminAuditLogger $audit) {}

    /**
     * Atomically replace the target user's Spatie roles with `$newRole` and
     * record an audit log entry. The previous role set is captured *before*
     * `syncRoles()` so `meta.old` reflects the pre-change state.
     */
    public function execute(User $target, UserRole $newRole, User $admin): void
    {
        $oldRoles = $target->getRoleNames()->all();

        DB::transaction(function () use ($target, $newRole, $oldRoles): void {
            $target->syncRoles([$newRole->value]);

            $this->audit->log(
                AdminAuditAction::UserRoleChanged,
                $target,
                ['old' => $oldRoles, 'new' => $newRole->value],
            );
        });
    }
}
