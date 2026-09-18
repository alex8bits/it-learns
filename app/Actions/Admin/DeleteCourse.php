<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Models\Course;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use App\Services\Courses\PreviewImageProcessor;
use Illuminate\Support\Facades\DB;

class DeleteCourse
{
    public function __construct(
        private AdminAuditLogger $audit,
        private PreviewImageProcessor $previews,
    ) {}

    /**
     * Delete the course together with its level/lesson tree (the FK
     * cascades do the child rows) and the preview file.
     *
     * The `CourseDeleted` audit entry is written before the delete
     * inside the transaction — the audit row stores plain
     * `subject_type`/`subject_id` strings, so it survives the subject.
     * Prompt versions are intentionally kept: the append-only history
     * has no FK and outlives the course. The preview file is removed
     * only after the transaction commits.
     */
    public function execute(Course $course, User $actor): void
    {
        $previewPath = $course->preview_image_path;

        DB::transaction(function () use ($course): void {
            $this->audit->log(AdminAuditAction::CourseDeleted, $course, [
                'slug' => $course->slug,
                'title' => $course->title,
            ]);

            $course->delete();
        });

        $this->previews->delete($previewPath);
    }
}
