<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\PracticeTask;
use App\Services\Practice\CanonicalResultSerializer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PracticeTask>
 */
class PracticeTaskFactory extends Factory
{
    /**
     * Define the model's default state: a published task, first in its
     * lesson, without a seed script (create one via the `withSeedScript`
     * state). `expected_hash` is derived from `expected_rows` through
     * the canonical serializer with the columns taken from the first
     * row — the same invariant the admin CRUD will enforce.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'statement' => 'Выберите название и год каждой книги и отсортируйте по году по возрастанию',
            'expected_result_text' => 'Две строки, отсортированные по году по возрастанию',
            'expected_rows' => [
                ['id' => 1, 'title' => 'SQL Basics', 'year' => 2020],
                ['id' => 2, 'title' => 'Advanced SQL', 'year' => 2021],
            ],
            'seed_sql' => null,
            'expected_hash' => function (array $attributes): string {
                /** @var array<int, array<string, mixed>> $rows */
                $rows = is_array($attributes['expected_rows']) ? $attributes['expected_rows'] : [];

                return app(CanonicalResultSerializer::class)->hash($rows, array_keys($rows[0] ?? []));
            },
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
     * Provide a valid SQLite multi-statement seed script whose reference
     * query `SELECT id, title, year FROM books` yields exactly the
     * default `expected_rows` in the same (id) order.
     */
    public function withSeedScript(): static
    {
        return $this->state(fn (): array => [
            'seed_sql' => <<<'SQL'
                CREATE TABLE books (id INTEGER PRIMARY KEY, title TEXT, year INTEGER);
                INSERT INTO books (title, year) VALUES ('SQL Basics', 2020);
                INSERT INTO books (title, year) VALUES ('Advanced SQL', 2021);
                SQL,
        ]);
    }
}
