<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\PracticeRuntime;
use App\Models\Lesson;
use App\Models\PracticeTask;
use App\Models\PracticeTaskSubmission;
use App\Services\Practice\CanonicalResultSerializer;
use App\Services\Practice\Dto\PracticeTaskInput;
use Tests\TestCase;

class PracticeTaskTest extends TestCase
{
    public function test_casts_map_expected_rows_to_array_is_published_to_bool_and_runtime_to_enum(): void
    {
        $casts = (new PracticeTask)->getCasts();

        $this->assertSame('array', $casts['expected_rows']);
        $this->assertSame('bool', $casts['is_published']);
        $this->assertSame(PracticeRuntime::class, $casts['runtime']);
    }

    public function test_fillable_contains_exactly_the_declared_fields(): void
    {
        $this->assertSame(
            ['lesson_id', 'statement', 'expected_result_text', 'expected_rows', 'seed_sql', 'expected_hash', 'order', 'is_published', 'runtime'],
            (new PracticeTask)->getFillable(),
        );
    }

    public function test_factory_creates_published_task_with_derived_hash(): void
    {
        $task = PracticeTask::factory()->create();

        $this->assertTrue($task->is_published);
        $this->assertSame(1, $task->order);
        $this->assertNull($task->seed_sql);
        $this->assertIsArray($task->expected_rows);
        $this->assertNotEmpty($task->expected_rows);
        $this->assertIsString($task->statement);
        $this->assertNotSame('', $task->statement);

        // The stored hash is exactly the canonical hash of the expected
        // rows with the columns taken from the first row.
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $task->expected_rows;

        $expectedHash = app(CanonicalResultSerializer::class)
            ->hash($rows, array_keys($rows[0]));

        $this->assertSame($expectedHash, $task->expected_hash);

        $this->assertDatabaseHas('practice_tasks', [
            'id' => $task->id,
            'lesson_id' => $task->lesson_id,
            'order' => 1,
            'is_published' => 1,
        ]);
    }

    public function test_factory_recomputes_hash_when_expected_rows_are_overridden(): void
    {
        $rows = [
            ['id' => 5, 'name' => 'Alice'],
            ['id' => 6, 'name' => 'Bob'],
        ];

        $task = PracticeTask::factory()->create(['expected_rows' => $rows]);

        $this->assertSame(
            app(CanonicalResultSerializer::class)->hash($rows, ['id', 'name']),
            $task->expected_hash,
        );
    }

    public function test_unpublished_state_hides_task_from_lesson_flow(): void
    {
        $task = PracticeTask::factory()->unpublished()->create();

        $this->assertFalse($task->is_published);
        $this->assertDatabaseHas('practice_tasks', [
            'id' => $task->id,
            'is_published' => 0,
        ]);
    }

    public function test_with_seed_script_state_provides_seed_sql(): void
    {
        $task = PracticeTask::factory()->withSeedScript()->create();

        $this->assertIsString($task->seed_sql);
        $this->assertStringContainsString('CREATE TABLE', $task->seed_sql);
        $this->assertStringContainsString('INSERT INTO', $task->seed_sql);
    }

    public function test_lesson_relation_resolves_the_parent_lesson(): void
    {
        $lesson = Lesson::factory()->create();
        $task = PracticeTask::factory()->create(['lesson_id' => $lesson->id]);

        $this->assertTrue($task->lesson->is($lesson));
    }

    public function test_submissions_relation_lists_attempts_of_the_task(): void
    {
        $task = PracticeTask::factory()->create();

        $submission = PracticeTaskSubmission::factory()->for($task)->create();

        $this->assertTrue($task->submissions->first()->is($submission));
    }

    public function test_lesson_practice_tasks_relation_sorts_tasks_by_order_column(): void
    {
        $lesson = Lesson::factory()->create();

        PracticeTask::factory()->create(['lesson_id' => $lesson->id, 'order' => 2]);
        PracticeTask::factory()->create(['lesson_id' => $lesson->id, 'order' => 3]);
        PracticeTask::factory()->create(['lesson_id' => $lesson->id, 'order' => 1]);

        $this->assertSame([1, 2, 3], $lesson->practiceTasks->pluck('order')->all());
    }

    public function test_published_scope_returns_only_published_tasks(): void
    {
        $lesson = Lesson::factory()->create();

        $published = PracticeTask::factory()->create(['lesson_id' => $lesson->id]);
        PracticeTask::factory()->unpublished()->create(['lesson_id' => $lesson->id]);

        $visible = PracticeTask::query()->published()->pluck('id');

        $this->assertSame([$published->id], $visible->all());
    }

    public function test_ordered_scope_sorts_tasks_by_order_column(): void
    {
        $lesson = Lesson::factory()->create();

        PracticeTask::factory()->create(['lesson_id' => $lesson->id, 'order' => 3]);
        PracticeTask::factory()->create(['lesson_id' => $lesson->id, 'order' => 1]);
        PracticeTask::factory()->create(['lesson_id' => $lesson->id, 'order' => 2]);

        $this->assertSame([1, 2, 3], PracticeTask::query()->ordered()->pluck('order')->all());
    }

    public function test_to_input_maps_the_model_fields_onto_the_dto(): void
    {
        $task = PracticeTask::factory()->withSeedScript()->create();

        $input = $task->toInput();

        $this->assertInstanceOf(PracticeTaskInput::class, $input);
        $this->assertSame($task->id, $input->taskId);
        $this->assertSame($task->statement, $input->taskText);
        $this->assertSame($task->seed_sql, $input->seedScript);
        $this->assertSame($task->expected_hash, $input->expectedHash);
        $this->assertSame($task->runtime, $input->runtime);
    }

    public function test_to_input_keeps_seed_script_nullable(): void
    {
        $input = PracticeTask::factory()->create()->toInput();

        $this->assertNull($input->seedScript);
        $this->assertNotNull($input->taskId);
        $this->assertNotNull($input->taskText);
        $this->assertNotNull($input->expectedHash);
    }

    public function test_to_input_passes_the_task_runtime_as_an_enum_instance(): void
    {
        $task = PracticeTask::factory()->create(['runtime' => PracticeRuntime::Mysql]);

        $input = $task->toInput();

        $this->assertSame(PracticeRuntime::Mysql, $input->runtime);
        $this->assertDatabaseHas('practice_tasks', [
            'id' => $task->id,
            'runtime' => PracticeRuntime::Mysql->value,
        ]);
    }

    public function test_runtime_column_defaults_to_sqlite_for_existing_content(): void
    {
        $task = PracticeTask::factory()->create();

        // The model instance never loaded the attribute (the factory
        // does not fill it), so the DTO keeps the driver-default null;
        // the column default backfills the stored row to sqlite.
        $this->assertNull($task->toInput()->runtime);
        $this->assertSame(PracticeRuntime::Sqlite, $task->fresh()->runtime);
        $this->assertDatabaseHas('practice_tasks', [
            'id' => $task->id,
            'runtime' => PracticeRuntime::Sqlite->value,
        ]);
    }
}
