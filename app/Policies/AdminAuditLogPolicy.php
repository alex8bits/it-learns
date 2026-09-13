<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;

class AdminAuditLogPolicy
{
    /**
     * Admin can browse the audit log list.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Admin->value);
    }

    /**
     * Admin can view any single audit log entry.
     */
    public function view(User $user, AdminAuditLog $log): bool
    {
        return $user->hasRole(UserRole::Admin->value);
    }

    /*
     * Intentionally no create/update/delete methods.
     * The audit log is append-only: Laravel's Gate will deny these
     * abilities by default, so any accidental authorize('create', $log)
     * call throws AuthorizationException instead of silently allowing it.
     */
}
