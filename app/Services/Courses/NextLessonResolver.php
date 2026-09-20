<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Models\Course;
use App\Models\Lesson;

/**
 * The single place defining which lesson is «the next one» in a course:
 * the lesson right after $current in the canonical course order —
 * levels by `order`, lessons by `order` inside each level, across level
 * boundaries. The lesson page uses it for the «Перейти к следующему
 * уроку» button; null means the current lesson is the last one. This is
 * a positional neighbour, not the «first uncompleted lesson» of
 * StartCourse (Начать/Продолжить) — different questions, keep them apart.
 */
class NextLessonResolver
{
    /**
     * The next lesson of the course after $current, ordered by
     * (level.order, lesson.order) across level boundaries. Published
     * lessons only by default; the admin preview passes false to see
     * drafts as well. Null when $current is the last lesson (or the
     * course has no other lessons). The publication of $current itself
     * and the course status are the caller's concern — an unpublished
     * current lesson simply does not appear in the published list and
     * maps to null. Levels have no publication flag, so the filter
     * applies to lessons only.
     *
     * @param  Lesson  $current  the lesson the user is currently on
     * @param  bool  $publishedOnly  whether draft lessons participate (false in the admin preview)
     * @return Lesson|null the lesson following $current, or null when there is none
     */
    public function __invoke(Course $course, Lesson $current, bool $publishedOnly = true): ?Lesson
    {
        $lessons = Lesson::query()
            ->when($publishedOnly, fn ($query) => $query->published())
            ->whereHas('level', fn ($levels) => $levels->where('course_id', $course->id))
            ->with('level:id,course_id,order')
            ->get()
            ->sortBy([
                fn (Lesson $a, Lesson $b): int => $a->level->order <=> $b->level->order,
                fn (Lesson $a, Lesson $b): int => $a->order <=> $b->order,
            ])
            ->values();

        $index = $lessons->search(fn (Lesson $lesson): bool => $lesson->id === $current->id);

        return $index === false ? null : $lessons->get($index + 1);
    }
}
