<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Support\Facades\DB;

class DeleteLesson
{
    public function __construct(private AdminAuditLogger $audit) {}

    /**
     * Delete the lesson after writing the `LessonDeleted` audit entry
     * in the same transaction.
     */
    public function execute(Lesson $lesson, User $actor): void
    {
        DB::transaction(function () use ($lesson): void {
            $this->audit->log(AdminAuditAction::LessonDeleted, $lesson, [
                'level_id' => $lesson->level_id,
                'slug' => $lesson->slug,
                'title' => $lesson->title,
            ]);

            $lesson->delete();
        });
    }
}
