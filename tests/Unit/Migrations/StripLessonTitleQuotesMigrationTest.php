<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use App\Models\Lesson;
use Tests\TestCase;

class StripLessonTitleQuotesMigrationTest extends TestCase
{
    public function test_up_strips_exactly_one_layer_of_surrounding_quotes(): void
    {
        $doubleQuoted = Lesson::factory()->create(['title' => '"Заголовок в кавычках"']);
        $singleQuoted = Lesson::factory()->create(['title' => "'Одинарные'"]);

        $this->runUp();

        $this->assertSame('Заголовок в кавычках', $doubleQuoted->refresh()->title);
        $this->assertSame('Одинарные', $singleQuoted->refresh()->title);
    }

    public function test_up_keeps_inner_quotes_and_strips_only_the_outer_layer(): void
    {
        $nested = Lesson::factory()->create(['title' => '"Обрамляющие и "внутренние" кавычки"']);

        $this->runUp();

        $this->assertSame('Обрамляющие и "внутренние" кавычки', $nested->refresh()->title);
    }

    public function test_up_leaves_titles_without_a_paired_surrounding_quote_untouched(): void
    {
        $unquoted = Lesson::factory()->create(['title' => 'Без кавычек']);
        $unpaired = Lesson::factory()->create(['title' => '"Непарные']);
        $innerOnly = Lesson::factory()->create(['title' => 'Кавычки "внутри" текста']);
        $singleCharacter = Lesson::factory()->create(['title' => '"']);

        $this->runUp();

        $this->assertSame('Без кавычек', $unquoted->refresh()->title);
        $this->assertSame('"Непарные', $unpaired->refresh()->title);
        $this->assertSame('Кавычки "внутри" текста', $innerOnly->refresh()->title);
        // A value shorter than two characters can never be a paired quote.
        $this->assertSame('"', $singleCharacter->refresh()->title);
    }

    public function test_up_is_idempotent(): void
    {
        $lesson = Lesson::factory()->create(['title' => '"Заголовок в кавычках"']);

        $this->runUp();
        $this->runUp();

        $this->assertSame('Заголовок в кавычках', $lesson->refresh()->title);
    }

    public function test_down_is_a_no_op(): void
    {
        $stripped = Lesson::factory()->create(['title' => 'Заголовок без кавычек']);
        $quoted = Lesson::factory()->create(['title' => '"Заголовок в кавычках"']);

        $this->runDown();

        // The original YAML quoting cannot be reconstructed
        // deterministically, so down() must leave every row as is.
        $this->assertSame('Заголовок без кавычек', $stripped->refresh()->title);
        $this->assertSame('"Заголовок в кавычках"', $quoted->refresh()->title);
    }

    /**
     * Pattern note: RefreshDatabase has already run every migration
     * (including this one, as a no-op over the then-empty table), so
     * `artisan migrate` would skip it as already-run. The supported
     * way to exercise one migration's data fix is to require its
     * file — a migration file returns its anonymous class instance —
     * and call up()/down() directly against rows created after the
     * schema is in place. The framework's Migration contract does
     * not declare up()/down() (a migration may implement only one of
     * them), so each call is narrowed with method_exists().
     */
    private function runUp(): void
    {
        $migration = $this->migration();

        assert(method_exists($migration, 'up'));

        $migration->up();
    }

    private function runDown(): void
    {
        $migration = $this->migration();

        assert(method_exists($migration, 'down'));

        $migration->down();
    }

    private function migration(): object
    {
        $migration = require database_path('migrations/2026_10_02_061155_strip_surrounding_quotes_from_lesson_titles_table.php');

        assert(is_object($migration));

        return $migration;
    }
}
