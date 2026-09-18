<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CourseProgressStatus;
use App\Models\Course;
use App\Models\User;
use App\Models\UserCourseProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserCourseProgress>
 */
class UserCourseProgressFactory extends Factory
{
    /**
     * Define the model's default state: a course in progress without a
     * current-lesson pointer yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'course_id' => Course::factory(),
            'status' => CourseProgressStatus::InProgress,
            'current_lesson_id' => null,
        ];
    }

    /**
     * Completed course: status Completed.
     */
    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => CourseProgressStatus::Completed,
        ]);
    }
}
