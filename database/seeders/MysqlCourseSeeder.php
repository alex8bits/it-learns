<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CourseStatus;
use App\Enums\PracticeRuntime;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\PracticeTask;
use App\Models\TheoryTask;
use App\Models\TheoryTaskOption;
use App\Services\Practice\CanonicalResultSerializer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds the MySQL course from the lesson markdown files in `docs/mysql/`
 * (the file format is the machine-readable contract of
 * docs/mysql-lesson-rule.md §5). Re-running is append-only: the course
 * and its four fixed levels are ensured via firstOrCreate, a lesson
 * whose slug already exists is skipped as a whole (never updated, never
 * duplicated), and only missing lessons are created.
 */
class MysqlCourseSeeder extends Seeder
{
    /**
     * Fixed course levels (docs/mysql.md plan): frontmatter `level_slug`
     * → level title and order inside the course.
     *
     * @var array<string, array{title: string, order: int}>
     */
    private const LEVELS = [
        'basics' => ['title' => 'Основы', 'order' => 1],
        'beginner' => ['title' => 'Начинающий', 'order' => 2],
        'intermediate' => ['title' => 'Средний', 'order' => 3],
        'advanced' => ['title' => 'Продвинутый', 'order' => 4],
    ];

    /**
     * Marker separating an incorrect option text from its explanation
     * (docs/mysql-lesson-rule.md §2).
     */
    private const ERROR_TEXT_MARKER = ' — error_text: ';

    /**
     * Frontmatter keys allowed to carry an inline ` #` comment in the
     * template (docs/mysql-lesson-rule.md §5). Comments are stripped
     * from these keys only, so a `title` legitimately containing `#`
     * is not truncated.
     *
     * @var list<string>
     */
    private const FRONTMATTER_COMMENTED_KEYS = ['level', 'level_slug', 'lesson', 'practice'];

    /**
     * Directory holding the lesson markdown files: a path relative to
     * the project root or an absolute one. Public so tests can point
     * the seeder at a fixture directory instead of the real
     * `docs/mysql/`.
     */
    public string $lessonsDirectory = 'docs/mysql';

    public function run(): void
    {
        $course = Course::firstOrCreate(
            ['slug' => 'mysql'],
            [
                'title' => 'MySQL',
                'description' => 'База данных MySQL: от первого SELECT до production-эксплуатации.',
                'status' => CourseStatus::Published,
                'created_by' => null,
            ],
        );

        $levels = [];

        foreach (self::LEVELS as $slug => ['title' => $title, 'order' => $order]) {
            $levels[$slug] = Level::firstOrCreate(
                ['course_id' => $course->id, 'title' => $title],
                ['order' => $order],
            );
        }

        $created = 0;
        $skipped = 0;

        foreach ($this->lessonFiles() as $path) {
            $filename = basename($path);

            if (preg_match('/^(?<level_slug>basics|beginner|intermediate|advanced)-\d{2}-(?<theme>[a-z0-9]+(?:-[a-z0-9]+)*)\.md$/', $filename, $match) !== 1) {
                $this->command?->warn("[mysql] файл `{$filename}` не подходит под шаблон имени `<level_slug>-<NN>-<theme>.md` — пропущен.");

                continue;
            }

            $slug = $match['theme'];

            if (Lesson::query()->where('slug', $slug)->exists()) {
                $skipped++;
                $this->command?->info("[mysql] урок `{$slug}` уже существует — пропущен.");

                continue;
            }

            $lesson = $this->parseLessonFile($path, $slug);

            if ($lesson['level_slug'] !== $match['level_slug']) {
                throw new RuntimeException(sprintf(
                    '[mysql] `%s`: level_slug имени файла (`%s`) не совпадает с frontmatter `level_slug` (`%s`).',
                    $filename,
                    $match['level_slug'],
                    $lesson['level_slug'],
                ));
            }

            $level = $levels[$lesson['level_slug']]
                ?? throw new RuntimeException("[mysql] `{$filename}`: неизвестный level_slug `{$lesson['level_slug']}`.");
            $this->createLesson($level, $lesson);
            $created++;
            $this->command?->info(sprintf(
                '[mysql] создан урок `%s` (уровень `%s`, теория: %d, практика: %d).',
                $slug,
                $lesson['level_slug'],
                count($lesson['theory_tasks']),
                count($lesson['practice_tasks']),
            ));
        }

        $this->command?->info(sprintf('[mysql] курс MySQL: создано уроков — %d, пропущено — %d.', $created, $skipped));
    }

