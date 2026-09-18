<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\CourseProgressStatus;
use App\Models\Lesson;
use App\Models\UserCourseProgress;
use Tests\TestCase;

class UserCourseProgressTest extends TestCase
{
    public function test_casts_map_status_to_course_progress_status_enum(): void
    {
        $casts = (new UserCourseProgress)->getCasts();

        $this->assertSame(CourseProgressStatus::class, $casts['status']);
    }

    public function test_fillable_contains_exactly_the_declared_fields(): void
    {
        $this->assertSame(
            ['user_id', 'course_id', 'status', 'current_lesson_id'],
            (new UserCourseProgress)->getFillable(),
        );
    }

    public function test_factory_creates_in_progress_course_without_current_lesson(): void
    {
        $progress = UserCourseProgress::factory()->create();

        $this->assertSame(CourseProgressStatus::InProgress, $progress->status);
        $this->assertNull($progress->current_lesson_id);
        $this->assertNull($progress->currentLesson);

        $this->assertDatabaseHas('user_course_progress', [
            'id' => $progress->id,
            'user_id' => $progress->user_id,
            'course_id' => $progress->course_id,
            'status' => CourseProgressStatus::InProgress->value,
            'current_lesson_id' => null,
        ]);
    }

    public function test_completed_state_sets_completed_status(): void
    {
        $progress = UserCourseProgress::factory()->completed()->create();

        $this->assertSame(CourseProgressStatus::Completed, $progress->status);

        $this->assertDatabaseHas('user_course_progress', [
            'id' => $progress->id,
            'status' => CourseProgressStatus::Completed->value,
        ]);
    }

    public function test_current_lesson_relation_resolves_the_pointed_lesson(): void
    {
        $lesson = Lesson::factory()->create();
        $progress = UserCourseProgress::factory()->create(['current_lesson_id' => $lesson->id]);

        $this->assertTrue($progress->currentLesson->is($lesson));
    }
}
