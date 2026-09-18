<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Lesson;
use App\Models\TheoryTask;
use App\Models\TheoryTaskOption;
use Tests\TestCase;

class TheoryTaskTest extends TestCase
{
    public function test_casts_map_is_published_to_bool(): void
    {
        $casts = (new TheoryTask)->getCasts();

        $this->assertSame('bool', $casts['is_published']);
    }

    public function test_fillable_contains_exactly_the_declared_fields(): void
    {
        $this->assertSame(
            ['lesson_id', 'question', 'order', 'is_published'],
            (new TheoryTask)->getFillable(),
        );
    }

    public function test_factory_creates_published_task_without_options(): void
    {
        $task = TheoryTask::factory()->create();

        $this->assertTrue($task->is_published);
        $this->assertSame(1, $task->order);
        $this->assertIsString($task->question);
        $this->assertNotSame('', $task->question);
        $this->assertCount(0, $task->options);

        $this->assertDatabaseHas('theory_tasks', [
            'id' => $task->id,
            'lesson_id' => $task->lesson_id,
            'order' => 1,
            'is_published' => 1,
        ]);
    }

    public function test_unpublished_state_hides_task_from_lesson_flow(): void
    {
        $task = TheoryTask::factory()->unpublished()->create();

        $this->assertFalse($task->is_published);
        $this->assertDatabaseHas('theory_tasks', [
            'id' => $task->id,
            'is_published' => 0,
        ]);
    }

    public function test_lesson_relation_resolves_the_parent_lesson(): void
    {
        $lesson = Lesson::factory()->create();
        $task = TheoryTask::factory()->create(['lesson_id' => $lesson->id]);

        $this->assertTrue($task->lesson->is($lesson));
    }

    public function test_published_scope_returns_only_published_tasks(): void
    {
        $lesson = Lesson::factory()->create();

        $published = TheoryTask::factory()->create(['lesson_id' => $lesson->id]);
        TheoryTask::factory()->unpublished()->create(['lesson_id' => $lesson->id]);

        $visible = TheoryTask::query()->published()->pluck('id');

        $this->assertSame([$published->id], $visible->all());
    }

    public function test_ordered_scope_sorts_tasks_by_order_column(): void
    {
        $lesson = Lesson::factory()->create();

        TheoryTask::factory()->create(['lesson_id' => $lesson->id, 'order' => 3]);
        TheoryTask::factory()->create(['lesson_id' => $lesson->id, 'order' => 1]);
        TheoryTask::factory()->create(['lesson_id' => $lesson->id, 'order' => 2]);

        $this->assertSame([1, 2, 3], TheoryTask::query()->ordered()->pluck('order')->all());
    }

    public function test_options_relation_sorts_options_by_order_column(): void
    {
        $task = TheoryTask::factory()->create();

        TheoryTaskOption::factory()->createMany([
            ['theory_task_id' => $task->id, 'order' => 2],
            ['theory_task_id' => $task->id, 'order' => 3],
            ['theory_task_id' => $task->id, 'order' => 1],
        ]);

        $this->assertSame([1, 2, 3], $task->options->pluck('order')->all());
    }

    public function test_with_options_state_creates_one_correct_option_with_error_texts(): void
    {
        $task = TheoryTask::factory()->withOptions()->create();

        $options = $task->options;

        $this->assertCount(3, $options);
        $this->assertSame([1, 2, 3], $options->pluck('order')->all());
        $this->assertSame([true, false, false], $options->pluck('is_correct')->all());

        foreach ($options as $option) {
            if ($option->is_correct) {
                $this->assertNull($option->error_text);
            } else {
                $this->assertIsString($option->error_text);
                $this->assertNotSame('', $option->error_text);
            }
        }
    }
}
