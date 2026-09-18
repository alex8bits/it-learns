<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiTokenUsageAction;
use App\Models\AiTokenUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiTokenUsage>
 */
class AiTokenUsageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'model' => 'gpt-test',
            'tokens' => fake()->numberBetween(10, 500),
            'action' => AiTokenUsageAction::Feedback,
        ];
    }

    /**
     * Indicate the tokens were spent on extra-task generation.
     */
    public function extraTask(): static
    {
        return $this->state(fn (): array => [
            'action' => AiTokenUsageAction::ExtraTask,
        ]);
    }
}
