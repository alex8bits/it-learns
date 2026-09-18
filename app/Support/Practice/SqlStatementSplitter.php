<?php

declare(strict_types=1);

namespace App\Support\Practice;

/**
 * SQL statement splitter shared by the practice environment drivers:
 * extracted verbatim from LocalSqlitePracticeEnvironment (Stage 5) so
 * the Docker driver (Stage 10) enforces the same single-statement
 * guard on identically parsed input. Pure and stateless.
 */
final class SqlStatementSplitter
{
    /**
     * Split the code into statements on unquoted semicolons. The
     * scanner tracks '…' and "…" literals (with doubled-quote
     * escapes); line (--) and block comments are skipped rather than
     * copied, so every statement surfaces with its real first
     * keyword — the blacklisted-keyword guard depends on it, because
     * SQLite executes a comment-prefixed statement and the comment
     * must not mask the keyword. A semicolon inside a literal or a
     * comment stays literal text. UTF-8 multibyte sequences never
     * collide with the single-byte tokens, so a byte-wise walk is
     * safe.
     *
     * @return list<string>
     */
    public function split(string $code): array
    {
        $statements = [];
        $current = '';
        $length = strlen($code);

        $i = 0;

        while ($i < $length) {
            $char = $code[$i];

            if ($char === '-' && ($code[$i + 1] ?? '') === '-') {
                while ($i < $length && $code[$i] !== "\n") {
                    $i++;
                }

                continue;
            }

            if ($char === '/' && ($code[$i + 1] ?? '') === '*') {
                $i += 2;

                while ($i < $length && ($code[$i] !== '*' || ($code[$i + 1] ?? '') !== '/')) {
                    $i++;
                }

                if ($i < $length) {
                    $i += 2;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $current .= $char;
                $i++;

                while ($i < $length) {
                    $current .= $code[$i];

                    if ($code[$i] === $quote) {
                        if (($code[$i + 1] ?? '') === $quote) {
                            $current .= $code[$i + 1];
                            $i += 2;

                            continue;
                        }

                        $i++;

                        break;
                    }

                    $i++;
                }

                continue;
            }

            if ($char === ';') {
                $statements[] = $current;
                $current = '';
                $i++;

                continue;
            }

            $current .= $char;
            $i++;
        }

        $statements[] = $current;

        return $statements;
    }
}
