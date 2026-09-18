<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LessonProgressStatus;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserLessonProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserLessonProgress>
 */
class UserLessonProgressFactory extends Factory
{
    /**
     * Define the model's default state: a lesson in progress, just started.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'lesson_id' => Lesson::factory(),
            'status' => LessonProgressStatus::InProgress,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }

    /**
     * Completed lesson: status Completed with the completion timestamp.
     */
    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => LessonProgressStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
