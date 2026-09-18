<?php

declare(strict_types=1);

namespace App\Actions\Progress;

use App\Enums\CourseProgressStatus;
use App\Enums\CourseStatus;
use App\Enums\LessonProgressStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\User;
use App\Models\UserCourseProgress;
use App\Models\UserLessonProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Starts or resumes a course for the user (Stage 7). One endpoint serves
 * both «Начать курс» and «Продолжить»: the current lesson is the first
 * published lesson of the course (levels ordered, lessons ordered) that
 * the user has not completed yet; when everything is completed, the
 * course restarts from the first published lesson.
 */
class StartCourse
{
    /**
     * Create (once) the user's course progress row, point it at the
     * current lesson and return that lesson.
     *
     * @throws NotFoundHttpException when the course is not published or has no published lessons
     */
    public function execute(User $user, Course $course): Lesson
    {
        if ($course->status !== CourseStatus::Published) {
            throw new NotFoundHttpException('Course is not published.');
        }

        return DB::transaction(function () use ($user, $course): Lesson {
            $lesson = $this->resolveCurrentLesson($user, $course);

            $progress = UserCourseProgress::query()->firstOrCreate(
                ['user_id' => $user->id, 'course_id' => $course->id],
                ['status' => CourseProgressStatus::InProgress],
            );

            if ($progress->current_lesson_id !== $lesson->id) {
                $progress->update(['current_lesson_id' => $lesson->id]);
            }

            return $lesson;
        });
    }

    private function resolveCurrentLesson(User $user, Course $course): Lesson
    {
        $publishedLessons = $course->levels()
            ->with(['lessons' => fn ($lessons) => $lessons->published()])
            ->get()
            ->flatMap(fn (Level $level): array => $level->lessons->all());

        $lesson = $this->firstUncompleted($user, $publishedLessons) ?? $publishedLessons->first();

        if ($lesson === null) {
            throw new NotFoundHttpException('Course has no published lessons.');
        }

        return $lesson;
    }

    /**
     * The first lesson of the ordered list that has no Completed progress
     * row for the user; falls back to the first lesson when the user has
     * completed them all.
     *
     * @param  Collection<int, Lesson>  $lessons
     */
    private function firstUncompleted(User $user, Collection $lessons): ?Lesson
    {
        $completedLessonIds = UserLessonProgress::query()
            ->where('user_id', $user->id)
            ->where('status', LessonProgressStatus::Completed)
            ->pluck('lesson_id');

        return $lessons->first(
            fn (Lesson $lesson): bool => ! $completedLessonIds->contains($lesson->id),
        );
    }
}
