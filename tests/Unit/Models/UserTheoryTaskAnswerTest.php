<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\TheoryTask;
use App\Models\UserTheoryTaskAnswer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserTheoryTaskAnswerTest extends TestCase
{
    public function test_casts_map_is_correct_to_bool_and_answered_at_to_datetime(): void
    {
        $casts = (new UserTheoryTaskAnswer)->getCasts();

        $this->assertSame('bool', $casts['is_correct']);
        $this->assertSame('datetime', $casts['answered_at']);
    }

    public function test_fillable_contains_exactly_the_declared_fields(): void
    {
        $this->assertSame(
            ['user_id', 'theory_task_id', 'option_id', 'is_correct', 'answered_at'],
            (new UserTheoryTaskAnswer)->getFillable(),
        );
    }

    public function test_model_keeps_no_standard_timestamps(): void
    {
        $answer = UserTheoryTaskAnswer::factory()->create();

        $this->assertFalse((new UserTheoryTaskAnswer)->timestamps);
        $this->assertNull($answer->getAttribute('created_at'));
        $this->assertNull($answer->getAttribute('updated_at'));

        $fresh = UserTheoryTaskAnswer::query()->findOrFail($answer->id);

        $this->assertInstanceOf(Carbon::class, $fresh->answered_at);
    }

    public function test_factory_creates_correct_answer_with_option_of_the_same_task(): void
    {
        $answer = UserTheoryTaskAnswer::factory()->create();

        $this->assertTrue($answer->is_correct);
        $this->assertTrue($answer->option->is_correct);
        $this->assertSame($answer->theory_task_id, $answer->option->theory_task_id);

        $this->assertDatabaseHas('user_theory_task_answers', [
            'id' => $answer->id,
            'user_id' => $answer->user_id,
            'theory_task_id' => $answer->theory_task_id,
            'option_id' => $answer->option_id,
            'is_correct' => 1,
        ]);
    }

    public function test_factory_for_existing_task_resolves_option_from_that_task(): void
    {
        $task = TheoryTask::factory()->withOptions()->create();

        $answer = UserTheoryTaskAnswer::factory()->for($task)->create();

        $this->assertSame($task->id, $answer->theory_task_id);
        $this->assertTrue($answer->option->is_correct);
        $this->assertSame($task->id, $answer->option->theory_task_id);

        // `for()` re-uses the given task instead of creating a new one.
        $this->assertSame(1, TheoryTask::query()->count());
    }

    public function test_factory_for_task_without_correct_option_fails_on_insert(): void
    {
        $task = TheoryTask::factory()->create();

        // The `option_id` closure only searches for an existing correct
        // option (it never creates one): a task without one resolves the
        // foreign key to null, and the NOT NULL column rejects the insert.
        $raw = UserTheoryTaskAnswer::factory()->for($task)->raw();

        $this->assertNull($raw['option_id']);

        $this->expectException(QueryException::class);

        UserTheoryTaskAnswer::factory()->for($task)->create();
    }
}
