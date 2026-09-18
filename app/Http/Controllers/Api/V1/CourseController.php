<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Http\Resources\LessonResource;
use App\Models\Course;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public read-only catalog API (Stage 6): the JSON counterpart of the
 * web catalog (Courses\CatalogController / Courses\CourseController).
 * Guest-accessible — no auth middleware; only published courses are
 * visible (the `published` scope filters drafts/archived into 404s,
 * guests have no user to authorize). Deliberately no throttle: a cheap
 * read-only catalog, consistent with the documented /api/v1/health
 * decision (routes/api.php).
 */
class CourseController extends Controller
{
    /**
     * Paginated list of published courses, newest first, 15 per page.
     * No `levels` eager load: the list shape stays flat (rule #9 —
     * `whenLoaded` keeps the relation out of the payload).
     *
     * The material mode is pinned at the entry point: the list
     * explicitly resets LessonResource::$withMaterial to false, so a
     * previous show request handled by the same FPM worker can never
     * leak study material into list responses.
     */
    public function index(): AnonymousResourceCollection
    {
        LessonResource::$withMaterial = false;

        $courses = Course::query()
            ->published()
            ->ordered()
            ->withCount('levels')
            ->paginate(15);

        return CourseResource::collection($courses);
    }

    /**
     * Course card by slug: published course with its levels (ordered by
     * `order`) eager-loaded together with their published lessons only —
     * draft lessons never reach the API consumer, and the eager load
     * keeps the response N+1-free.
     *
     * Contract: every API entry point pins the material mode on entry
     * for determinism between requests of the same FPM worker — this
     * one sets LessonResource::$withMaterial to true (not relying on
     * the flag left by a previous request), index() resets it to
     * false. The flag itself is never reset after rendering (see the
     * LessonResource contract).
     */
    public function show(string $slug): CourseResource
    {
        LessonResource::$withMaterial = true;

        $course = Course::query()
            ->published()
            ->with(['levels' => fn ($levels) => $levels->ordered()->with([
                'lessons' => fn ($lessons) => $lessons->published()->ordered(),
            ])])
            ->where('slug', $slug)
            ->firstOrFail();

        return new CourseResource($course);
    }
}
