<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Course;
use App\Services\Courses\PreviewImageProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued WebP conversion of a stored course preview (Stage 11).
 *
 * Dispatched by CreateCourse/UpdateCourse after the database
 * transaction commits, for previews stored in a non-WebP format. The
 * job is idempotent: when the current path already points at a
 * `.webp` file, `convertToWebp()` returns null and nothing happens.
 * Every degradation (course deleted, path empty, conversion not
 * applicable) is a silent no-op — the preview simply stays in its
 * original, already-valid format.
 */
class ConvertCoursePreviewToWebp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $courseId)
    {
        // Set imperatively: `afterCommit` is declared by the Queueable
        // trait (untyped there), and a class-level redeclaration with
        // a type/default is a fatal composition conflict. True pairs
        // with the actions dispatching this job only after their own
        // DB::transaction has committed — double protection against a
        // job running on an uncommitted course.
        $this->afterCommit = true;
    }

    public function handle(PreviewImageProcessor $previews): void
    {
        $course = Course::find($this->courseId);

        if ($course === null) {
            // The course is gone; DeleteCourse has already cleaned the
            // preview file up.
            return;
        }

        $path = $course->preview_image_path;

        if ($path === null || $path === '') {
            return;
        }

        $newPath = $previews->convertToWebp($path);

        if ($newPath === null) {
            return;
        }

        // Single-table write — no transaction needed (rule 6 covers
        // multi-table writes only).
        $course->update(['preview_image_path' => $newPath]);

        $previews->delete($path);
    }
}
