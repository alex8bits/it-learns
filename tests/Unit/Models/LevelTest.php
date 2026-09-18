<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use Tests\TestCase;

class LevelTest extends TestCase
{
    public function test_fillable_contains_exactly_the_declared_fields(): void
    {
        $this->assertSame(
            ['course_id', 'title', 'order'],
            (new Level)->getFillable(),
        );
    }

    public function test_level_model_disables_timestamps(): void
    {
        $this->assertFalse((new Level)->timestamps);
    }

    public function test_factory_creates_level_with_unique_title_and_order_one(): void
    {
        $level = Level::factory()->create();

        $this->assertIsString($level->title);
        $this->assertNotSame('', $level->title);
        $this->assertSame(1, $level->order);

        $this->assertDatabaseHas('levels', [
            'id' => $level->id,
            'course_id' => $level->course_id,
            'title' => $level->title,
            'order' => 1,
        ]);
    }

    public function test_course_relation_resolves_the_parent_course(): void
    {
        $course = Course::factory()->create();
        $level = Level::factory()->create(['course_id' => $course->id]);

        $this->assertTrue($level->course->is($course));
    }

    public function test_lessons_relation_is_ordered_by_order_column(): void
    {
        $level = Level::factory()->create();

        Lesson::factory()->create(['level_id' => $level->id, 'order' => 3]);
        Lesson::factory()->create(['level_id' => $level->id, 'order' => 1]);
        Lesson::factory()->create(['level_id' => $level->id, 'order' => 2]);

        $this->assertSame([1, 2, 3], $level->lessons->pluck('order')->all());
    }

    public function test_ordered_scope_sorts_levels_by_order_column(): void
    {
        $course = Course::factory()->create();

        Level::factory()->create(['course_id' => $course->id, 'title' => 'Третий', 'order' => 3]);
        Level::factory()->create(['course_id' => $course->id, 'title' => 'Первый', 'order' => 1]);
        Level::factory()->create(['course_id' => $course->id, 'title' => 'Второй', 'order' => 2]);

        $this->assertSame(
            ['Первый', 'Второй', 'Третий'],
            Level::query()->ordered()->pluck('title')->all(),
        );
    }
}
