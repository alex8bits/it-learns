<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Practice\Docker;

use App\Services\Practice\Docker\DockerTableParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit coverage of the TSV normalization shared by the docker
 * driver's execution path: header handling, the mysql/psql NULL
 * representations, the batch unescaping and the degenerate payloads.
 */
class DockerTableParserTest extends TestCase
{
    private DockerTableParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DockerTableParser;
    }

    public function test_parses_a_regular_mysql_batch_result(): void
    {
        $parsed = $this->parser->parse("id\tname\n1\tAlice\n2\tBob\n");

        $this->assertSame(['id', 'name'], $parsed['columns']);
        $this->assertSame([
            ['id' => '1', 'name' => 'Alice'],
            ['id' => '2', 'name' => 'Bob'],
        ], $parsed['rows']);
    }

    public function test_parses_a_regular_psql_result_with_the_marker(): void
    {
        $parsed = $this->parser->parse("id\tname\n1\tAlice\n", DockerTableParser::NULL_MARKER);

        $this->assertSame(['id', 'name'], $parsed['columns']);
        $this->assertSame([['id' => '1', 'name' => 'Alice']], $parsed['rows']);
    }

    public function test_empty_output_is_a_non_select_statement(): void
    {
        $parsed = $this->parser->parse('');

        $this->assertNull($parsed['rows']);
        $this->assertNull($parsed['columns']);
    }

    /**
     * @param  string  $output  whitespace-only payload — still no dataset
     */
    #[DataProvider('noDatasetOutputProvider')]
    public function test_whitespace_only_output_is_a_non_select_statement(string $output): void
    {
        $parsed = $this->parser->parse($output);

        $this->assertNull($parsed['rows']);
        $this->assertNull($parsed['columns']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function noDatasetOutputProvider(): array
    {
        return [
            'newlines only' => ["\n\n"],
            'carriage returns' => ["\r\n\r\n"],
            'spaces and tabs' => [" \t \n"],
        ];
    }

    public function test_header_only_output_is_an_empty_result_set(): void
    {
        $parsed = $this->parser->parse("id\tname\n");

        $this->assertSame(['id', 'name'], $parsed['columns']);
        $this->assertSame([], $parsed['rows']);
    }

    public function test_crlf_line_endings_are_normalized(): void
    {
        $parsed = $this->parser->parse("id\tname\r\n1\tAlice\r\n");

        $this->assertSame(['id', 'name'], $parsed['columns']);
        $this->assertSame([['id' => '1', 'name' => 'Alice']], $parsed['rows']);
    }

    public function test_mysql_null_literal_maps_to_php_null(): void
    {
        $parsed = $this->parser->parse("id\tname\n1\tNULL\n");

        $this->assertNull($parsed['rows'][0]['name']);
    }

    public function test_psql_marker_maps_to_php_null(): void
    {
        $parsed = $this->parser->parse("id\tname\n1\t".DockerTableParser::NULL_MARKER."\n", DockerTableParser::NULL_MARKER);

        $this->assertNull($parsed['rows'][0]['name']);
    }

    /**
     * SELECT NULL; — mysql --batch prints the header and the data line
     * both as the literal "NULL": the column name must stay the string
     * "NULL" while the value maps to PHP null.
     */
    public function test_a_column_named_null_stays_a_string_while_the_value_maps_to_null(): void
    {
        $parsed = $this->parser->parse("NULL\nNULL\n");

        $this->assertSame(['NULL'], $parsed['columns']);
        $this->assertSame([['NULL' => null]], $parsed['rows']);
    }

    public function test_a_null_named_column_mixes_with_regular_columns(): void
    {
        $parsed = $this->parser->parse("id\tNULL\tname\n1\tNULL\tNULL\n");

        $this->assertSame(['id', 'NULL', 'name'], $parsed['columns']);
        $this->assertSame([['id' => '1', 'NULL' => null, 'name' => null]], $parsed['rows']);
    }

    public function test_a_column_named_like_the_psql_marker_stays_a_string(): void
    {
        $parsed = $this->parser->parse(
            "id\t".DockerTableParser::NULL_MARKER."\n1\t".DockerTableParser::NULL_MARKER."\n",
            DockerTableParser::NULL_MARKER,
        );

        $this->assertSame(['id', DockerTableParser::NULL_MARKER], $parsed['columns']);
        $this->assertSame([['id' => '1', DockerTableParser::NULL_MARKER => null]], $parsed['rows']);
    }

    public function test_batch_escapes_in_the_header_are_restored(): void
    {
        $parsed = $this->parser->parse("a\\tb\tplain\n1\t2\n");

        $this->assertSame(["a\tb", 'plain'], $parsed['columns']);
        $this->assertSame([["a\tb" => '1', 'plain' => '2']], $parsed['rows']);
    }

    /**
     * The documented mysql ambiguity: with the psql marker active a
     * literal "NULL" string survives as a string — only the marker is
     * NULL.
     */
    public function test_psql_literal_null_string_survives_as_a_string(): void
    {
        $parsed = $this->parser->parse("id\tname\n1\tNULL\n", DockerTableParser::NULL_MARKER);

        $this->assertSame('NULL', $parsed['rows'][0]['name']);
    }

    public function test_mysql_empty_string_stays_an_empty_string(): void
    {
        $parsed = $this->parser->parse("id\tname\n1\t\n");

        $this->assertSame('', $parsed['rows'][0]['name']);
    }

    public function test_psql_empty_string_is_distinct_from_the_null_marker(): void
    {
        $parsed = $this->parser->parse("id\tname\n1\t\n2\t".DockerTableParser::NULL_MARKER."\n", DockerTableParser::NULL_MARKER);

        $this->assertSame('', $parsed['rows'][0]['name']);
        $this->assertNull($parsed['rows'][1]['name']);
    }

    /**
     * @param  string  $raw  the escaped cell as printed by mysql --batch
     * @param  string  $expected  the restored original value
     */
    #[DataProvider('batchEscapeProvider')]
    public function test_mysql_batch_escapes_are_restored(string $raw, string $expected): void
    {
        $parsed = $this->parser->parse("id\tvalue\n1\t{$raw}\n");

        $this->assertSame($expected, $parsed['rows'][0]['value']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function batchEscapeProvider(): array
    {
        return [
            'escaped tab' => ['a\\tb', "a\tb"],
            'escaped newline' => ['a\\nb', "a\nb"],
            'escaped backslash' => ['a\\\\b', 'a\\b'],
            'escaped backslash before escaped tab' => ['a\\\\\\tb', "a\\\tb"],
            'plain text untouched' => ['plain', 'plain'],
            'escape-free path-like value' => ['C:\\\\temp', 'C:\\temp'],
        ];
    }

    public function test_escaped_newline_keeps_the_row_on_one_physical_line(): void
    {
        $parsed = $this->parser->parse("id\tvalue\n1\tline\\nbreak\n2\tother\n");

        $this->assertSame("line\nbreak", $parsed['rows'][0]['value']);
        $this->assertCount(2, $parsed['rows']);
    }

    public function test_row_with_missing_cells_is_padded_with_nulls(): void
    {
        $parsed = $this->parser->parse("id\tname\tcity\n1");

        $this->assertSame([['id' => '1', 'name' => null, 'city' => null]], $parsed['rows']);
    }

    public function test_single_column_result(): void
    {
        $parsed = $this->parser->parse("count\n42\n");

        $this->assertSame(['count'], $parsed['columns']);
        $this->assertSame([['count' => '42']], $parsed['rows']);
    }

    public function test_numeric_and_boolean_like_strings_stay_strings(): void
    {
        $parsed = $this->parser->parse("a\tb\tc\n1\t1.50\ttrue\n");

        $this->assertSame([['a' => '1', 'b' => '1.50', 'c' => 'true']], $parsed['rows']);
    }
}
