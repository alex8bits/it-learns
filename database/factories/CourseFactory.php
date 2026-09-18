<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CourseStatus;
use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    /**
     * Define the model's default state: an unpublished draft course
     * without a preview image, without a course-specific AI prompt and
     * without a manual catalog order (0 = newest-first tie-breaker).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'preview_image_path' => null,
            'status' => CourseStatus::Draft,
            'sort_order' => 0,
            'ai_course_prompt' => null,
            'created_by' => null,
        ];
    }

    /**
     * Published course: visible in the public catalog.
     */
    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => CourseStatus::Published,
        ]);
    }

    /**
     * Archived course: hidden from the catalog, kept for history.
     */
    public function archived(): static
    {
        return $this->state(fn (): array => [
            'status' => CourseStatus::Archived,
        ]);
    }

    /**
     * Course with a course-specific AI prompt cached from
     * `ai_prompt_versions` (see PromptVersionService).
     */
    public function withPrompt(): static
    {
        return $this->state(fn (): array => [
            'ai_course_prompt' => 'Ты ИИ-ассистент курса. Помогай обучающемуся по материалам курса.',
        ]);
    }
}
