<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TheoryTask;
use App\Models\TheoryTaskOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TheoryTaskOption>
 */
class TheoryTaskOptionFactory extends Factory
{
    /**
     * Define the model's default state: an incorrect option with a Russian
     * explanation (quiz safety: a random option is never correct).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'theory_task_id' => TheoryTask::factory(),
            'text' => fake()->sentence(3),
            'is_correct' => false,
            'error_text' => 'Этот вариант не соответствует правильному ответу.',
            'order' => 1,
        ];
    }
}
