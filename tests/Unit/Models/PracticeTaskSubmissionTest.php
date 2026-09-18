<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\PracticeAttemptStatus;
use App\Models\PracticeTask;
use App\Models\PracticeTaskFeedback;
use App\Models\PracticeTaskSubmission;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PracticeTaskSubmissionTest extends TestCase
{
    public function test_casts_map_status_to_enum_result_diff_to_array_and_created_at_to_datetime(): void
    {
        $casts = (new PracticeTaskSubmission)->getCasts();

        $this->assertSame(PracticeAttemptStatus::class, $casts['status']);
        $this->assertSame('array', $casts['result_diff']);
        $this->assertSame('datetime', $casts['created_at']);
    }

    public function test_fillable_contains_exactly_the_declared_fields(): void
    {
        $this->assertSame(
            ['user_id', 'practice_task_id', 'code', 'status', 'result_diff', 'error_text', 'duration_ms', 'created_at'],
            (new PracticeTaskSubmission)->getFillable(),
        );
    }

    public function test_model_keeps_no_standard_timestamps(): void
    {
        $submission = PracticeTaskSubmission::factory()->create();

        $this->assertFalse((new PracticeTaskSubmission)->timestamps);
        $this->assertNull($submission->getAttribute('updated_at'));

        $fresh = PracticeTaskSubmission::query()->findOrFail($submission->id);

        $this->assertInstanceOf(Carbon::class, $fresh->created_at);
        $this->assertNull($fresh->getAttribute('updated_at'));
    }

    public function test_factory_creates_failed_attempt_by_default(): void
    {
        $submission = PracticeTaskSubmission::factory()->create();

        $this->assertSame(PracticeAttemptStatus::Failed, $submission->status);
        $this->assertNull($submission->result_diff);
        $this->assertNull($submission->error_text);
        $this->assertNull($submission->duration_ms);
        $this->assertInstanceOf(Carbon::class, $submission->created_at);

        $this->assertDatabaseHas('practice_task_submissions', [
            'id' => $submission->id,
            'user_id' => $submission->user_id,
            'practice_task_id' => $submission->practice_task_id,
            'status' => PracticeAttemptStatus::Failed->value,
        ]);
    }

    public function test_passed_state_marks_the_task_solved(): void
    {
        $submission = PracticeTaskSubmission::factory()->passed()->create();

        $this->assertSame(PracticeAttemptStatus::Passed, $submission->status);
        $this->assertDatabaseHas('practice_task_submissions', [
            'id' => $submission->id,
            'status' => PracticeAttemptStatus::Passed->value,
        ]);
    }

    public function test_failed_state_keeps_the_default_outcome(): void
    {
        $submission = PracticeTaskSubmission::factory()->failed()->create();

        $this->assertSame(PracticeAttemptStatus::Failed, $submission->status);
    }

    public function test_error_state_carries_error_text(): void
    {
        $submission = PracticeTaskSubmission::factory()->error()->create();

        $this->assertSame(PracticeAttemptStatus::Error, $submission->status);
        $this->assertIsString($submission->error_text);
        $this->assertNotSame('', $submission->error_text);
        $this->assertNull($submission->result_diff);
    }

    public function test_result_diff_round_trips_through_the_json_column(): void
    {
        $diff = [
            'expected' => [['title' => 'SQL Basics', 'year' => 2020]],
            'actual' => [['title' => 'Advanced SQL', 'year' => 2021]],
        ];

        $submission = PracticeTaskSubmission::factory()->create([
            'result_diff' => $diff,
            'duration_ms' => 42,
        ]);

        $fresh = PracticeTaskSubmission::query()->findOrFail($submission->id);

        $this->assertSame($diff, $fresh->result_diff);
        $this->assertSame(42, $fresh->duration_ms);
    }

    public function test_relations_resolve_user_task_and_feedbacks(): void
    {
        $user = User::factory()->create();
        $task = PracticeTask::factory()->create();
        $submission = PracticeTaskSubmission::factory()->for($user)->for($task)->create();

        $feedback = PracticeTaskFeedback::factory()->for($submission, 'submission')->create();

        $this->assertTrue($submission->user->is($user));
        $this->assertTrue($submission->practiceTask->is($task));
        $this->assertTrue($submission->feedbacks->first()->is($feedback));
    }

    public function test_attempts_are_not_limited_by_a_unique_constraint(): void
    {
        $submission = PracticeTaskSubmission::factory()->create();

        PracticeTaskSubmission::factory()
            ->for($submission->user)
            ->for($submission->practiceTask)
            ->failed()
            ->create();

        $this->assertSame(2, PracticeTaskSubmission::query()->count());
        $this->assertSame(
            2,
            PracticeTaskSubmission::query()
                ->where('user_id', $submission->user_id)
                ->where('practice_task_id', $submission->practice_task_id)
                ->count(),
        );
    }
}
