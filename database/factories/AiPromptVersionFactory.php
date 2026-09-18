<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiPromptVersion;
use App\Models\User;
use App\Services\Ai\PromptKeys;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPromptVersion>
 */
class AiPromptVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prompt_key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'body' => fake()->paragraphs(3, true),
            'version_number' => 1,
            'comment' => fake()->sentence(),
            'created_by' => User::factory(),
            'meta' => null,
        ];
    }

    /**
     * Indicate the version was written by the system (seeder), not a user.
     */
    public function authoredBySystem(): static
    {
        return $this->state(fn (): array => [
            'created_by' => null,
        ]);
    }
}
