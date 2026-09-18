<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Practice;

use App\Support\Practice\SqlStatementSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SqlStatementSplitterTest extends TestCase
{
    private SqlStatementSplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new SqlStatementSplitter;
    }

    /**
     * @param  non-empty-list<string>  $expected
     */
    #[DataProvider('singleStatementProvider')]
    public function test_code_without_unquoted_semicolon_is_one_statement(string $code, array $expected): void
    {
        $this->assertSame($expected, $this->splitter->split($code));
    }

    /**
     * @return array<string, array{string, non-empty-list<string>}>
     */
    public static function singleStatementProvider(): array
    {
        return [
            'single statement' => ['SELECT 1', ['SELECT 1']],
            'empty code' => ['', ['']],
            'semicolon inside single-quoted literal' => ["SELECT 'a;b' AS marker", ["SELECT 'a;b' AS marker"]],
            'semicolon inside single-quoted literal with doubled-quote escape' => [
                "SELECT 'it''s;fine' AS marker",
                ["SELECT 'it''s;fine' AS marker"],
            ],
            'semicolon inside double-quoted identifier' => ['SELECT "a;b" AS marker', ['SELECT "a;b" AS marker']],
            'semicolon inside line comment' => ['SELECT 1 AS one -- ; not a separator', ['SELECT 1 AS one ']],
            'semicolon inside block comment' => ['/* ; */ SELECT 1 AS one', [' SELECT 1 AS one']],
            'unterminated line comment' => ['SELECT 1 -- unterminated', ['SELECT 1 ']],
            'unterminated block comment' => ['/* unterminated', ['']],
            'unterminated literal' => ["SELECT 'abc", ["SELECT 'abc"]],
            'utf-8 multibyte payload' => ["SELECT 'тест;x' AS п", ["SELECT 'тест;x' AS п"]],
        ];
    }

    /**
     * @param  non-empty-list<string>  $expected
     */
    #[DataProvider('multipleStatementsProvider')]
    public function test_unquoted_semicolons_split_statements(string $code, array $expected): void
    {
        $this->assertSame($expected, $this->splitter->split($code));
    }

    /**
     * @return array<string, array{string, non-empty-list<string>}>
     */
    public static function multipleStatementsProvider(): array
    {
        return [
            'lowercase keywords' => ['select 1; select 2', ['select 1', ' select 2']],
            'two inserts' => [
                "INSERT INTO users (name) VALUES ('X'); INSERT INTO users (name) VALUES ('Y')",
                ["INSERT INTO users (name) VALUES ('X')", " INSERT INTO users (name) VALUES ('Y')"],
            ],
            'destructive first statement' => ['DROP TABLE users; SELECT 1', ['DROP TABLE users', ' SELECT 1']],
            'trailing semicolon leaves an empty tail fragment' => ['SELECT 1 AS one;', ['SELECT 1 AS one', '']],
            'leading semicolon leaves an empty head fragment' => [';SELECT 1', ['', 'SELECT 1']],
            'three statements' => ['A;B;C', ['A', 'B', 'C']],
        ];
    }

    /**
     * @param  non-empty-list<string>  $expected
     */
    #[DataProvider('commentStrippingProvider')]
    public function test_comments_are_skipped_so_the_real_first_keyword_surfaces(string $code, array $expected): void
    {
        $this->assertSame($expected, $this->splitter->split($code));
    }

    /**
     * @return array<string, array{string, non-empty-list<string>}>
     */
    public static function commentStrippingProvider(): array
    {
        return [
            'line comment before keyword' => ["-- comment\nVACUUM", ["\nVACUUM"]],
            'block comment before keyword' => ['/* x */ PRAGMA max_page_count = 0', [' PRAGMA max_page_count = 0']],
            'comment between statements' => [
                'SELECT 1 /* mid ; */ ; SELECT 2',
                ['SELECT 1  ', ' SELECT 2'],
            ],
            'comment tokens inside a literal are literal text' => ["SELECT '-- not a comment'", ["SELECT '-- not a comment'"]],
        ];
    }

    public function test_fragments_keep_surrounding_whitespace_untrimmed(): void
    {
        // The caller (the single-statement guard) trims the fragments;
        // the splitter itself is a faithful scanner.
        $this->assertSame(
            ['  SELECT 1  ', '  SELECT 2  '],
            $this->splitter->split('  SELECT 1  ;  SELECT 2  '),
        );
    }
}
