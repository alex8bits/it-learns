<?php

declare(strict_types=1);

namespace Tests\Feature\Practice;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\PracticeTask;
use App\Models\PracticeTaskSubmission;
use App\Models\Subscription;
use App\Models\User;
use Tests\TestCase;

/**
 * Smoke of the premium AI routes (Stage 8): happy paths plus the two
 * HTTP-level behaviours that cannot be covered in Unit — the
 * EnsurePremium gate on the real routes and the global 429 mapping of
 * AiLimitExceededException (bootstrap/app.php).
 */
class AiRoutesTest extends TestCase
{
    public function test_premium_user_gets_ai_feedback_for_a_failed_attempt(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->active()->create(['user_id' => $user]);
        $task = $this->createTask();
        PracticeTaskSubmission::factory()->for($task)->for($user)->failed()->create();

        $response = $this->actingAs($user)
            ->from(route('lessons.show', $task->lesson->slug))
            ->post(route('practice-tasks.ai-feedback', $task));

        $response->assertRedirect(route('lessons.show', $task->lesson->slug));
        $response->assertSessionHas('ai_feedback');
        $this->assertDatabaseHas('practice_task_feedbacks', [
            'user_id' => $user->id,
            'practice_task_submission_id' => PracticeTaskSubmission::query()->sole()->id,
        ]);
    }

    public function test_free_user_is_redirected_to_pricing(): void
    {
        $user = User::factory()->create();
        $task = $this->createTask();

        $response = $this->actingAs($user)
            ->post(route('practice-tasks.ai-feedback', $task));

        $response->assertRedirect(route('pricing'));
        $this->assertDatabaseCount('practice_task_feedbacks', 0);
    }

    public function test_free_user_json_request_gets_403(): void
    {
        $user = User::factory()->create();
        $task = $this->createTask();

        $response = $this->actingAs($user)
            ->postJson(route('practice-tasks.ai-feedback', $task));

        $response->assertForbidden();
        $response->assertJsonPath('message', 'Доступно только с премиум-подпиской.');
    }

    public function test_exhausted_user_limit_maps_to_json_429_without_a_provider_call(): void
    {
        config(['ai.token_limit_per_user_per_day' => 0]);

        $user = User::factory()->create();
        Subscription::factory()->active()->create(['user_id' => $user]);
        $task = $this->createTask();
        PracticeTaskSubmission::factory()->for($task)->for($user)->failed()->create();

        $response = $this->actingAs($user)
            ->postJson(route('practice-tasks.ai-feedback', $task));

        $response->assertStatus(429);
        $this->assertSame('60', $response->headers->get('Retry-After'));
        $response->assertJsonPath('is_global_limit', false);

        // The guard fires before the provider call: no tokens spent, no
        // feedback row written.
        $this->assertDatabaseCount('ai_token_usages', 0);
        $this->assertDatabaseCount('practice_task_feedbacks', 0);
    }

    public function test_exhausted_limit_in_web_context_redirects_back_with_ai_error(): void
    {
        config(['ai.token_limit_per_user_per_day' => 0]);

        $user = User::factory()->create();
        Subscription::factory()->active()->create(['user_id' => $user]);
        $task = $this->createTask();

        $response = $this->actingAs($user)
            ->from(route('lessons.show', $task->lesson->slug))
            ->post(route('practice-tasks.ai-extra-task', $task));

        $response->assertRedirect(route('lessons.show', $task->lesson->slug));
        $response->assertSessionHasErrors('ai');
        $this->assertDatabaseCount('practice_task_feedbacks', 0);
    }

    public function test_premium_user_gets_an_extra_task_without_any_persistence(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->active()->create(['user_id' => $user]);
        $task = $this->createTask();

        $response = $this->actingAs($user)
            ->from(route('lessons.show', $task->lesson->slug))
            ->post(route('practice-tasks.ai-extra-task', $task));

        $response->assertRedirect(route('lessons.show', $task->lesson->slug));
        $response->assertSessionHas('extra_task');
        $this->assertDatabaseCount('practice_tasks', 1);
        $this->assertDatabaseCount('practice_task_submissions', 0);
    }

    /**
     * A published course -> level -> lesson chain with one practice task.
     */
    private function createTask(): PracticeTask
    {
        $course = Course::factory()->published()->create();
        $level = Level::factory()->for($course)->create();
        $lesson = Lesson::factory()->for($level)->create();

        return PracticeTask::factory()->for($lesson)->create();
    }
}
