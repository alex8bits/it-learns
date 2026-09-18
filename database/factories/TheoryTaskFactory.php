<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\TheoryTask;
use App\Models\TheoryTaskOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TheoryTask>
 */
class TheoryTaskFactory extends Factory
{
    /**
     * Define the model's default state: a published task, first in its
     * lesson, without options (create them via the `withOptions` state).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'question' => 'Какой запрос выбирает все столбцы из таблицы users?',
            'order' => 1,
            'is_published' => true,
        ];
    }

    /**
     * Unpublished task: hidden from the lesson flow.
     */
    public function unpublished(): static
    {
        return $this->state(fn (): array => [
            'is_published' => false,
        ]);
    }

    /**
     * Create three options for the task: exactly one correct plus two
     * incorrect with Russian explanation texts, ordered 1–3.
     */
    public function withOptions(): static
    {
        return $this->afterCreating(function (TheoryTask $task): void {
            TheoryTaskOption::factory()
                ->count(3)
                ->sequence(
                    [
                        'text' => 'SELECT * FROM users;',
                        'is_correct' => true,
                        'error_text' => null,
                        'order' => 1,
                    ],
                    [
                        'text' => 'SELECT users;',
                        'is_correct' => false,
                        'error_text' => 'Этот вариант не соответствует синтаксису SELECT: после ключевого слова SELECT нужно перечислить столбцы или *, а затем FROM с именем таблицы.',
                        'order' => 2,
                    ],
                    [
                        'text' => 'GET ALL users;',
                        'is_correct' => false,
                        'error_text' => 'Этот вариант не соответствует SQL: GET ALL — не команда языка, данные извлекаются запросом SELECT.',
                        'order' => 3,
                    ],
                )
                ->create(['theory_task_id' => $task->id]);
        });
    }
}
