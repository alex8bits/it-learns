<?php

declare(strict_types=1);

namespace App\Services\Practice\Docker;

/**
 * Normalizes the tab-separated CLI output of the engine clients
 * (mysql --batch / psql -A -F "\t") into the rows/columns shape of the
 * practice contract. Pure and stateless — the entire docker driver
 * shares it for readiness probes, seeding and the student's query.
 *
 * Known ambiguity (accepted by design, see the Stage 10 design doc):
 * in the default mysql mode a data cell equal to the literal string
 * "NULL" is indistinguishable from SQL NULL — the canonical serializer
 * keeps them distinct on the author side, so a hash collision is
 * impossible; at worst an exotic string value yields a false negative.
 * Header cells are exempt: a column name is always a string, so the
 * literal header "NULL" of SELECT NULL; never becomes PHP null. In
 * psql mode the dedicated marker below makes NULL explicit and the
 * ambiguity disappears. Values containing raw tabs/newlines that the
 * client did not escape (psql unaligned does not escape) cannot be
 * recovered — also documented as out of scope.
 */
final class DockerTableParser
{
    /**
     * The -P null=<marker> representation of SQL NULL used for psql.
     * Deliberately exotic so it can never occur in real data.
     */
    public const NULL_MARKER = '␀ITLEARNS_NULL␀';

    /**
     * Parse the TSV output. The first non-empty line is the header
     * (both clients print column names first); a payload without any
     * line is a non-SELECT statement (rows and columns both null),
     * while a header-only payload is an empty result set.
     *
     * @param  string  $output  raw stdout of the engine client
     * @param  string|null  $nullMarker  the NULL representation of the engine client; null selects the mysql batch literal "NULL"
     * @return array{rows: array<int, array<string, mixed>>|null, columns: list<string>|null}
     */
    public function parse(string $output, ?string $nullMarker = null): array
    {
        $payload = str_replace("\r\n", "\n", $output);

        if (trim($payload) === '') {
            return ['rows' => null, 'columns' => null];
        }

        // Only trailing newlines are stripped: a full trim would eat
        // the trailing tab of a last row whose final cell is an empty
        // string.
        $lines = explode("\n", rtrim($payload, "\n"));

        // Header cells are column names, never values, so the NULL
        // mapping deliberately does not apply to them: SELECT NULL;
        // makes mysql --batch print the literal header "NULL", which
        // must stay the string "NULL" (a PHP null here would fatal
        // unescape(), which takes string). Only the batch escapes are
        // restored — column names are escaped exactly like values.
        $columns = [];

        foreach (explode("\t", (string) array_shift($lines)) as $cell) {
            $columns[] = $this->unescape($cell);
        }

        $rows = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            /** @var list<string|null> $cells */
            $cells = $this->splitCells($line, $nullMarker);

            $row = [];

            foreach ($columns as $index => $column) {
                $row[$column] = array_key_exists($index, $cells) ? $cells[$index] : null;
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'columns' => $columns];
    }

    /**
     * Split one physical data line into cells on real tabs, mapping
     * the NULL representation of the engine client to PHP null and
     * restoring the batch escapes of the surviving strings. Escaped
     * tabs (\t as two characters) survive the split — that is exactly
     * why the engines escape them. Data lines only: the header is
     * parsed in parse() without the NULL mapping (see there).
     *
     * @return list<string|null>
     */
    private function splitCells(string $line, ?string $nullMarker = null): array
    {
        $sentinel = $nullMarker ?? 'NULL';

        return array_map(
            function (string $cell) use ($sentinel): string|null {
                return $cell === $sentinel ? null : $this->unescape($cell);
            },
            explode("\t", $line),
        );
    }

    /**
     * Undo the mysql batch escaping (psql output simply never contains
     * these two-character sequences unless the data did): \\ -> \,
     * \t -> tab, \n -> newline. A single left-to-right scan is
     * required: sequential str_replace passes would re-interpret the
     * backslash of a restored \\ followed by a literal t. Unknown
     * escapes (\0, \Z of mysql) are kept verbatim.
     */
    private function unescape(string $cell): string
    {
        $result = '';
        $length = strlen($cell);

        for ($i = 0; $i < $length; $i++) {
            $char = $cell[$i];

            if ($char !== '\\' || $i + 1 >= $length) {
                $result .= $char;

                continue;
            }

            $next = $cell[++$i];

            $result .= match ($next) {
                '\\' => '\\',
                't' => "\t",
                'n' => "\n",
                default => '\\'.$next,
            };
        }

        return $result;
    }
}
