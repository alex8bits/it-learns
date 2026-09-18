<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Progress;

use App\Actions\Progress\StartCourse;
use App\Enums\CourseProgressStatus;
use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\User;
use App\Models\UserCourseProgress;
use App\Models\UserLessonProgress;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class StartCourseTest extends TestCase
{
    private User $user;

    private Course $course;

    private Lesson $firstLesson;

    private Lesson $secondLesson;

    private Lesson $levelTwoLesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->course = Course::factory()->published()->create();

        $firstLevel = Level::factory()
            ->for($this->course)
            ->create(['order' => 1]);
        $secondLevel = Level::factory()
            ->for($this->course)
            ->create(['order' => 2]);

        // Sorts before every published lesson but must never be resolved.
        Lesson::factory()->for($firstLevel)->unpublished()->create(['order' => 0]);
        $this->firstLesson = Lesson::factory()->for($firstLevel)->create(['order' => 1]);
        $this->secondLesson = Lesson::factory()->for($firstLevel)->create(['order' => 2]);
        $this->levelTwoLesson = Lesson::factory()->for($secondLevel)->create(['order' => 1]);
    }

    public function test_first_start_creates_progress_and_returns_first_published_lesson(): void
    {
        $lesson = app(StartCourse::class)->execute($this->user, $this->course);

        $this->assertSame($this->firstLesson->id, $lesson->id);

        $this->assertDatabaseHas('user_course_progress', [
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'status' => CourseProgressStatus::InProgress->value,
            'current_lesson_id' => $this->firstLesson->id,
        ]);
    }

    public function test_repeated_start_keeps_a_single_row_and_does_not_reset_status(): void
    {
        UserCourseProgress::factory()->completed()->for($this->user)->for($this->course)->create();

        $first = app(StartCourse::class)->execute($this->user, $this->course);
        $second = app(StartCourse::class)->execute($this->user, $this->course);

        $this->assertSame($this->firstLesson->id, $first->id);
        $this->assertSame($this->firstLesson->id, $second->id);
        $this->assertDatabaseCount('user_course_progress', 1);
        $this->assertDatabaseHas('user_course_progress', [
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'status' => CourseProgressStatus::Completed->value,
            'current_lesson_id' => $this->firstLesson->id,
        ]);
    }

    public function test_completed_first_lesson_resolves_to_the_next_published_lesson(): void
    {
        UserLessonProgress::factory()->completed()->for($this->user)->for($this->firstLesson)->create();

        $lesson = app(StartCourse::class)->execute($this->user, $this->course);

        $this->assertSame($this->secondLesson->id, $lesson->id);
        $this->assertDatabaseHas('user_course_progress', [
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'current_lesson_id' => $this->secondLesson->id,
        ]);
    }

    public function test_in_progress_lesson_is_not_skipped(): void
    {
        UserLessonProgress::factory()->for($this->user)->for($this->firstLesson)->create();

        $lesson = app(StartCourse::class)->execute($this->user, $this->course);

        $this->assertSame($this->firstLesson->id, $lesson->id);
    }

    public function test_fully_completed_course_restarts_from_the_first_published_lesson(): void
    {
        foreach ([$this->firstLesson, $this->secondLesson, $this->levelTwoLesson] as $lesson) {
            UserLessonProgress::factory()->completed()->for($this->user)->for($lesson)->create();
        }

        $lesson = app(StartCourse::class)->execute($this->user, $this->course);

        $this->assertSame($this->firstLesson->id, $lesson->id);
    }

    public function test_current_lesson_pointer_moves_to_the_unstarted_lesson(): void
    {
        UserCourseProgress::factory()->for($this->user)->for($this->course)->create();
        UserLessonProgress::factory()->completed()->for($this->user)->for($this->firstLesson)->create();

        app(StartCourse::class)->execute($this->user, $this->course);

        $this->assertDatabaseHas('user_course_progress', [
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'current_lesson_id' => $this->secondLesson->id,
        ]);
    }

    #[DataProvider('unpublishedCourseStatusProvider')]
    public function test_unpublished_course_throws_and_writes_nothing(CourseStatus $status): void
    {
        $course = Course::factory()->create(['status' => $status]);

        try {
            app(StartCourse::class)->execute($this->user, $course);
            $this->fail('Expected NotFoundHttpException for an unpublished course.');
        } catch (NotFoundHttpException) {
            // Expected: guard semantics, nothing to start.
        }

        $this->assertDatabaseCount('user_course_progress', 0);
    }

    /**
     * @return array<string, array{0: CourseStatus}>
     */
    public static function unpublishedCourseStatusProvider(): array
    {
        return [
            'draft course' => [CourseStatus::Draft],
            'archived course' => [CourseStatus::Archived],
        ];
    }

    public function test_course_without_published_lessons_throws_and_writes_nothing(): void
    {
        $course = Course::factory()->published()->create();
        $level = Level::factory()->for($course)->create(['order' => 1]);
        Lesson::factory()->for($level)->unpublished()->create();

        try {
            app(StartCourse::class)->execute($this->user, $course);
            $this->fail('Expected NotFoundHttpException for a course without published lessons.');
        } catch (NotFoundHttpException) {
            // Expected: no lesson to point the progress at.
        }

        $this->assertDatabaseCount('user_course_progress', 0);
    }
}
