<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Models\PracticeTask;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeletePracticeTask
{
    public function __construct(private AdminAuditLogger $audit) {}

    /**
     * Delete the practice task after writing the `PracticeTaskDeleted`
     * audit entry in the same transaction (the audit subject must still
     * exist when the row is written). Attempt history and AI feedback
     * are removed by FK cascades.
     */
    public function execute(PracticeTask $task, User $actor): void
    {
        DB::transaction(function () use ($task): void {
            $this->audit->log(AdminAuditAction::PracticeTaskDeleted, $task, [
                'lesson_id' => $task->lesson_id,
                'statement_preview' => Str::limit($task->statement, 80),
            ]);

            $task->delete();
        });
    }
}
