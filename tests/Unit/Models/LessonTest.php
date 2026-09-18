<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Lesson;
use App\Models\Level;
use Tests\TestCase;

class LessonTest extends TestCase
{
    public function test_casts_map_is_published_to_bool(): void
    {
        $casts = (new Lesson)->getCasts();

        $this->assertSame('bool', $casts['is_published']);
    }

    public function test_fillable_contains_exactly_the_declared_fields(): void
    {
        $this->assertSame(
            ['level_id', 'slug', 'title', 'order', 'material', 'is_published'],
            (new Lesson)->getFillable(),
        );
    }

    public function test_factory_creates_published_lesson_with_material(): void
    {
        $lesson = Lesson::factory()->create();

        $this->assertTrue($lesson->is_published);
        $this->assertSame(1, $lesson->order);
        $this->assertIsString($lesson->material);
        $this->assertNotSame('', $lesson->material);

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'level_id' => $lesson->level_id,
            'order' => 1,
            'is_published' => 1,
        ]);
    }

    public function test_unpublished_state_hides_lesson_from_public(): void
    {
        $lesson = Lesson::factory()->unpublished()->create();

        $this->assertFalse($lesson->is_published);
        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'is_published' => 0,
        ]);
    }

    public function test_is_published_round_trips_as_bool(): void
    {
        $lesson = Lesson::factory()->unpublished()->create();

        $fresh = Lesson::query()->findOrFail($lesson->id);

        $this->assertFalse($fresh->is_published);
        $this->assertSame(0, (int) $fresh->getRawOriginal('is_published'));
    }

    public function test_level_relation_resolves_the_parent_level(): void
    {
        $level = Level::factory()->create();
        $lesson = Lesson::factory()->create(['level_id' => $level->id]);

        $this->assertTrue($lesson->level->is($level));
    }

    public function test_published_scope_returns_only_published_lessons(): void
    {
        $level = Level::factory()->create();

        $published = Lesson::factory()->create(['level_id' => $level->id]);
        Lesson::factory()->unpublished()->create(['level_id' => $level->id]);

        $visible = Lesson::query()->published()->pluck('id');

        $this->assertSame([$published->id], $visible->all());
    }

    public function test_ordered_scope_sorts_lessons_by_order_column(): void
    {
        $level = Level::factory()->create();

        Lesson::factory()->create(['level_id' => $level->id, 'order' => 3]);
        Lesson::factory()->create(['level_id' => $level->id, 'order' => 1]);
        Lesson::factory()->create(['level_id' => $level->id, 'order' => 2]);

        $this->assertSame([1, 2, 3], Lesson::query()->ordered()->pluck('order')->all());
    }
}
