<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Course;
use App\Models\Level;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Level>
 */
class LevelFactory extends Factory
{
    /**
     * Define the model's default state. The `title` comes from a unique
     * faker sequence, so several levels of the same course never collide
     * on the (course_id, title) unique index. Tests that need a
     * meaningful name or a specific position pass an explicit
     * `title`/`order`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'title' => fake()->unique()->numerify('Раздел ##'),
            'order' => 1,
        ];
    }
}
