<?php

declare(strict_types=1);

namespace Tests\Unit\Seeders;

use App\Enums\CourseStatus;
use App\Enums\PracticeRuntime;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\PracticeTask;
use App\Models\TheoryTask;
use App\Models\TheoryTaskOption;
use App\Services\Practice\CanonicalResultSerializer;
use Database\Seeders\MysqlCourseSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class MysqlCourseSeederTest extends TestCase
{
    /**
     * Directory with synthetic fixture lesson files (created on demand
     * in the system temp directory); empty until a test needs one.
     */
    private string $fixtureDirectory = '';

    protected function tearDown(): void
    {
        if ($this->fixtureDirectory !== '' && is_dir($this->fixtureDirectory)) {
            foreach (glob($this->fixtureDirectory.DIRECTORY_SEPARATOR.'*.md') ?: [] as $fixtureFile) {
                unlink($fixtureFile);
            }

            rmdir($this->fixtureDirectory);
        }

        parent::tearDown();
    }

    public function test_first_run_creates_published_mysql_course_with_four_levels_and_parsed_lesson(): void
    {
        $this->seed(MysqlCourseSeeder::class);

        $course = Course::query()->where('slug', 'mysql')->firstOrFail();

        $this->assertSame('MySQL', $course->title);
        $this->assertSame('База данных MySQL: от первого SELECT до production-эксплуатации.', $course->description);
        $this->assertSame(CourseStatus::Published, $course->status);

        $levels = Level::query()->where('course_id', $course->id)->ordered()->get();

        $this->assertSame(['Основы', 'Начинающий', 'Средний', 'Продвинутый'], $levels->pluck('title')->all());
        $this->assertSame([1, 2, 3, 4], $levels->pluck('order')->all());

        $basics = Level::query()->where('course_id', $course->id)->where('title', 'Основы')->firstOrFail();
        $lesson = Lesson::query()->where('slug', 'what-is-a-database')->firstOrFail();

        $this->assertSame($basics->id, $lesson->level_id);
        $this->assertSame('Что такое база данных и СУБД', $lesson->title);
        $this->assertSame(1, $lesson->order);
        $this->assertTrue($lesson->is_published);

        // Material is the raw `## Материал` section: real markdown with
        // code fences, no section headings leaked in.
        $this->assertStringContainsString('SELECT name, price FROM products;', $lesson->material);
        $this->assertStringNotContainsString('## Материал', $lesson->material);
        $this->assertStringNotContainsString('### Вопрос 1', $lesson->material);
    }

    public function test_lesson_gets_five_theory_tasks_with_parsed_options(): void
    {
        $this->seed(MysqlCourseSeeder::class);

        $lesson = Lesson::query()->where('slug', 'what-is-a-database')->firstOrFail();
        $tasks = TheoryTask::query()
            ->where('lesson_id', $lesson->id)
            ->ordered()
            ->with('options')
            ->get();

        $this->assertSame([1, 2, 3, 4, 5], $tasks->pluck('order')->all());
        $this->assertSame('Чем база данных отличается от СУБД?', $tasks->first()->question);

        foreach ($tasks as $task) {
            $this->assertTrue($task->is_published);

            $options = $task->options;

            $this->assertCount(3, $options);
            $this->assertSame([1, 2, 3], $options->pluck('order')->all());
            $this->assertSame(1, $options->where('is_correct', true)->count());

            foreach ($options as $option) {
                if ($option->is_correct) {
                    $this->assertNull($option->error_text);
                } else {
                    $this->assertNotNull($option->error_text);
                    $this->assertNotSame('', $option->error_text);
                }
            }
        }

        // ✅ option: bare text without the emoji and without error_text.
        $firstTaskOptions = $tasks->first()->options;
        $firstOption = $firstTaskOptions->first();

        $this->assertTrue($firstOption->is_correct);
        $this->assertSame(
            'База данных — само хранилище структурированных данных, а СУБД — программа, которая этим хранилищем управляет',
            $firstOption->text,
        );

        // ❌ options: the option text itself contains «—», so the split
        // must happen on the `— error_text:` marker, not on a bare dash.
        $this->assertSame(
            [
                'База данных — само хранилище структурированных данных, а СУБД — программа, которая этим хранилищем управляет',
                'Это два названия одного и того же',
                'СУБД — это хранилище данных, а база данных — программа для управления им',
            ],
            $firstTaskOptions->pluck('text')->all(),
        );
        $this->assertSame(
            [
                null,
                'понятия разные: данные лежат в базе данных, а MySQL — это программа (СУБД), которая их хранит, защищает и обрабатывает. Смешение терминов мешает понимать, где чья ответственность.',
                'понятия переставлены местами: программой является именно СУБД (например, MySQL), а хранилищем — база данных.',
            ],
            $firstTaskOptions->pluck('error_text')->all(),
        );
    }

    public function test_lesson_gets_two_published_mysql_practice_tasks_with_canonical_hash(): void
    {
        $this->seed(MysqlCourseSeeder::class);

        $lesson = Lesson::query()->where('slug', 'what-is-a-database')->firstOrFail();
        $tasks = PracticeTask::query()->where('lesson_id', $lesson->id)->ordered()->get();

        $this->assertSame([1, 2], $tasks->pluck('order')->all());

        foreach ($tasks as $task) {
            $this->assertTrue($task->is_published);
            $this->assertSame(PracticeRuntime::Mysql, $task->runtime);
            $this->assertIsString($task->statement);
            $this->assertNotSame('', $task->statement);
            $this->assertIsString($task->expected_result_text);
            $this->assertNotSame('', $task->expected_result_text);
            $this->assertIsString($task->seed_sql);
            $this->assertStringContainsString('CREATE TABLE products', $task->seed_sql);
            $this->assertStringContainsString('INSERT INTO products', $task->seed_sql);

            // Author-only HTML comments (reference solutions) never
            // reach the seeded fields.
            $this->assertStringNotContainsString('Эталонное решение', $task->statement);
            $this->assertStringNotContainsString('Эталонное решение', $task->expected_result_text);
        }

        $first = PracticeTask::query()->where('lesson_id', $lesson->id)->where('order', 1)->firstOrFail();
        $second = PracticeTask::query()->where('lesson_id', $lesson->id)->where('order', 2)->firstOrFail();

        $this->assertStringContainsString('столбцы `name` и `price`', $first->statement);

        // Cells stay strings — no type coercion; the canonical
        // serializer normalizes values when hashing.
        $this->assertSame(
            [
                ['name' => 'Клавиатура механическая', 'price' => '4990.00'],
                ['name' => 'Мышь беспроводная', 'price' => '1290.50'],
                ['name' => 'Монитор 21 дюйм', 'price' => '24990.00'],
                ['name' => 'USB-хаб на 7 портов', 'price' => '1890.00'],
                ['name' => 'Веб-камера 1080p', 'price' => '3590.00'],
            ],
            $first->expected_rows,
        );

        $this->assertSame(
            app(CanonicalResultSerializer::class)->hash($first->expected_rows, ['name', 'price']),
            $first->expected_hash,
        );

        $this->assertSame(
            [
                ['id' => '1', 'name' => 'Клавиатура механическая', 'price' => '4990.00', 'stock' => '12'],
                ['id' => '2', 'name' => 'Мышь беспроводная', 'price' => '1290.50', 'stock' => '47'],
                ['id' => '3', 'name' => 'Монитор 21 дюйм', 'price' => '24990.00', 'stock' => '5'],
                ['id' => '4', 'name' => 'USB-хаб на 7 портов', 'price' => '1890.00', 'stock' => '0'],
                ['id' => '5', 'name' => 'Веб-камера 1080p', 'price' => '3590.00', 'stock' => '23'],
            ],
            $second->expected_rows,
        );

        $this->assertSame(
            app(CanonicalResultSerializer::class)->hash($second->expected_rows, ['id', 'name', 'price', 'stock']),
            $second->expected_hash,
        );
    }

    public function test_repeated_run_does_not_update_or_duplicate_existing_rows(): void
    {
        // A synthetic lesson fixture (not the real `docs/mysql/` files)
        // keeps the expected counts deterministic and independent from
        // how many real lessons the course ships with.
        $seeder = $this->writeFixtureFile(
            'basics-02-fixture-lesson.md',
            self::lessonFile(self::practiceFrontmatter(), self::practiceBody(self::validQuestion(), self::validPracticeTask())),
        );

        $seeder->run();

        // Mutate the lesson to prove a repeated run neither updates nor
        // duplicates: existing lessons are skipped as a whole.
        $lesson = Lesson::query()->where('slug', 'fixture-lesson')->firstOrFail();
        $lesson->update([
            'title' => 'Изменён вручную',
            'material' => 'Материал перезаписан вручную.',
        ]);

        $seeder->run();

        $this->assertDatabaseCount('courses', 1);
        $this->assertDatabaseCount('levels', 4);
        $this->assertDatabaseCount('lessons', 1);
        $this->assertDatabaseCount('theory_tasks', 1);
        $this->assertDatabaseCount('theory_task_options', 2);
        $this->assertDatabaseCount('practice_tasks', 1);

        $lesson->refresh();

        $this->assertSame('Изменён вручную', $lesson->title);
        $this->assertSame('Материал перезаписан вручную.', $lesson->material);
    }

    public function test_deleted_lesson_is_recreated_by_next_run(): void
    {
        $seeder = $this->writeFixtureFile(
            'basics-02-fixture-lesson.md',
            self::lessonFile(self::practiceFrontmatter(), self::practiceBody(self::validQuestion(), self::validPracticeTask())),
        );

        $seeder->run();

        // Removing the lesson cascades to its tasks; the append-only
        // seeder must re-create exactly what is missing.
        Lesson::query()->where('slug', 'fixture-lesson')->firstOrFail()->delete();

        $this->assertDatabaseCount('lessons', 0);
        $this->assertDatabaseCount('theory_tasks', 0);
        $this->assertDatabaseCount('theory_task_options', 0);
        $this->assertDatabaseCount('practice_tasks', 0);

        $seeder->run();

        $lesson = Lesson::query()->where('slug', 'fixture-lesson')->firstOrFail();

        $this->assertSame('Урок с практикой', $lesson->title);
        $this->assertTrue($lesson->is_published);

        // The re-created lesson carries its tasks again.
        $this->assertSame(1, TheoryTask::query()->where('lesson_id', $lesson->id)->count());
        $this->assertSame(1, PracticeTask::query()->where('lesson_id', $lesson->id)->count());

        $this->assertDatabaseCount('courses', 1);
        $this->assertDatabaseCount('levels', 4);
        $this->assertDatabaseCount('lessons', 1);
        $this->assertDatabaseCount('theory_tasks', 1);
        $this->assertDatabaseCount('theory_task_options', 2);
        $this->assertDatabaseCount('practice_tasks', 1);
    }

    public function test_theory_only_lesson_file_seeds_lesson_without_practice_tasks(): void
    {
        $frontmatter = <<<'MD'
        level_slug: basics
        lesson: 2
        title: Урок про решётку # и хеш #2 в заголовке
        practice: no             # yes | no — по колонке «Практика» в docs/mysql.md
        MD;

        $seeder = $this->writeFixtureFile(
            'basics-02-theory-only.md',
            self::lessonFile($frontmatter, self::theoryBody(self::validQuestion())),
        );

        $seeder->run();

        $course = Course::query()->where('slug', 'mysql')->firstOrFail();
        $basics = Level::query()->where('course_id', $course->id)->where('title', 'Основы')->firstOrFail();
        $lesson = Lesson::query()->where('slug', 'theory-only')->firstOrFail();

        $this->assertSame($basics->id, $lesson->level_id);
        // The `#` inside the title survives: inline ` #` comments are
        // stripped only from the template's annotated keys.
        $this->assertSame('Урок про решётку # и хеш #2 в заголовке', $lesson->title);
        $this->assertSame(2, $lesson->order);
        $this->assertTrue($lesson->is_published);

        $theoryTask = TheoryTask::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $this->assertSame('Один ли верный вариант?', $theoryTask->question);
        $this->assertSame(2, TheoryTaskOption::query()->where('theory_task_id', $theoryTask->id)->count());

        // `practice: no` with no `## Практические задания` section is a
        // valid theory-only lesson (level 2 of the course ships this way).
        $this->assertSame(0, PracticeTask::query()->where('lesson_id', $lesson->id)->count());
    }

    public function test_files_not_matching_the_filename_pattern_are_quietly_skipped(): void
    {
        // `notes.md` never matches the `*-*.md` glob at all, while
        // `basics-foo.md` is globbed but fails the
        // `<level_slug>-<NN>-<theme>.md` pattern: both must be skipped
        // with a warning (the command is null here — the warn stays
        // null-safe), never throw and never seed anything.
        $this->writeFixtureFile('notes.md', 'Просто заметка, не урок.');

        $seeder = $this->writeFixtureFile(
            'basics-foo.md',
            self::lessonFile(self::validFrontmatter(), self::theoryBody(self::validQuestion())),
        );

        $seeder->run();

        $this->assertDatabaseCount('lessons', 0);
        $this->assertDatabaseCount('theory_tasks', 0);
        $this->assertDatabaseCount('practice_tasks', 0);
    }

    /**
     * @return array<string, array{filename: string, content: string, message: string}>
     */
    public static function invalidLessonFileProvider(): array
    {
        $missingLevelSlug = <<<'MD'
        lesson: 2
        title: Урок без уровня
        practice: no
        MD;

        $missingTitle = <<<'MD'
        level_slug: basics
        lesson: 2
        practice: no
        MD;

        $emptyMaterialBody = <<<'MD'
        # Урок

        ## Материал

        ## Теоретические задания

        ### Вопрос 1: Один ли верный вариант?

        - ✅ Верный вариант
        - ❌ Неверный вариант — error_text: почему он неверен

        MD;

        $zeroCorrectQuestion = <<<'MD'
        ### Вопрос 1: Один ли верный вариант?

        - ❌ Первый вариант — error_text: почему неверен
        - ❌ Второй вариант — error_text: почему неверен

        MD;

        $twoCorrectQuestion = <<<'MD'
        ### Вопрос 1: Один ли верный вариант?

        - ✅ Первый вариант
        - ✅ Второй вариант
        - ❌ Третий вариант — error_text: почему неверен

        MD;

        $missingErrorTextQuestion = <<<'MD'
        ### Вопрос 1: Один ли верный вариант?

        - ✅ Верный вариант
        - ❌ Неверный вариант без пояснения

        MD;

        $unknownRuntimeBody = self::practiceBody(self::validQuestion(), self::validPracticeTask('oracle'));

        return [
            'frontmatter без level_slug' => [
                'filename' => 'basics-01-missing-level-slug.md',
                'content' => self::lessonFile($missingLevelSlug, self::theoryBody(self::validQuestion())),
                'message' => 'frontmatter `level_slug` отсутствует или неизвестен',
            ],
            'frontmatter без title' => [
                'filename' => 'basics-01-missing-title.md',
                'content' => self::lessonFile($missingTitle, self::theoryBody(self::validQuestion())),
                'message' => 'frontmatter `title` отсутствует',
            ],
            'пустая секция «Материал»' => [
                'filename' => 'basics-01-empty-material.md',
                'content' => self::lessonFile(self::validFrontmatter(), $emptyMaterialBody),
                'message' => 'секция `## Материал` отсутствует или пуста',
            ],
            'ноль правильных вариантов в вопросе' => [
                'filename' => 'basics-01-zero-correct.md',
                'content' => self::lessonFile(self::validFrontmatter(), self::theoryBody($zeroCorrectQuestion)),
                'message' => 'должен быть ровно один правильный вариант `✅`, найдено — 0',
            ],
            'два правильных варианта в вопросе' => [
                'filename' => 'basics-01-two-correct.md',
                'content' => self::lessonFile(self::validFrontmatter(), self::theoryBody($twoCorrectQuestion)),
                'message' => 'должен быть ровно один правильный вариант `✅`, найдено — 2',
            ],
            'у неверного варианта нет error_text' => [
                'filename' => 'basics-01-missing-error-text.md',
                'content' => self::lessonFile(self::validFrontmatter(), self::theoryBody($missingErrorTextQuestion)),
                'message' => 'нет пояснения `— error_text:',
            ],
            'level_slug имени файла не совпадает с frontmatter' => [
                'filename' => 'beginner-03-level-mismatch.md',
                'content' => self::lessonFile(self::validFrontmatter(), self::theoryBody(self::validQuestion())),
                'message' => '(`beginner`) не совпадает с frontmatter `level_slug` (`basics`)',
            ],
            'неизвестный runtime практики' => [
                'filename' => 'basics-01-unknown-runtime.md',
                'content' => self::lessonFile(self::practiceFrontmatter(), $unknownRuntimeBody),
                'message' => 'неизвестный runtime `oracle`',
            ],
        ];
    }

    #[DataProvider('invalidLessonFileProvider')]
    public function test_invalid_lesson_file_fails_the_seed_without_touching_the_database(string $filename, string $content, string $message): void
    {
        $seeder = $this->writeFixtureFile($filename, $content);

        try {
            $seeder->run();
            $this->fail("MysqlCourseSeeder должен упасть на файле `{$filename}`, нарушающем формат урока.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        // Append-only seeding never self-heals, so a malformed lesson
        // must not leave even a partial trace in the database.
        $this->assertDatabaseCount('lessons', 0);
        $this->assertDatabaseCount('theory_tasks', 0);
        $this->assertDatabaseCount('theory_task_options', 0);
        $this->assertDatabaseCount('practice_tasks', 0);
    }

    /**
     * Write a synthetic lesson file into the fixture directory (created
     * on demand in the system temp directory) and return a seeder
     * pointed at it: the parser keeps reading real files from a real
     * directory, no filesystem mocking.
     */
    private function writeFixtureFile(string $filename, string $content): MysqlCourseSeeder
    {
        if ($this->fixtureDirectory === '') {
            $this->fixtureDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mysql-course-seeder-'.bin2hex(random_bytes(8));
            mkdir($this->fixtureDirectory);
        }

        file_put_contents($this->fixtureDirectory.DIRECTORY_SEPARATOR.$filename, $content);

        $seeder = new MysqlCourseSeeder;
        $seeder->lessonsDirectory = $this->fixtureDirectory;

        return $seeder;
    }

    /**
     * Assemble a lesson file from the fixed `course:`/`level:` header
     * (the `level:` line keeps its inline comment to exercise comment
     * stripping), the case's frontmatter lines and its markdown body.
     */
    private static function lessonFile(string $frontmatter, string $body): string
    {
        return <<<MD
        ---
        course: mysql
        level: Основы            # Основы | Начинающий | Средний | Продвинутый
        {$frontmatter}
        ---

        {$body}
        MD;
    }

    /**
     * Valid theory-only frontmatter tail (`practice: no`).
     */
    private static function validFrontmatter(): string
    {
        return <<<'MD'
        level_slug: basics
        lesson: 2
        title: Урок с теорией
        practice: no             # yes | no — по колонке «Практика» в docs/mysql.md
        MD;
    }

    /**
     * Valid `practice: yes` frontmatter tail for practice-bearing cases.
     */
    private static function practiceFrontmatter(): string
    {
        return <<<'MD'
        level_slug: basics
        lesson: 2
        title: Урок с практикой
        practice: yes            # yes | no — по колонке «Практика» в docs/mysql.md
        MD;
    }

    /**
     * Lesson body with a valid material section and the given theory
     * question section.
     */
    private static function theoryBody(string $question): string
    {
        return <<<MD
        # Урок

        ## Материал

        Материал урока.

        ## Теоретические задания

        {$question}
        MD;
    }

    /**
     * Lesson body with a valid material section, the given theory
     * question section and a `## Практические задания` section holding
     * the given practice task (docs/mysql-lesson-rule.md §5).
     */
    private static function practiceBody(string $question, string $practiceTask): string
    {
        return self::theoryBody($question)."\n\n## Практические задания\n\n".$practiceTask;
    }

    /**
     * Valid single-question theory section: one ✅ and one ❌ with an
     * error_text.
     */
    private static function validQuestion(): string
    {
        return <<<'MD'
        ### Вопрос 1: Один ли верный вариант?

        - ✅ Верный вариант
        - ❌ Неверный вариант — error_text: почему он неверен

        MD;
    }

    /**
     * Valid single-task practice section with all the required markers:
     * `**statement:**`, `**expected_result_text:**`, `**seed_sql:**`,
     * `**expected_rows:**` and `**runtime:**` set to the given runtime
     * (`mysql` by default).
     */
    private static function validPracticeTask(string $runtime = 'mysql'): string
    {
        return <<<MD
        ### Задание 1: Первое задание

        **statement:**

        Выведите все строки таблицы `t`.

        **expected_result_text:**

        Один столбец и одна строка.

        **seed_sql:**

        ```sql
        CREATE TABLE t (id INT);
        INSERT INTO t (id) VALUES (1);
        ```

        **expected_rows:**

        | id |
        | -- |
        | 1  |

        **runtime:** {$runtime}

        MD;
    }
}
