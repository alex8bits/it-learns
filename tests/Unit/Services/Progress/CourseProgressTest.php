<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Progress;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\User;
use App\Models\UserLessonProgress;
use App\Services\Progress\CourseProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CourseProgressTest extends TestCase
{
    public function test_empty_course_collection_returns_empty_map(): void
    {
        $user = User::factory()->create();

        $percents = (new CourseProgress)->percentByCourse($user->id, collect());

        $this->assertSame([], $percents);
    }

    public function test_course_without_lessons_maps_to_zero(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->published()->create();

        $percents = (new CourseProgress)->percentByCourse($user->id, collect([$course]));

        $this->assertSame([$course->id => 0], $percents);
    }

    public function test_course_with_only_unpublished_lessons_maps_to_zero(): void
    {
        $user = User::factory()->create();
        [$course] = $this->courseWithLessons(0, 2);

        $percents = (new CourseProgress)->percentByCourse($user->id, collect([$course]));

        $this->assertSame([$course->id => 0], $percents);
    }

    public function test_course_without_progress_rows_maps_to_zero(): void
    {
        $user = User::factory()->create();
        [$course] = $this->courseWithLessons(3);

        $percents = (new CourseProgress)->percentByCourse($user->id, collect([$course]));

        $this->assertSame([$course->id => 0], $percents);
    }

    public function test_one_of_three_published_lessons_completed_maps_to_33(): void
    {
        $user = User::factory()->create();
        [$course, $lessons] = $this->courseWithLessons(3);

        UserLessonProgress::factory()->completed()->for($user)->create([
            'lesson_id' => $lessons->first()->id,
        ]);

        $percents = (new CourseProgress)->percentByCourse($user->id, collect([$course]));

        $this->assertSame([$course->id => 33], $percents);
    }

    public function test_two_of_three_published_lessons_completed_maps_to_67(): void
    {
        $user = User::factory()->create();
        [$course, $lessons] = $this->courseWithLessons(3);

        $lessons->take(2)->each(fn (Lesson $lesson) => UserLessonProgress::factory()
            ->completed()
            ->for($user)
            ->create(['lesson_id' => $lesson->id]));

        $percents = (new CourseProgress)->percentByCourse($user->id, collect([$course]));

        $this->assertSame([$course->id => 67], $percents);
    }

    public function test_all_published_lessons_completed_map_to_100(): void
    {
        $user = User::factory()->create();
        [$course, $lessons] = $this->courseWithLessons(3);

        $lessons->each(fn (Lesson $lesson) => UserLessonProgress::factory()
            ->completed()
            ->for($user)
            ->create(['lesson_id' => $lesson->id]));

        $percents = (new CourseProgress)->percentByCourse($user->id, collect([$course]));

        $this->assertSame([$course->id => 100], $percents);
    }

    public function test_multiple_courses_with_different_progress_are_mapped_in_one_call(): void
    {
        $user = User::factory()->create();
        [$untouched] = $this->courseWithLessons(2);
        [$partial, $partialLessons] = $this->courseWithLessons(3);
        [$full, $fullLessons] = $this->courseWithLessons(2);

        UserLessonProgress::factory()->completed()->for($user)->create([
            'lesson_id' => $partialLessons->first()->id,
        ]);
        $fullLessons->each(fn (Lesson $lesson) => UserLessonProgress::factory()
            ->completed()
            ->for($user)
            ->create(['lesson_id' => $lesson->id]));

        $percents = (new CourseProgress)->percentByCourse($user->id, collect([$untouched, $partial, $full]));

        $this->assertSame([$untouched->id => 0, $partial->id => 33, $full->id => 100], $percents);
    }

    public function test_in_progress_rows_do_not_count_toward_the_numerator(): void
    {
        $user = User::factory()->create();
        [$course, $lessons] = $this->courseWithLessons(2);

        $lessons->each(fn (Lesson $lesson) => UserLessonProgress::factory()
            ->for($user)
            ->create(['lesson_id' => $lesson->id]));

        $percents = (new CourseProgress)->percentByCourse($user->id, collect([$course]));

        $this->assertSame([$course->id => 0], $percents);
    }

    public function test_unpublished_lessons_count_neither_as_total_nor_completed(): void
    {
        $user = User::factory()->create();
        // Two published + one unpublished lesson: the denominator must
        // be 2, so completing one published lesson is 50%, not 33%.
        [$course, $lessons] = $this->courseWithLessons(2, 1);

        UserLessonProgress::factory()->completed()->for($user)->create([
            'lesson_id' => $lessons->first()->id,
        ]);

        $percents = (new CourseProgress)->percentByCourse($user->id, collect([$course]));

        $this->assertSame([$course->id => 50], $percents);
    }

    public function test_progress_on_an_unpublished_lesson_is_ignored(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->published()->create();
        $level = Level::factory()->create(['course_id' => $course]);
        $published = Lesson::factory()->create(['level_id' => $level->id, 'order' => 1]);
        $unpublished = Lesson::factory()->unpublished()->create(['level_id' => $level->id, 'order' => 2]);

        // Completed — but on the unpublished lesson: neither numerator
        // nor denominator may see it, so the course stays at 0% while
        // the published lesson remains uncompleted in the denominator.
        UserLessonProgress::factory()->completed()->for($user)->create([
            'lesson_id' => $unpublished->id,
        ]);

        $percents = (new CourseProgress)->percentByCourse($user->id, collect([$course]));

        $this->assertSame([$course->id => 0], $percents);
    }

    public function test_another_users_progress_does_not_count(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$course, $lessons] = $this->courseWithLessons(2);

        $lessons->each(fn (Lesson $lesson) => UserLessonProgress::factory()
            ->completed()
            ->for($other)
            ->create(['lesson_id' => $lesson->id]));

        $percents = (new CourseProgress)->percentByCourse($user->id, collect([$course]));

        $this->assertSame([$course->id => 0], $percents);
    }

    public function test_percent_by_course_runs_a_constant_number_of_queries_regardless_of_course_count(): void
    {
        $user = User::factory()->create();
        $twoCourses = $this->progressedCourses($user, 2);
        $fiveCourses = $this->progressedCourses($user, 5);

        DB::enableQueryLog();

        DB::flushQueryLog();
        (new CourseProgress)->percentByCourse($user->id, $twoCourses);
        $twoCourseQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        (new CourseProgress)->percentByCourse($user->id, $fiveCourses);
        $fiveCourseQueryCount = count(DB::getQueryLog());

        DB::disableQueryLog();

        // The batch must not grow with the course count (no N+1),
        // and it must really hit the database.
        $this->assertSame($twoCourseQueryCount, $fiveCourseQueryCount);
        $this->assertGreaterThan(0, $fiveCourseQueryCount);
        $this->assertLessThan(6, $fiveCourseQueryCount);
    }

    #[DataProvider('percentProvider')]
    public function test_percent_computes_the_rounded_share(int $completed, int $total, int $expected): void
    {
        $this->assertSame($expected, (new CourseProgress)->percent($completed, $total));
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function percentProvider(): array
    {
        return [
            'no lessons at all' => [0, 0, 0],
            'one of three' => [1, 3, 33],
            'two of three' => [2, 3, 67],
            'all three' => [3, 3, 100],
        ];
    }

    /**
     * A published course with a single level holding the given number
     * of published (and optionally unpublished) lessons.
     *
     * @return array{0: Course, 1: Collection<int, Lesson>} the course and its published lessons
     */
    private function courseWithLessons(int $publishedCount, int $unpublishedCount = 0): array
    {
        $course = Course::factory()->published()->create();
        $level = Level::factory()->create(['course_id' => $course]);

        $published = Collection::times($publishedCount, fn (int $i): Lesson => Lesson::factory()->create([
            'level_id' => $level->id,
            'order' => $i,
        ]));

        Collection::times($unpublishedCount, fn (int $i): Lesson => Lesson::factory()->unpublished()->create([
            'level_id' => $level->id,
            'order' => $publishedCount + $i,
        ]));

        return [$course, $published];
    }

    /**
     * The given number of one-lesson courses, every lesson completed
     * by the user.
     *
     * @return Collection<int, Course>
     */
    private function progressedCourses(User $user, int $count): Collection
    {
        return Collection::times($count, function () use ($user): Course {
            [$course, $lessons] = $this->courseWithLessons(1);

            UserLessonProgress::factory()->completed()->for($user)->create([
                'lesson_id' => $lessons->first()->id,
            ]);

            return $course;
        });
    }
}
