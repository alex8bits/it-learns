<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\LessonProgressStatus;
use App\Models\Lesson;
use App\Models\UserLessonProgress;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserLessonProgressTest extends TestCase
{
    public function test_casts_map_status_enum_and_datetimes(): void
    {
        $casts = (new UserLessonProgress)->getCasts();

        $this->assertSame(LessonProgressStatus::class, $casts['status']);
        $this->assertSame('datetime', $casts['started_at']);
        $this->assertSame('datetime', $casts['completed_at']);
    }

    public function test_fillable_contains_exactly_the_declared_fields(): void
    {
        $this->assertSame(
            ['user_id', 'lesson_id', 'status', 'started_at', 'completed_at'],
            (new UserLessonProgress)->getFillable(),
        );
    }

    public function test_factory_creates_in_progress_lesson_without_completion(): void
    {
        $progress = UserLessonProgress::factory()->create();

        $this->assertSame(LessonProgressStatus::InProgress, $progress->status);
        $this->assertInstanceOf(Carbon::class, $progress->started_at);
        $this->assertNull($progress->completed_at);

        $this->assertDatabaseHas('user_lesson_progress', [
            'id' => $progress->id,
            'user_id' => $progress->user_id,
            'lesson_id' => $progress->lesson_id,
            'status' => LessonProgressStatus::InProgress->value,
            'completed_at' => null,
        ]);
    }

    public function test_completed_state_sets_completed_status_with_timestamp(): void
    {
        $progress = UserLessonProgress::factory()->completed()->create();

        $this->assertSame(LessonProgressStatus::Completed, $progress->status);
        $this->assertInstanceOf(Carbon::class, $progress->completed_at);

        $this->assertDatabaseHas('user_lesson_progress', [
            'id' => $progress->id,
            'status' => LessonProgressStatus::Completed->value,
        ]);
    }

    public function test_lesson_relation_resolves_the_progress_lesson(): void
    {
        $lesson = Lesson::factory()->create();
        $progress = UserLessonProgress::factory()->create(['lesson_id' => $lesson->id]);

        $this->assertTrue($progress->lesson->is($lesson));
    }
}
