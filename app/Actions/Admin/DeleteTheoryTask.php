<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Models\TheoryTask;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeleteTheoryTask
{
    public function __construct(private AdminAuditLogger $audit) {}

    /**
     * Delete the theory task after writing the `TheoryTaskDeleted` audit
     * entry in the same transaction. Options and users' answers are
     * removed by FK cascades.
     */
    public function execute(TheoryTask $task, User $actor): void
    {
        DB::transaction(function () use ($task): void {
            $this->audit->log(AdminAuditAction::TheoryTaskDeleted, $task, [
                'lesson_id' => $task->lesson_id,
                'question_preview' => Str::limit($task->question, 80),
                'options_count' => $task->options()->count(),
            ]);

            $task->delete();
        });
    }
}
