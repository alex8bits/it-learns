<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Support\Facades\DB;

class UnblockUser
{
    public function __construct(private AdminAuditLogger $audit) {}

    /**
     * Atomically clear the blocked flag on the target user and record the
     * audit log. Mirrors `BlockUser` — see the note there on why
     * `is_blocked` is not on `#[Fillable]`.
     */
    public function execute(User $target, User $admin): void
    {
        DB::transaction(function () use ($target): void {
            $target->is_blocked = false;
            $target->save();

            $this->audit->log(AdminAuditAction::UserUnblocked, $target);
        });
    }
}
