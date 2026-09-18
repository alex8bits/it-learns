<?php

declare(strict_types=1);

namespace Tests\Unit\Seeders;

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\PracticeTask;
use App\Models\TheoryTask;
use App\Models\User;
use App\Services\Practice\CanonicalResultSerializer;
use App\Services\Practice\LocalSqlitePracticeEnvironment;
use Database\Seeders\DemoCourseSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoCourseSeederTest extends TestCase
{
    public function test_first_run_creates_published_demo_course_with_levels_and_lessons(): void
    {
        $this->seed(DemoCourseSeeder::class);

        $course = Course::query()->where('slug', 'sql-basics')->firstOrFail();

        $this->assertSame('Основы SQL', $course->title);
        $this->assertSame(CourseStatus::Published, $course->status);
        $this->assertNull($course->created_by);
        $this->assertNull($course->ai_course_prompt);

        $beginner = Level::query()
            ->where('course_id', $course->id)
            ->where('title', 'Основы')
            ->firstOrFail();
        $intermediate = Level::query()
            ->where('course_id', $course->id)
            ->where('title', 'Продолжение')
            ->firstOrFail();

        $this->assertSame(1, $beginner->order);
        $this->assertSame(2, $intermediate->order);

        $this->assertSame(
            ['select-basics', 'where-ordering'],
            Lesson::query()->where('level_id', $beginner->id)->orderBy('order')->pluck('slug')->all(),
        );
        $this->assertSame(
            ['joins-intro'],
            Lesson::query()->where('level_id', $intermediate->id)->pluck('slug')->all(),
        );

        // Demo lessons are visible in the public course card immediately.
        $this->assertSame(3, Lesson::query()->where('is_published', true)->count());
    }

    public function test_select_basics_lesson_gets_two_published_theory_tasks_with_one_correct_option_each(): void
    {
        $this->seed(DemoCourseSeeder::class);

        $selectBasics = Lesson::query()->where('slug', 'select-basics')->firstOrFail();
        $tasks = TheoryTask::query()
            ->where('lesson_id', $selectBasics->id)
            ->ordered()
            ->with('options')
            ->get();

        $this->assertCount(2, $tasks);

        foreach ($tasks as $task) {
            $this->assertTrue($task->is_published);

            $options = $task->options;
            $this->assertCount(3, $options);
            $this->assertSame(
                [1, 2, 3],
                $options->pluck('order')->all(),
                'Options must be ordered 1-3 by the relation.',
            );
            $this->assertSame(
                1,
                $options->where('is_correct', true)->count(),
                'Each demo theory task must have exactly one correct option.',
            );

            $incorrect = $options->where('is_correct', false);
            $this->assertTrue(
                $incorrect->pluck('error_text')->every(fn ($errorText): bool => is_string($errorText) && $errorText !== ''),
                'Each incorrect demo option must carry a Russian explanation text.',
            );
        }
    }

    public function test_select_basics_lesson_gets_one_published_practice_task(): void
    {
        $this->seed(DemoCourseSeeder::class);

        $selectBasics = Lesson::query()->where('slug', 'select-basics')->firstOrFail();
        $tasks = PracticeTask::query()
            ->where('lesson_id', $selectBasics->id)
            ->ordered()
            ->get();

        $this->assertCount(1, $tasks);

        $task = $tasks->first();
        $this->assertTrue($task->is_published);
        $this->assertSame(1, $task->order);
        $this->assertIsString($task->statement);
        $this->assertNotSame('', $task->statement);
        $this->assertIsString($task->expected_result_text);
        $this->assertNotSame('', $task->expected_result_text);
        $this->assertIsString($task->seed_sql);
        $this->assertStringContainsString('CREATE TABLE books', $task->seed_sql);

        // The expected rows carry the reference SELECT shape: title + year,
        // already sorted by year.
        $this->assertSame(
            [
                ['title' => 'Преступление и наказание', 'year' => 1866],
                ['title' => 'Мастер и Маргарита', 'year' => 1967],
            ],
            $task->expected_rows,
        );

        // The stored hash is the canonical hash of those rows with the
        // columns taken from the first row.
        $this->assertSame(
            app(CanonicalResultSerializer::class)->hash($task->expected_rows, ['title', 'year']),
            $task->expected_hash,
        );
    }

    public function test_seeded_practice_task_is_solvable_in_the_local_sqlite_environment(): void
    {
        $this->seed(DemoCourseSeeder::class);

        $selectBasics = Lesson::query()->where('slug', 'select-basics')->firstOrFail();
        $task = PracticeTask::query()->where('lesson_id', $selectBasics->id)->firstOrFail();

        // Isolate the practice files in a unique temp directory per test
        // run — never in the real storage/framework/practice.
        $storagePath = sys_get_temp_dir().'/practice-seeder-test-'.Str::uuid()->toString();
        config(['practice.storage_path' => $storagePath]);

        $manager = new LocalSqlitePracticeEnvironment(new CanonicalResultSerializer);

        try {
            $environment = $manager->provision(User::factory()->create(), $task->toInput());

            try {
                $result = $manager->execute($environment, 'SELECT title, year FROM books ORDER BY year');
            } finally {
                $manager->destroy($environment);
            }

            $this->assertNull($result->error);
            $this->assertTrue(
                $manager->compare($result, $task->expected_hash),
                'The reference query must satisfy the seeded expected hash.',
            );
        } finally {
            $this->removeDirectory($storagePath);
        }
    }

    public function test_repeated_run_does_not_duplicate_rows(): void
    {
        $this->seed(DemoCourseSeeder::class);
        $this->seed(DemoCourseSeeder::class);

        $this->assertDatabaseCount('courses', 1);
        $this->assertDatabaseCount('levels', 2);
        $this->assertDatabaseCount('lessons', 3);
        $this->assertDatabaseCount('theory_tasks', 2);
        $this->assertDatabaseCount('theory_task_options', 6);
        $this->assertDatabaseCount('practice_tasks', 1);
    }

    /**
     * Delete the isolated practice storage directory of a test.
     */
    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            @unlink($dir.'/'.$entry);
        }

        @rmdir($dir);
    }
}
