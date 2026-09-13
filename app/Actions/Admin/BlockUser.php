<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Support\Facades\DB;

class BlockUser
{
    public function __construct(private AdminAuditLogger $audit) {}

    /**
     * Atomically mark the target user as blocked and record the audit log.
     *
     * `is_blocked` is intentionally not part of the model's `#[Fillable]` (Q7
     * of the admin skeleton design), so it is written through direct
     * assignment + `save()` here — only the `BlockUser` / `UnblockUser`
     * actions are allowed to flip the flag.
     */
    public function execute(User $target, User $admin): void
    {
        DB::transaction(function () use ($target): void {
            $target->is_blocked = true;
            $target->save();

            $this->audit->log(AdminAuditAction::UserBlocked, $target);
        });
    }
}
