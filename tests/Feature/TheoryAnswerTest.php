<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\User;
use Database\Seeders\DemoCourseSeeder;
use Tests\TestCase;

class TheoryAnswerTest extends TestCase
{
    public function test_correct_answer_is_recorded_and_redirects_back_to_lesson(): void
    {
        $this->seed(DemoCourseSeeder::class);
        $user = User::factory()->create();

        $lesson = Lesson::query()->where('slug', 'select-basics')->firstOrFail();
        $task = $lesson->theoryTasks()->where('order', 1)->firstOrFail();
        $correctOption = $task->options()->where('is_correct', true)->firstOrFail();

        $response = $this->actingAs($user)
            ->from(route('lessons.show', 'select-basics'))
            ->post(route('theory-tasks.answer', $task), ['option_id' => $correctOption->id]);

        $response->assertRedirect(route('lessons.show', 'select-basics'));
        $this->assertDatabaseHas('user_theory_task_answers', [
            'user_id' => $user->id,
            'theory_task_id' => $task->id,
            'option_id' => $correctOption->id,
            'is_correct' => true,
        ]);
    }
}
