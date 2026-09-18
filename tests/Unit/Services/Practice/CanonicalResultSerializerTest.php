<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Practice;

use App\Services\Practice\CanonicalResultSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CanonicalResultSerializerTest extends TestCase
{
    private CanonicalResultSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new CanonicalResultSerializer;
    }

    public function test_row_order_does_not_change_hash(): void
    {
        $columns = ['id', 'name'];

        $rowsA = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
            ['id' => 3, 'name' => 'Carol'],
        ];

        $rowsB = [
            ['id' => 3, 'name' => 'Carol'],
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];

        $this->assertSame(
            $this->serializer->hash($rowsA, $columns),
            $this->serializer->hash($rowsB, $columns),
        );
    }

    public function test_column_order_does_not_change_hash(): void
    {
        $columns = ['a', 'b'];

        $this->assertSame(
            $this->serializer->hash([['a' => 1, 'b' => 'x']], $columns),
            $this->serializer->hash([['b' => 'x', 'a' => 1]], $columns),
        );
    }

    public function test_string_case_and_whitespace_do_not_change_hash(): void
    {
        $this->assertSame(
            $this->serializer->hash([['name' => 'ABC']], ['name']),
            $this->serializer->hash([['name' => ' abc ']], ['name']),
        );
    }

    public function test_unicode_case_and_whitespace_do_not_change_hash(): void
    {
        $this->assertSame(
            $this->serializer->hash([['city' => 'МОСКВА']], ['city']),
            $this->serializer->hash([['city' => ' Москва ']], ['city']),
        );
    }

    public function test_null_empty_string_and_zero_produce_three_distinct_hashes(): void
    {
        $nullHash = $this->serializer->hash([['v' => null]], ['v']);
        $emptyHash = $this->serializer->hash([['v' => '']], ['v']);
        $zeroHash = $this->serializer->hash([['v' => 0]], ['v']);

        $this->assertNotSame($nullHash, $emptyHash);
        $this->assertNotSame($nullHash, $zeroHash);
        $this->assertNotSame($emptyHash, $zeroHash);
    }

    public function test_literal_null_sentinel_string_is_not_confused_with_actual_null(): void
    {
        $this->assertNotSame(
            $this->serializer->hash([['v' => '__NULL__']], ['v']),
            $this->serializer->hash([['v' => null]], ['v']),
        );
    }

    public function test_different_content_produces_different_hash(): void
    {
        $columns = ['id', 'name'];

        $this->assertNotSame(
            $this->serializer->hash([['id' => 1, 'name' => 'Alice']], $columns),
            $this->serializer->hash([['id' => 2, 'name' => 'Bob']], $columns),
        );
    }

    public function test_hash_is_deterministic_across_consecutive_calls(): void
    {
        $rows = [
            ['id' => 1, 'name' => ' Alice '],
            ['id' => 2, 'name' => 'bob'],
        ];

        $this->assertSame(
            $this->serializer->hash($rows, ['id', 'name']),
            $this->serializer->hash($rows, ['id', 'name']),
        );
    }

    public function test_empty_row_set_yields_valid_64_hex_hash(): void
    {
        $hash = $this->serializer->hash([], ['id']);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function test_columns_outside_the_canonical_list_are_ignored(): void
    {
        $this->assertSame(
            $this->serializer->hash([['id' => 1, 'name' => 'Alice', 'extra' => 'ignored']], ['id']),
            $this->serializer->hash([['id' => 1]], ['id']),
        );
    }

    public function test_non_array_rows_input_behaves_like_empty_row_set(): void
    {
        $this->assertSame(
            $this->serializer->hash('not-a-result-set', ['id']),
            $this->serializer->hash([], ['id']),
        );
    }

    public function test_non_array_row_entries_are_skipped(): void
    {
        $rows = [['a' => 1], 'garbage', 42, null];

        $this->assertSame(
            $this->serializer->hash($rows, ['a']),
            $this->serializer->hash([['a' => 1]], ['a']),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rowsA
     * @param  list<array<string, mixed>>  $rowsB
     * @param  list<string>  $columns
     */
    #[DataProvider('equivalentResultSetsProvider')]
    public function test_equivalent_result_sets_hash_identically(array $rowsA, array $rowsB, array $columns): void
    {
        $this->assertSame(
            $this->serializer->hash($rowsA, $columns),
            $this->serializer->hash($rowsB, $columns),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rowsA
     * @param  list<array<string, mixed>>  $rowsB
     * @param  list<string>  $columns
     */
    #[DataProvider('differentResultSetsProvider')]
    public function test_different_result_sets_hash_differently(array $rowsA, array $rowsB, array $columns): void
    {
        $this->assertNotSame(
            $this->serializer->hash($rowsA, $columns),
            $this->serializer->hash($rowsB, $columns),
        );
    }

    /**
     * @param  list<string>  $columns
     */
    #[DataProvider('arbitraryInputProvider')]
    public function test_hash_is_always_64_hex_characters(mixed $rows, array $columns): void
    {
        $hash = $this->serializer->hash($rows, $columns);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<string>}>
     */
    public static function equivalentResultSetsProvider(): array
    {
        return [
            'integer one as int vs float' => [
                [['n' => 1]],
                [['n' => 1.0]],
                ['n'],
            ],
            'integer one as int vs string' => [
                [['n' => 1]],
                [['n' => '1']],
                ['n'],
            ],
            'float one vs string "1.0"' => [
                [['n' => 1.0]],
                [['n' => '1.0']],
                ['n'],
            ],
            'float 1.5 vs string "1.5"' => [
                [['n' => 1.5]],
                [['n' => '1.5']],
                ['n'],
            ],
            'float 1.5 vs string "1.50" with trailing zero' => [
                [['n' => 1.5]],
                [['n' => '1.50']],
                ['n'],
            ],
            'negative integer vs its string spelling' => [
                [['n' => -3]],
                [['n' => '-3']],
                ['n'],
            ],
            'zero as int vs string' => [
                [['n' => 0]],
                [['n' => '0']],
                ['n'],
            ],
            'whitespace-padded numeric string vs int' => [
                [['n' => ' 42 ']],
                [['n' => 42]],
                ['n'],
            ],
            'floats differing beyond fixed precision collapse' => [
                [['n' => 0.1 + 0.2]],
                [['n' => 0.3]],
                ['n'],
            ],
            'boolean true normalizes like integer one' => [
                [['flag' => true]],
                [['flag' => 1]],
                ['flag'],
            ],
            'boolean false normalizes like integer zero' => [
                [['flag' => false]],
                [['flag' => 0]],
                ['flag'],
            ],
            'whitespace-only string equals empty string' => [
                [['s' => '   ']],
                [['s' => '']],
                ['s'],
            ],
        ];
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<string>}>
     */
    public static function differentResultSetsProvider(): array
    {
        return [
            'integer value differs' => [
                [['n' => 1]],
                [['n' => 2]],
                ['n'],
            ],
            'integer vs float differing within fixed precision' => [
                [['n' => 1]],
                [['n' => 1.0000001]],
                ['n'],
            ],
            'extra row appended' => [
                [['n' => 1]],
                [['n' => 1], ['n' => 2]],
                ['n'],
            ],
            'row removed' => [
                [['n' => 1], ['n' => 2]],
                [['n' => 1]],
                ['n'],
            ],
            'null vs empty string' => [
                [['v' => null]],
                [['v' => '']],
                ['v'],
            ],
            'null vs zero' => [
                [['v' => null]],
                [['v' => 0]],
                ['v'],
            ],
            'empty string vs zero' => [
                [['v' => '']],
                [['v' => 0]],
                ['v'],
            ],
            'string content differs' => [
                [['s' => 'alice']],
                [['s' => 'bob']],
                ['s'],
            ],
        ];
    }

    /**
     * @return array<string, array{0: mixed, 1: list<string>}>
     */
    public static function arbitraryInputProvider(): array
    {
        return [
            'empty row set' => [
                [],
                ['id'],
            ],
            'empty column list' => [
                [['id' => 1]],
                [],
            ],
            'null, bool, float and string values' => [
                [['a' => null, 'b' => true, 'c' => 2.5, 'd' => 'x']],
                ['a', 'b', 'c', 'd'],
            ],
            'nested array value falls back to json' => [
                [['a' => ['nested' => 1]]],
                ['a'],
            ],
            'row missing a canonical column' => [
                [['a' => 1]],
                ['a', 'missing'],
            ],
            'non-array rows input' => [
                'garbage',
                ['a'],
            ],
            'non-array row entries' => [
                [['a' => 1], 'garbage'],
                ['a'],
            ],
        ];
    }
}
