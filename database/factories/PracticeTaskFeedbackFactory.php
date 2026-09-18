<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PracticeTaskFeedback;
use App\Models\PracticeTaskSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PracticeTaskFeedback>
 */
class PracticeTaskFeedbackFactory extends Factory
{
    /**
     * Define the model's default state. The `user_id` closure receives
     * the already-expanded `practice_task_submission_id` and resolves
     * the owner of that attempt — the feedback always belongs to the
     * user who made the attempt, so explicit attributes and `for()`
     * both keep the pair consistent.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'practice_task_submission_id' => PracticeTaskSubmission::factory(),
            'user_id' => fn (array $attributes): int => (int) PracticeTaskSubmission::query()
                ->where('id', $attributes['practice_task_submission_id'])
                ->value('user_id'),
            'body' => 'Запрос выбирает только столбец title, а ожидаемый результат содержит также год каждой книги.',
            'created_at' => now(),
        ];
    }
}
