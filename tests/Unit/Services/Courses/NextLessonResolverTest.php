<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Courses;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Services\Courses\NextLessonResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NextLessonResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_next_lesson_within_the_same_level(): void
    {
        $course = $this->course();
        $level = $this->level($course, 1);
        $first = $this->lesson($level, 1);
        $second = $this->lesson($level, 2);
        $this->lesson($level, 3);

        $next = (new NextLessonResolver)($course, $first);

        $this->assertNotNull($next);
        $this->assertTrue($next->is($second));
    }

    public function test_crosses_the_level_boundary_to_the_first_lesson_of_the_next_level(): void
    {
        $course = $this->course();
        $firstLevel = $this->level($course, 1);
        $secondLevel = $this->level($course, 2);
        $this->lesson($firstLevel, 1);
        $lastOfFirstLevel = $this->lesson($firstLevel, 2);
        $firstOfSecondLevel = $this->lesson($secondLevel, 1);
        $this->lesson($secondLevel, 2);

        $next = (new NextLessonResolver)($course, $lastOfFirstLevel);

        $this->assertNotNull($next);
        $this->assertTrue($next->is($firstOfSecondLevel));
    }

    public function test_last_lesson_of_the_course_returns_null(): void
    {
        $course = $this->course();
        $level = $this->level($course, 1);
        $this->lesson($level, 1);
        $last = $this->lesson($level, 2);

        $this->assertNull((new NextLessonResolver)($course, $last));
    }

    public function test_unpublished_lessons_are_skipped_by_default(): void
    {
        $course = $this->course();
        $level = $this->level($course, 1);
        $first = $this->lesson($level, 1);
        $this->lesson($level, 2, published: false);
        $third = $this->lesson($level, 3);

        $next = (new NextLessonResolver)($course, $first);

        $this->assertNotNull($next);
        $this->assertTrue($next->is($third));
    }

    public function test_preview_mode_with_published_only_false_sees_drafts(): void
    {
        $course = $this->course();
        $level = $this->level($course, 1);
        $first = $this->lesson($level, 1);
        $draft = $this->lesson($level, 2, published: false);
        $this->lesson($level, 3);

        $next = (new NextLessonResolver)($course, $first, publishedOnly: false);

        $this->assertNotNull($next);
        $this->assertTrue($next->is($draft));
    }

    public function test_single_lesson_course_returns_null(): void
    {
        $course = $this->course();
        $level = $this->level($course, 1);
        $only = $this->lesson($level, 1);

        $this->assertNull((new NextLessonResolver)($course, $only));
    }

    public function test_current_lesson_missing_from_the_published_list_returns_null(): void
    {
        $course = $this->course();
        $level = $this->level($course, 1);
        $this->lesson($level, 1);
        $draftCurrent = $this->lesson($level, 2, published: false);
        $this->lesson($level, 3);

        // The unpublished current lesson is filtered out of the list,
        // so there is no index to look past — null, never a fallback.
        $this->assertNull((new NextLessonResolver)($course, $draftCurrent));
    }

    public function test_level_order_takes_precedence_over_lesson_order(): void
    {
        $course = $this->course();
        $firstLevel = $this->level($course, 1);
        $secondLevel = $this->level($course, 2);
        $this->lesson($firstLevel, 1);
        $endOfFirstLevel = $this->lesson($firstLevel, 10);
        $startOfSecondLevel = $this->lesson($secondLevel, 5);

        // Lesson order 5 of level 2 comes AFTER lesson order 10 of
        // level 1: the level bucket wins over the raw lesson order.
        $next = (new NextLessonResolver)($course, $endOfFirstLevel);

        $this->assertNotNull($next);
        $this->assertTrue($next->is($startOfSecondLevel));
    }

    public function test_resolves_with_a_constant_two_queries(): void
    {
        $course = $this->course();
        $firstLevel = $this->level($course, 1);
        $secondLevel = $this->level($course, 2);
        $current = $this->lesson($firstLevel, 1);
        $this->lesson($firstLevel, 2);
        $this->lesson($secondLevel, 1);

        DB::enableQueryLog();
        DB::flushQueryLog();

        (new NextLessonResolver)($course, $current);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Exactly one query for the lessons plus one eager load of the
        // levels — never one query per lesson (no N+1, rule №9).
        $this->assertCount(2, $queries);
    }

    /**
     * A published course (level `order` values matter to the resolver,
     * the course status does not — it is the caller's concern).
     */
    private function course(): Course
    {
        return Course::factory()->published()->create();
    }

    /**
     * A level of the course at the given position.
     */
    private function level(Course $course, int $order): Level
    {
        return Level::factory()->for($course)->create(['order' => $order]);
    }

    /**
     * A lesson of the level at the given position, published unless
     * stated otherwise.
     */
    private function lesson(Level $level, int $order, bool $published = true): Lesson
    {
        $factory = Lesson::factory()->for($level);

        if (! $published) {
            $factory = $factory->unpublished();
        }

        return $factory->create(['order' => $order]);
    }
}
