<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\CourseStatus;
use App\Jobs\ConvertCoursePreviewToWebp;
use App\Models\Course;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use App\Services\Ai\PromptKeys;
use App\Services\Ai\PromptVersionService;
use App\Services\Courses\PreviewImageProcessor;
use App\Services\Courses\UniqueSlugGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CreateCourse
{
    public function __construct(
        private AdminAuditLogger $audit,
        private UniqueSlugGenerator $slugGenerator,
        private PreviewImageProcessor $previews,
        private PromptVersionService $promptVersions,
    ) {}

    /**
     * Create a course with a unique slug and an optional processed
     * preview image, atomically audited as `CourseCreated`.
     *
     * The preview file is written to disk before the transaction — a
     * disk write cannot roll back anyway, and only the committed course
     * row references the path. A non-empty `ai_course_prompt` gets its
     * first version (`v1`) through PromptVersionService in the same
     * transaction, which also fills the `courses.ai_course_prompt`
     * cache column — so the prompt is never passed to `Course::create`.
     * Non-WebP previews get a queued WebP conversion dispatched after
     * the commit (the job itself is `afterCommit`-guarded too).
     *
     * @param  array{title: string, description: string, status?: CourseStatus|string, sort_order?: int, ai_course_prompt?: string|null}  $attributes
     */
    public function execute(array $attributes, ?UploadedFile $previewImage, User $actor): Course
    {
        $prompt = trim((string) ($attributes['ai_course_prompt'] ?? ''));

        $previewPath = $previewImage !== null
            ? $this->previews->process($previewImage)
            : null;

        $course = DB::transaction(function () use ($attributes, $actor, $prompt, $previewPath): Course {
            $course = Course::create([
                'slug' => $this->slugGenerator->generate((string) $attributes['title'], Course::class),
                'title' => $attributes['title'],
                'description' => $attributes['description'],
                'preview_image_path' => $previewPath,
                'status' => $attributes['status'] ?? CourseStatus::Draft,
                'sort_order' => (int) ($attributes['sort_order'] ?? 0),
                'created_by' => $actor->id,
            ]);

            if ($prompt !== '') {
                $this->promptVersions->createNewVersion(
                    PromptKeys::forCourse($course->id),
                    $prompt,
                    'Initial version',
                    $actor,
                );
            }

            $this->audit->log(AdminAuditAction::CourseCreated, $course, [
                'slug' => $course->slug,
                'has_preview' => $previewPath !== null,
                'with_prompt' => $prompt !== '',
                'sort_order' => $course->sort_order,
            ]);

            return $course;
        });

        if ($previewPath !== null && ! str_ends_with($previewPath, '.webp')) {
            ConvertCoursePreviewToWebp::dispatch($course->id);
        }

        return $course;
    }
}