    /**
     * Lesson files to import, sorted for a deterministic order. An
     * absolute `$lessonsDirectory` (e.g. a test fixture directory) is
     * used as is; a relative one is resolved against the project root.
     *
     * @return list<string>
     */
    private function lessonFiles(): array
    {
        $directory = preg_match('/^(?:[A-Za-z]:)?[\/\\\\]/', $this->lessonsDirectory) === 1
            ? $this->lessonsDirectory
            : base_path($this->lessonsDirectory);

        $files = glob($directory.'/*-*.md') ?: [];
        sort($files);

        return $files;
    }

    /**
     * Parse one lesson file into lesson attributes. Any deviation from
     * the format throws: the files are the single source of truth, so a
     * malformed file must fail the seed loudly instead of seeding
     * partial content.
     *
     * @return array{slug: string, level_slug: string, order: int, title: string, material: string, theory_tasks: list<array{order: int, question: string, options: list<array{order: int, text: string, is_correct: bool, error_text: string|null}>}>, practice_tasks: list<array{order: int, statement: string, expected_result_text: string, seed_sql: string, expected_columns: list<string>, expected_rows: list<array<string, string>>, runtime: PracticeRuntime}>}
     */
    private function parseLessonFile(string $path, string $slug): array
    {
        $filename = basename($path);
        $raw = file_get_contents($path);

        if ($raw === false || $raw === '') {
            throw new RuntimeException("[mysql] `{$filename}`: файл пуст или не читается.");
        }

        $content = str_replace("\r\n", "\n", str_replace("\u{FEFF}", '', $raw));

        [$frontmatter, $body] = $this->splitFrontmatter($content, $filename);

        $levelSlug = $frontmatter['level_slug'] ?? null;

        if ($levelSlug === null || ! isset(self::LEVELS[$levelSlug])) {
            throw new RuntimeException("[mysql] `{$filename}`: frontmatter `level_slug` отсутствует или неизвестен.");
        }

        $orderValue = $frontmatter['lesson'] ?? null;

        if ($orderValue === null || ! ctype_digit($orderValue)) {
            throw new RuntimeException("[mysql] `{$filename}`: frontmatter `lesson` должен быть номером урока.");
        }

        $title = $frontmatter['title'] ?? null;

        if ($title === null || $title === '') {
            throw new RuntimeException("[mysql] `{$filename}`: frontmatter `title` отсутствует.");
        }

        $practice = $frontmatter['practice'] ?? null;

        if ($practice !== 'yes' && $practice !== 'no') {
            throw new RuntimeException("[mysql] `{$filename}`: frontmatter `practice` должен быть `yes` или `no`.");
        }

        $sections = $this->splitByHeadings($body, '## ');

        $material = $sections['Материал'] ?? null;

        if ($material === null || $material === '') {
            throw new RuntimeException("[mysql] `{$filename}`: секция `## Материал` отсутствует или пуста.");
        }

        $theorySection = $sections['Теоретические задания'] ?? null;

        if ($theorySection === null) {
            throw new RuntimeException("[mysql] `{$filename}`: секция `## Теоретические задания` отсутствует.");
        }

        $theoryTasks = $this->parseTheoryTasks($theorySection, $filename);

        if ($theoryTasks === []) {
            throw new RuntimeException("[mysql] `{$filename}`: нет ни одного вопроса `### Вопрос N: ...`.");
        }

        $practiceTasks = [];

        if (isset($sections['Практические задания'])) {
            $practiceTasks = $this->parsePracticeTasks($sections['Практические задания'], $filename);
        }

        if ($practice === 'yes' && $practiceTasks === []) {
            throw new RuntimeException("[mysql] `{$filename}`: `practice: yes`, но задания `### Задание N: ...` не найдены.");
        }

        return [
            'slug' => $slug,
            'level_slug' => $levelSlug,
            'order' => (int) $orderValue,
            'title' => $title,
            'material' => $material,
            'theory_tasks' => $theoryTasks,
            'practice_tasks' => $practiceTasks,
        ];
    }

