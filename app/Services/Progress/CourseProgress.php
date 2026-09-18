<?php

declare(strict_types=1);

namespace App\Services\Progress;

use App\Enums\LessonProgressStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\UserLessonProgress;
use Illuminate\Support\Collection;

/**
 * The single place defining the user's course progress percent
 * (Stage 7 formula): completed published lessons over all published
 * lessons of the course, computed on read — there is no `percent`
 * column (concept.md §6). The course card (`/courses/{slug}`) and the
 * dashboard grid both go through this service, so their percents can
 * never drift apart. Do not duplicate the formula anywhere else.
 */
class CourseProgress
{
    /**
     * The course completion percent for the given completed/total
     * counts: 0 when there is nothing to complete, otherwise the
     * rounded share of completed lessons.
     */
    public function percent(int $completed, int $total): int
    {
        return $total === 0 ? 0 : (int) round($completed / $total * 100);
    }

    /**
     * Batch percents for a whole page of courses: the published
     * lessons of every given course are fetched in one query (mapped
     * to their course through the owning level) and the user's
     * completed rows in one more — a constant number of queries
     * regardless of how many courses the page shows (no N+1, rule
     * №9). A course without published lessons and a user without
     * progress rows both map to 0. Inertia serializes the int keys as
     * an object (`{"1": 33}`); the Vue side reads
     * `progressPercents[course.id]`.
     *
     * @param  Collection<int, Course>  $courses
     * @return array<int, int> map of course_id => percent
     */
    public function percentByCourse(int $userId, Collection $courses): array
    {
        if ($courses->isEmpty()) {
            return [];
        }

        $lessonsByCourse = Lesson::query()
            ->published()
            ->whereHas('level', fn ($levels) => $levels->whereIn('course_id', $courses->pluck('id')))
            ->with('level:id,course_id')
            ->get()
            ->groupBy(fn (Lesson $lesson): int => $lesson->level->course_id);

        $completedLessonIds = UserLessonProgress::query()
            ->where('user_id', $userId)
            ->whereIn('lesson_id', $lessonsByCourse->flatten()->pluck('id'))
            ->where('status', LessonProgressStatus::Completed->value)
            ->pluck('lesson_id');

        $percents = [];

        foreach ($courses as $course) {
            $courseLessonIds = $lessonsByCourse->get($course->id)?->pluck('id') ?? collect();

            $percents[$course->id] = $this->percent(
                $courseLessonIds->intersect($completedLessonIds)->count(),
                $courseLessonIds->count(),
            );
        }

        return $percents;
    }
}
