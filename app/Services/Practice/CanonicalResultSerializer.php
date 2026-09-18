<?php

declare(strict_types=1);

namespace App\Services\Practice;

/**
 * Canonical serialization of SQL result sets (docs/concept.md §5.4,
 * platform plan Stage 5): the expected hash of a practice task and the
 * hash of a student's attempt are only comparable when both sides run
 * through the same normalization first. Row order, column order, string
 * case/whitespace and numeric formatting must not change the hash.
 * Pure class — no DB/HTTP, unit-testable in isolation; consumed by
 * LocalSqlitePracticeEnvironment::compare().
 */
final class CanonicalResultSerializer
{
    /**
     * Sentinel for SQL NULL so that null, an empty string and a zero
     * normalize to three distinct values. Deliberately uppercase:
     * regular strings are lowercased during normalization and can
     * never collide with it.
     */
    private const NULL_SENTINEL = '__NULL__';

    /**
     * Deterministic hash of a result set: row order, column order, string
     * case/whitespace and numeric formatting must not change the hash.
     * Emulates "ORDER BY all columns" by sorting the encoded rows
     * lexicographically. Never throws: a non-list input behaves like an
     * empty result set, non-array row entries are skipped, and an empty
     * result set hashes the empty string.
     *
     * @param  mixed  $rows  result rows (assoc arrays)
     * @param  list<string>  $columns  column names defining the canonical key order
     */
    public function hash(mixed $rows, array $columns): string
    {
        if (! is_array($rows)) {
            $rows = [];
        }

        $jsonRows = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $canonicalRow = [];

            foreach ($columns as $column) {
                if (array_key_exists($column, $row)) {
                    $canonicalRow[$column] = $this->normalizeValue($row[$column]);
                }
            }

            $json = json_encode($canonicalRow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $jsonRows[] = is_string($json) ? $json : '';
        }

        usort($jsonRows, strcmp(...));

        return hash('sha256', implode("\n", $jsonRows));
    }

    /**
     * Normalize a single cell: SQL NULL becomes a sentinel string,
     * booleans become "1"/"0" (the SQLite convention), numbers become
     * decimal strings with a fixed precision (integral values without
     * a fraction part), strings are trimmed and lowercased, anything
     * else falls back to a JSON encoding.
     */
    private function normalizeValue(mixed $value): string
    {
        if ($value === null) {
            return self::NULL_SENTINEL;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value) || is_numeric($value)) {
            return $value == (int) $value
                ? sprintf('%d', (int) $value)
                : sprintf('%.6F', (float) $value);
        }

        if (is_string($value)) {
            return mb_strtolower(trim($value));
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }
}