    /**
     * Persist the parsed lesson with its theory and practice tasks in a
     * single transaction: the write spans lessons, theory_tasks,
     * theory_task_options and practice_tasks (project rule 6).
     *
     * @param  array{slug: string, level_slug: string, order: int, title: string, material: string, theory_tasks: list<array{order: int, question: string, options: list<array{order: int, text: string, is_correct: bool, error_text: string|null}>}>, practice_tasks: list<array{order: int, statement: string, expected_result_text: string, seed_sql: string, expected_columns: list<string>, expected_rows: list<array<string, string>>, runtime: PracticeRuntime}>}  $lesson
     */
    private function createLesson(Level $level, array $lesson): void
    {
        DB::transaction(function () use ($level, $lesson): void {
            $created = Lesson::create([
                'level_id' => $level->id,
                'slug' => $lesson['slug'],
                'title' => $lesson['title'],
                'order' => $lesson['order'],
                'material' => $lesson['material'],
                'is_published' => true,
            ]);

            foreach ($lesson['theory_tasks'] as $task) {
                $theoryTask = TheoryTask::create([
                    'lesson_id' => $created->id,
                    'question' => $task['question'],
                    'order' => $task['order'],
                    'is_published' => true,
                ]);

                foreach ($task['options'] as $option) {
                    TheoryTaskOption::create([
                        'theory_task_id' => $theoryTask->id,
                        'text' => $option['text'],
                        'is_correct' => $option['is_correct'],
                        'error_text' => $option['error_text'],
                        'order' => $option['order'],
                    ]);
                }
            }

            foreach ($lesson['practice_tasks'] as $task) {
                PracticeTask::create([
                    'lesson_id' => $created->id,
                    'statement' => $task['statement'],
                    'expected_result_text' => $task['expected_result_text'],
                    'expected_rows' => $task['expected_rows'],
                    'seed_sql' => $task['seed_sql'],
                    'expected_hash' => app(CanonicalResultSerializer::class)->hash($task['expected_rows'], $task['expected_columns']),
                    'order' => $task['order'],
                    'is_published' => true,
                    'runtime' => $task['runtime'],
                ]);
            }
        });
    }

    /**
     * Split the file into its frontmatter map and the markdown body
     * after the closing `---`. Inline ` #` comments are stripped from
     * the template's annotated keys only (see
     * FRONTMATTER_COMMENTED_KEYS), never from values such as `title`
     * that may legitimately contain `#`.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private function splitFrontmatter(string $content, string $filename): array
    {
        $lines = explode("\n", $content);

        if (($lines[0] ?? null) !== '---') {
            throw new RuntimeException("[mysql] `{$filename}`: файл должен начинаться с frontmatter `---`.");
        }

        $map = [];

        foreach (array_slice($lines, 1) as $index => $line) {
            if ($line === '---') {
                return [$map, implode("\n", array_slice($lines, $index + 2))];
            }

            if ($line === '') {
                continue;
            }

            $separator = strpos($line, ':');

            if ($separator === false) {
                throw new RuntimeException("[mysql] `{$filename}`: некорректная строка frontmatter `{$line}`.");
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if (in_array($key, self::FRONTMATTER_COMMENTED_KEYS, true)) {
                $value = trim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
            }

            $map[$key] = $value;
        }

        throw new RuntimeException("[mysql] `{$filename}`: frontmatter не закрыт строкой `---`.");
    }

    /**
     * Split markdown text into chunks started by ATX headings with the
     * given prefix (e.g. `## `): heading text (without the hashes) →
     * raw chunk body. Text before the first matching heading is
     * dropped.
     *
     * @return array<string, string>
     */
    private function splitByHeadings(string $text, string $prefix): array
    {
        $chunks = [];
        $heading = null;
        $buffer = [];

        foreach (explode("\n", $text) as $line) {
            if (str_starts_with($line, $prefix)) {
                if ($heading !== null) {
                    $chunks[$heading] = trim(implode("\n", $buffer));
                }

                $heading = trim(mb_substr($line, strlen($prefix)));
                $buffer = [];

                continue;
            }

            if ($heading !== null) {
                $buffer[] = $line;
            }
        }

        if ($heading !== null) {
            $chunks[$heading] = trim(implode("\n", $buffer));
        }

        return $chunks;
    }

