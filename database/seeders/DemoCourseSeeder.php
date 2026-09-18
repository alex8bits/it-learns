<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\PracticeTask;
use App\Models\TheoryTask;
use App\Models\TheoryTaskOption;
use App\Services\Practice\CanonicalResultSerializer;
use Illuminate\Database\Seeder;

/**
 * Demo course for a fresh install: «Основы SQL» with two levels and
 * three published lessons (theory tasks plus one SQL practice task in
 * `select-basics`), visible in the public catalog immediately.
 */
class DemoCourseSeeder extends Seeder
{
    public function run(): void
    {
        $course = Course::firstOrCreate(
            ['slug' => 'sql-basics'],
            [
                'title' => 'Основы SQL',
                'description' => 'Практический курс по основам SQL: от первых SELECT до JOIN.',
                'status' => CourseStatus::Published,
                'created_by' => null,
            ],
        );

        $beginner = Level::firstOrCreate(
            ['course_id' => $course->id, 'title' => 'Основы'],
            ['order' => 1],
        );
        $intermediate = Level::firstOrCreate(
            ['course_id' => $course->id, 'title' => 'Продолжение'],
            ['order' => 2],
        );

        $selectBasics = Lesson::firstOrCreate(
            ['slug' => 'select-basics'],
            [
                'level_id' => $beginner->id,
                'title' => 'SELECT: первые запросы',
                'order' => 1,
                'material' => 'SELECT извлекает данные из таблицы. Простейший запрос — SELECT * FROM users; — возвращает все строки и все столбцы. Чтобы не тянуть лишнее, перечисляйте нужные столбцы явно: SELECT name, email FROM users;',
                'is_published' => true,
            ],
        );
        Lesson::firstOrCreate(
            ['slug' => 'where-ordering'],
            [
                'level_id' => $beginner->id,
                'title' => 'WHERE и сортировка',
                'order' => 2,
                'material' => 'WHERE фильтрует строки: SELECT * FROM users WHERE is_blocked = false;. ORDER BY сортирует результат: ... ORDER BY created_at DESC. Комбинируйте фильтр и сортировку, чтобы получать ровно нужный срез данных.',
                'is_published' => true,
            ],
        );
        Lesson::firstOrCreate(
            ['slug' => 'joins-intro'],
            [
                'level_id' => $intermediate->id,
                'title' => 'JOIN: соединение таблиц',
                'order' => 1,
                'material' => 'INNER JOIN соединяет строки двух таблиц по условию: SELECT u.name, p.amount FROM users u INNER JOIN payments p ON p.user_id = u.id;. Совпадения без пары с обеих сторон теряются; LEFT JOIN сохраняет все строки левой таблицы.',
                'is_published' => true,
            ],
        );

        foreach ($this->selectBasicsTheoryTasks() as $taskAttributes) {
            $task = TheoryTask::firstOrCreate(
                ['lesson_id' => $selectBasics->id, 'order' => $taskAttributes['order']],
                [
                    'question' => $taskAttributes['question'],
                    'is_published' => true,
                ],
            );

            foreach ($taskAttributes['options'] as $optionAttributes) {
                TheoryTaskOption::firstOrCreate(
                    ['theory_task_id' => $task->id, 'order' => $optionAttributes['order']],
                    [
                        'text' => $optionAttributes['text'],
                        'is_correct' => $optionAttributes['is_correct'],
                        'error_text' => $optionAttributes['error_text'],
                    ],
                );
            }
        }

        $this->seedSelectBasicsPracticeTask($selectBasics);
    }

    /**
     * One published practice task for the demo lesson `select-basics`:
     * the student writes `SELECT title, year FROM books ORDER BY year`
     * against the seeded SQLite table. The expected hash is derived
     * from `expected_rows` with the same canonical serializer the
     * runtime compares attempts by, so a correct query always passes.
     */
    private function seedSelectBasicsPracticeTask(Lesson $selectBasics): void
    {
        $expectedRows = [
            ['title' => 'Преступление и наказание', 'year' => 1866],
            ['title' => 'Мастер и Маргарита', 'year' => 1967],
        ];

        $expectedHash = app(CanonicalResultSerializer::class)
            ->hash($expectedRows, array_keys($expectedRows[0]));

        PracticeTask::firstOrCreate(
            ['lesson_id' => $selectBasics->id, 'statement' => 'Выберите название и год каждой книги и отсортируйте по году по возрастанию'],
            [
                'expected_result_text' => 'Две строки — название и год каждой книги, отсортированные по году по возрастанию.',
                'expected_rows' => $expectedRows,
                'seed_sql' => <<<'SQL'
                    CREATE TABLE books (id INTEGER PRIMARY KEY, title TEXT, author TEXT, year INTEGER);
                    INSERT INTO books (title, author, year) VALUES ('Преступление и наказание', 'Фёдор Достоевский', 1866);
                    INSERT INTO books (title, author, year) VALUES ('Мастер и Маргарита', 'Михаил Булгаков', 1967);
                    SQL,
                'expected_hash' => $expectedHash,
                'order' => 1,
                'is_published' => true,
            ],
        );
    }

    /**
     * Theory tasks for the demo lesson `select-basics`: two published
     * questions about SQL SELECT, one correct option each.
     *
     * @return array<int, array{order: int, question: string, options: array<int, array{order: int, text: string, is_correct: bool, error_text: string|null}>}>
     */
    private function selectBasicsTheoryTasks(): array
    {
        return [
            [
                'order' => 1,
                'question' => 'Какой запрос выберет только столбцы name и email из таблицы users?',
                'options' => [
                    [
                        'order' => 1,
                        'text' => 'SELECT name, email FROM users;',
                        'is_correct' => true,
                        'error_text' => null,
                    ],
                    [
                        'order' => 2,
                        'text' => 'SELECT name AND email FROM users;',
                        'is_correct' => false,
                        'error_text' => 'Этот вариант не соответствует синтаксису SQL: столбцы перечисляются через запятую, а AND — логический оператор для условий в WHERE.',
                    ],
                    [
                        'order' => 3,
                        'text' => 'GET name, email FROM users;',
                        'is_correct' => false,
                        'error_text' => 'Этот вариант не соответствует SQL: GET — не команда языка, данные извлекаются запросом SELECT.',
                    ],
                ],
            ],
            [
                'order' => 2,
                'question' => 'Что вернёт запрос SELECT * FROM users;?',
                'options' => [
                    [
                        'order' => 1,
                        'text' => 'Все строки и все столбцы таблицы users',
                        'is_correct' => true,
                        'error_text' => null,
                    ],
                    [
                        'order' => 2,
                        'text' => 'Только первую строку таблицы users',
                        'is_correct' => false,
                        'error_text' => 'Этот вариант не соответствует семантике SELECT: без ограничения LIMIT запрос возвращает все строки, а не только первую.',
                    ],
                    [
                        'order' => 3,
                        'text' => 'Только список имён столбцов таблицы users',
                        'is_correct' => false,
                        'error_text' => 'Этот вариант не соответствует семантике SELECT: * означает все столбцы со значениями всех строк, а не только перечень имён столбцов.',
                    ],
                ],
            ],
        ];
    }
}
