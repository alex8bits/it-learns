<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\UserLlmLimit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserLlmLimit>
 */
class UserLlmLimitFactory extends Factory
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
            'extra_tokens' => 0,
        ];
    }

    /**
     * Indicate the user has an admin-granted extra token amount.
     */
    public function withExtra(int $tokens): static
    {
        return $this->state(fn (): array => [
            'extra_tokens' => $tokens,
        ]);
    }
}