    /**
     * Parse the `## Теоретические задания` section into theory task
     * attributes (docs/mysql-lesson-rule.md §2).
     *
     * @return list<array{order: int, question: string, options: list<array{order: int, text: string, is_correct: bool, error_text: string|null}>}>
     */
    private function parseTheoryTasks(string $section, string $filename): array
    {
        $tasks = [];

        foreach ($this->splitByHeadings($section, '### ') as $heading => $body) {
            if (preg_match('/^Вопрос\s+(\d+):\s*(.+)$/u', $heading, $match) !== 1) {
                throw new RuntimeException("[mysql] `{$filename}`: заголовок `{$heading}` не подходит под формат `### Вопрос N: <текст>`.");
            }

            $options = [];
            $optionOrder = 0;

            foreach (explode("\n", $body) as $line) {
                if (preg_match('/^-\s+(✅|❌)\s*(.+)$/u', trim($line), $item) !== 1) {
                    continue;
                }

                $optionOrder++;

                if ($item[1] === '✅') {
                    $options[] = [
                        'order' => $optionOrder,
                        'text' => trim($item[2]),
                        'is_correct' => true,
                        'error_text' => null,
                    ];

                    continue;
                }

                // Split on the LAST ` — error_text: ` marker: an incorrect
                // option text may itself contain «—» dashes.
                $position = strrpos($item[2], self::ERROR_TEXT_MARKER);

                if ($position === false) {
                    throw new RuntimeException("[mysql] `{$filename}`: у неверного варианта `{$item[2]}` нет пояснения `— error_text: <почему неверен>`.");
                }

                $options[] = [
                    'order' => $optionOrder,
                    'text' => trim(substr($item[2], 0, $position)),
                    'is_correct' => false,
                    'error_text' => trim(substr($item[2], $position + strlen(self::ERROR_TEXT_MARKER))),
                ];
            }

            if ($options === []) {
                throw new RuntimeException("[mysql] `{$filename}`: у вопроса `{$heading}` нет ни одного варианта `- ✅` / `- ❌`.");
            }

            $correctCount = count(array_filter(
                $options,
                static fn (array $option): bool => $option['is_correct'],
            ));

            if ($correctCount !== 1) {
                throw new RuntimeException(sprintf(
                    '[mysql] `%s`: у вопроса `%s` должен быть ровно один правильный вариант `✅`, найдено — %d.',
                    $filename,
                    $heading,
                    $correctCount,
                ));
            }

            $tasks[] = [
                'order' => (int) $match[1],
                'question' => trim($match[2]),
                'options' => $options,
            ];
        }

        return $tasks;
    }

    /**
     * Parse the `## Практические задания` section into practice task
     * attributes (docs/mysql-lesson-rule.md §3, §5). `expected_columns`
     * comes from the table header row and feeds the canonical hash
     * exactly like in DemoCourseSeeder.
     *
     * @return list<array{order: int, statement: string, expected_result_text: string, seed_sql: string, expected_columns: list<string>, expected_rows: list<array<string, string>>, runtime: PracticeRuntime}>
     */
    private function parsePracticeTasks(string $section, string $filename): array
    {
        $tasks = [];

        foreach ($this->splitByHeadings($section, '### ') as $heading => $body) {
            if (preg_match('/^Задание\s+(\d+):\s*(.+)$/u', $heading, $match) !== 1) {
                throw new RuntimeException("[mysql] `{$filename}`: заголовок `{$heading}` не подходит под формат `### Задание N: <название>`.");
            }

            $blocks = $this->splitByMarkers($body);

            foreach (['statement', 'expected_result_text', 'seed_sql', 'expected_rows', 'runtime'] as $marker) {
                if (! array_key_exists($marker, $blocks)) {
                    throw new RuntimeException("[mysql] `{$filename}`: в задании `{$heading}` нет маркера `**{$marker}:**`.");
                }
            }

            [$columns, $rows] = $this->parseExpectedRowsTable($blocks['expected_rows'], $filename);

            $runtime = PracticeRuntime::tryFrom(trim($blocks['runtime']));

            if ($runtime === null) {
                throw new RuntimeException("[mysql] `{$filename}`: в задании `{$heading}` неизвестный runtime `".trim($blocks['runtime']).'`.');
            }

            $tasks[] = [
                'order' => (int) $match[1],
                'statement' => $blocks['statement'],
                'expected_result_text' => $blocks['expected_result_text'],
                'seed_sql' => $this->extractFencedSql($blocks['seed_sql'], $filename),
                'expected_columns' => $columns,
                'expected_rows' => $rows,
                'runtime' => $runtime,
            ];
        }

        return $tasks;
    }

