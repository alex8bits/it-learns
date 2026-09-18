<?php

declare(strict_types=1);

namespace App\Http\Controllers\Courses;

use App\Enums\LessonProgressStatus;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\UserLessonProgress;
use App\Services\Progress\CourseProgress;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public course card (Stage 6): `/courses/{slug}` shows the course with
 * its «levels → lessons» structure. Guest-accessible — unpublished
 * courses resolve to 404 through the `published` scope (no policy:
 * guests have no user to authorize). For authenticated users the card
 * additionally carries their progress (Stage 7): a percent bar plus
 * per-lesson statuses for the clickable lesson links.
 */
class CourseController extends Controller
{
    /**
     * Attributes hidden from the public course card: the course-specific
     * AI prompt and internal storage/creator references must not leak
     * into guest-visible page props.
     */
    private const HIDDEN_COURSE_ATTRIBUTES = ['ai_course_prompt', 'preview_image_path', 'created_by'];

    public function __construct(private readonly CourseProgress $progress) {}

    /**
     * Course card by slug: published course with levels (ordered by
     * `order`, each carrying its own title) eager-loaded together with
     * their published lessons only — draft lessons never reach the
     * frontend, and the eager load keeps the page N+1-free.
     */
    public function show(Request $request, string $slug): Response
    {
        $course = Course::query()
            ->published()
            ->where('slug', $slug)
            ->with(['levels' => fn ($levels) => $levels->ordered()->with([
                'lessons' => fn ($lessons) => $lessons->published()->ordered(),
            ])])
            ->firstOrFail()
            ->makeHidden(self::HIDDEN_COURSE_ATTRIBUTES);

        $props = [
            'course' => $course,
        ];

        if ($request->user() !== null) {
            $props['progress'] = $this->userProgress($request->user()->id, $course);
        }

        return Inertia::render('Courses/Show', $props);
    }

    /**
     * The user's progress over the published lessons of this course:
     * percent (completed / total, rounded — the single formula lives
     * in CourseProgress, shared with the dashboard grid) and a
     * lesson_id => status map for the lesson badges. «Не начат» rows
     * are absent — the frontend treats a missing lesson_id as not
     * started. One `whereIn` query covers the whole course.
     *
     * @return array{percent: int, lessonStatuses: array<int, string>}
     */
    private function userProgress(int $userId, Course $course): array
    {
        $lessonIds = $course->levels
            ->flatMap(fn ($level) => $level->lessons->pluck('id'))
            ->values();

        $lessonStatuses = UserLessonProgress::query()
            ->where('user_id', $userId)
            ->whereIn('lesson_id', $lessonIds)
            ->pluck('status', 'lesson_id');

        $completed = $lessonStatuses
            ->filter(fn (LessonProgressStatus $status): bool => $status === LessonProgressStatus::Completed)
            ->count();

        return [
            'percent' => $this->progress->percent($completed, $lessonIds->count()),
            'lessonStatuses' => $lessonStatuses
                ->map(fn (LessonProgressStatus $status): string => $status->value)
                ->all(),
        ];
    }
}
