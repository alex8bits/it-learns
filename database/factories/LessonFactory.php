<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\Level;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    /**
     * Define the model's default state: a published lesson with study
     * material, first in its level.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'level_id' => Level::factory(),
            'slug' => fake()->unique()->slug(),
            'title' => fake()->sentence(2),
            'order' => 1,
            'material' => fake()->paragraphs(3, true),
            'is_published' => true,
        ];
    }

    /**
     * Unpublished lesson: hidden from the public course card.
     */
    public function unpublished(): static
    {
        return $this->state(fn (): array => [
            'is_published' => false,
        ]);
    }
}