    /**
     * Split a practice task body into `**marker:**` blocks: marker name
     * → block text (the marker line remainder plus everything up to the
     * next marker). Text before the first marker (task intro, HTML
     * comments with reference solutions) is dropped.
     *
     * @return array<string, string>
     */
    private function splitByMarkers(string $body): array
    {
        $blocks = [];
        $marker = null;
        $buffer = [];

        foreach (explode("\n", $body) as $line) {
            if (preg_match('/^\*\*(statement|expected_result_text|seed_sql|expected_rows|runtime):\*\*(.*)$/', $line, $match) === 1) {
                if ($marker !== null) {
                    $blocks[$marker] = trim(implode("\n", $buffer));
                }

                $marker = $match[1];
                $buffer = [$match[2]];

                continue;
            }

            if ($marker !== null) {
                $buffer[] = $line;
            }
        }

        if ($marker !== null) {
            $blocks[$marker] = trim(implode("\n", $buffer));
        }

        return $blocks;
    }

    /**
     * Extract the content of the first fenced code block (the SQL
     * itself, without the fences and the language identifier).
     */
    private function extractFencedSql(string $block, string $filename): string
    {
        $sql = [];
        $inside = false;

        foreach (explode("\n", $block) as $line) {
            if (! $inside && str_starts_with($line, '```')) {
                $inside = true;

                continue;
            }

            if ($inside && str_starts_with($line, '```')) {
                return trim(implode("\n", $sql));
            }

            if ($inside) {
                $sql[] = $line;
            }
        }

        throw new RuntimeException("[mysql] `{$filename}`: в блоке `**seed_sql:**` нет fenced-блока с SQL.");
    }

    /**
     * Parse the markdown table after `**expected_rows:**` into its
     * column list and data rows. Cells stay strings — no type
     * coercion; the canonical serializer normalizes values when
     * hashing.
     *
     * @return array{0: list<string>, 1: list<array<string, string>>}
     */
    private function parseExpectedRowsTable(string $block, string $filename): array
    {
        $tableRows = [];

        foreach (explode("\n", $block) as $line) {
            $line = trim($line);

            if (str_starts_with($line, '|')) {
                $tableRows[] = $this->splitTableRow($line);
            }
        }

        if (count($tableRows) < 2) {
            throw new RuntimeException("[mysql] `{$filename}`: после `**expected_rows:**` нет markdown-таблицы.");
        }

        $columns = $tableRows[0];

        foreach ($tableRows[1] as $separatorCell) {
            if (preg_match('/^:?-+:?$/', $separatorCell) !== 1) {
                throw new RuntimeException("[mysql] `{$filename}`: вторая строка таблицы `**expected_rows:**` не является разделителем `---`.");
            }
        }

        $rows = [];

        foreach (array_slice($tableRows, 2) as $tableRow) {
            if (count($tableRow) !== count($columns)) {
                throw new RuntimeException("[mysql] `{$filename}`: строка таблицы `**expected_rows:**` не совпадает по числу колонок с заголовком.");
            }

            $row = [];

            foreach ($columns as $index => $column) {
                $row[$column] = $tableRow[$index];
            }

            $rows[] = $row;
        }

        return [$columns, $rows];
    }

    /**
     * Split one markdown table row into trimmed cell strings.
     *
     * @return list<string>
     */
    private function splitTableRow(string $line): array
    {
        return array_map(
            static fn (string $cell): string => trim($cell),
            explode('|', trim($line, '|')),
        );
    }
}
