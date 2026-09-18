<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Models\Level;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Support\Facades\DB;

class DeleteLevel
{
    public function __construct(private AdminAuditLogger $audit) {}

    /**
     * Delete the level and its lessons (the FK cascade) after writing
     * the `LevelDeleted` audit entry in the same transaction.
     */
    public function execute(Level $level, User $actor): void
    {
        DB::transaction(function () use ($level): void {
            $this->audit->log(AdminAuditAction::LevelDeleted, $level, [
                'course_id' => $level->course_id,
                'title' => $level->title,
            ]);

            $level->delete();
        });
    }
}
