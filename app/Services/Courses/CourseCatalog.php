<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Models\Course;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Published course catalog — the single query behind both the public
 * catalog (`/`, `/courses`) and the authorized user dashboard: only
 * published courses, newest first, with a `levels_count` badge and
 * guest-invisible attributes stripped from every item.
 */
class CourseCatalog
{
    /**
     * Attributes hidden from public-facing lists: the course-specific
     * AI prompt and internal storage/creator references must not leak
     * into page props.
     */
    private const HIDDEN_COURSE_ATTRIBUTES = ['ai_course_prompt', 'preview_image_path', 'created_by'];

    /**
     * @return LengthAwarePaginator<int, Course>
     */
    public function paginate(int $perPage = 12): LengthAwarePaginator
    {
        return Course::query()
            ->published()
            ->ordered()
            ->withCount('levels')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Course $course) => $course->makeHidden(self::HIDDEN_COURSE_ATTRIBUTES));
    }
}
