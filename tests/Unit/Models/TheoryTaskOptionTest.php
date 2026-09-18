<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\TheoryTask;
use App\Models\TheoryTaskOption;
use Tests\TestCase;

class TheoryTaskOptionTest extends TestCase
{
    public function test_casts_map_is_correct_to_bool(): void
    {
        $casts = (new TheoryTaskOption)->getCasts();

        $this->assertSame('bool', $casts['is_correct']);
    }

    public function test_fillable_contains_exactly_the_declared_fields(): void
    {
        $this->assertSame(
            ['theory_task_id', 'text', 'is_correct', 'error_text', 'order'],
            (new TheoryTaskOption)->getFillable(),
        );
    }

    public function test_factory_creates_incorrect_option_with_error_text(): void
    {
        $option = TheoryTaskOption::factory()->create();

        $this->assertFalse($option->is_correct);
        $this->assertIsString($option->text);
        $this->assertNotSame('', $option->text);
        $this->assertIsString($option->error_text);
        $this->assertNotSame('', $option->error_text);
        $this->assertSame(1, $option->order);

        $this->assertDatabaseHas('theory_task_options', [
            'id' => $option->id,
            'theory_task_id' => $option->theory_task_id,
            'is_correct' => 0,
            'order' => 1,
        ]);
    }

    public function test_theory_task_relation_resolves_the_parent_task(): void
    {
        $task = TheoryTask::factory()->create();
        $option = TheoryTaskOption::factory()->create(['theory_task_id' => $task->id]);

        $this->assertTrue($option->theoryTask->is($task));
    }
}
