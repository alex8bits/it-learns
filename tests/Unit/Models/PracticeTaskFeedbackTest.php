<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\PracticeTaskFeedback;
use App\Models\PracticeTaskSubmission;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PracticeTaskFeedbackTest extends TestCase
{
    public function test_casts_map_created_at_to_datetime(): void
    {
        $casts = (new PracticeTaskFeedback)->getCasts();

        $this->assertSame('datetime', $casts['created_at']);
    }

    public function test_fillable_contains_exactly_the_declared_fields(): void
    {
        $this->assertSame(
            ['practice_task_submission_id', 'user_id', 'body', 'created_at'],
            (new PracticeTaskFeedback)->getFillable(),
        );
    }

    public function test_model_keeps_no_standard_timestamps(): void
    {
        $feedback = PracticeTaskFeedback::factory()->create();

        $this->assertFalse((new PracticeTaskFeedback)->timestamps);
        $this->assertNull($feedback->getAttribute('updated_at'));

        $fresh = PracticeTaskFeedback::query()->findOrFail($feedback->id);

        $this->assertInstanceOf(Carbon::class, $fresh->created_at);
        $this->assertNull($fresh->getAttribute('updated_at'));
    }

    public function test_factory_creates_feedback_owned_by_the_attempts_user(): void
    {
        $feedback = PracticeTaskFeedback::factory()->create();

        $this->assertIsString($feedback->body);
        $this->assertNotSame('', $feedback->body);
        $this->assertInstanceOf(Carbon::class, $feedback->created_at);
        $this->assertSame($feedback->submission->user_id, $feedback->user_id);

        $this->assertDatabaseHas('practice_task_feedbacks', [
            'id' => $feedback->id,
            'practice_task_submission_id' => $feedback->practice_task_submission_id,
            'user_id' => $feedback->user_id,
        ]);
    }

    public function test_factory_for_existing_submission_resolves_its_user(): void
    {
        $submission = PracticeTaskFeedback::factory()->create()->submission;

        $feedback = PracticeTaskFeedback::factory()->for($submission, 'submission')->create();

        $this->assertSame($submission->id, $feedback->practice_task_submission_id);
        $this->assertSame($submission->user_id, $feedback->user_id);

        // `for()` re-uses the given submission instead of creating a new one.
        $this->assertSame(1, PracticeTaskSubmission::query()->count());
    }

    public function test_relations_resolve_submission_and_user(): void
    {
        $user = User::factory()->create();
        $submission = PracticeTaskSubmission::factory()->for($user)->create();

        $feedback = PracticeTaskFeedback::factory()->for($submission, 'submission')->create();

        $this->assertTrue($feedback->submission->is($submission));
        $this->assertTrue($feedback->user->is($user));
    }
}
