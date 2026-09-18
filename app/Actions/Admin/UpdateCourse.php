<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\CourseStatus;
use App\Jobs\ConvertCoursePreviewToWebp;
use App\Models\Course;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use App\Services\Courses\PreviewImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class UpdateCourse
{
    public function __construct(
        private AdminAuditLogger $audit,
        private PreviewImageProcessor $previews,
    ) {}

    /**
     * Update the scalar course fields (slug is immutable) and the
     * preview image, audited atomically by the status transition:
     * moving to `Published` records `CoursePublished`, moving to
     * `Archived` records `CourseArchived`, everything else (including
     * staying in the same status) records `CourseUpdated`.
     *
     * The new preview is processed and stored before the transaction;
     * the replaced old file is deleted only after the transaction has
     * committed, so a rolled-back update never destroys the live file.
     * Non-WebP replacements get a queued WebP conversion dispatched
     * after the commit (the job itself is `afterCommit`-guarded too).
     *
     * @param  array{title?: string, description?: string, status?: CourseStatus|string, sort_order?: int}  $attributes
     */
    public function execute(Course $course, array $attributes, ?UploadedFile $previewImage, User $actor): Course
    {
        $statusOld = $this->statusValue($course->status);
        $oldPreviewPath = $course->preview_image_path;

        $newPreviewPath = $previewImage !== null
            ? $this->previews->process($previewImage)
            : null;

        DB::transaction(function () use ($course, $attributes, $statusOld, $newPreviewPath): void {
            $payload = [];

            if (array_key_exists('title', $attributes)) {
                $payload['title'] = (string) $attributes['title'];
            }

            if (array_key_exists('description', $attributes)) {
                $payload['description'] = (string) $attributes['description'];
            }

            if (array_key_exists('status', $attributes)) {
                $payload['status'] = $attributes['status'] instanceof CourseStatus
                    ? $attributes['status']
                    : CourseStatus::from((string) $attributes['status']);
            }

            if (array_key_exists('sort_order', $attributes)) {
                $payload['sort_order'] = (int) $attributes['sort_order'];
            }

            if ($newPreviewPath !== null) {
                $payload['preview_image_path'] = $newPreviewPath;
            }

            $course->fill($payload)->save();

            $action = match (true) {
                $course->status === CourseStatus::Published && $statusOld !== CourseStatus::Published->value => AdminAuditAction::CoursePublished,
                $course->status === CourseStatus::Archived && $statusOld !== CourseStatus::Archived->value => AdminAuditAction::CourseArchived,
                default => AdminAuditAction::CourseUpdated,
            };

            $this->audit->log($action, $course, [
                'slug' => $course->slug,
                'status_old' => $statusOld,
                'status_new' => $this->statusValue($course->status),
                'preview_changed' => $newPreviewPath !== null,
                'sort_order' => $course->sort_order,
            ]);
        });

        if ($newPreviewPath !== null) {
            $this->previews->delete($oldPreviewPath);

            if (! str_ends_with($newPreviewPath, '.webp')) {
                ConvertCoursePreviewToWebp::dispatch($course->id);
            }
        }

        return $course;
    }

    /**
     * Normalize the status for the audit meta: the attribute holds the
     * enum instance at runtime, while Larastan only sees the backing
     * string column.
     */
    private function statusValue(CourseStatus|string $status): string
    {
        return $status instanceof CourseStatus ? $status->value : $status;
    }
}
