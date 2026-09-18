<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Runtime used to execute a student's practice solution. Stage 5
 * shipped the local SQLite runtime; Stage 10 adds the Docker SQL
 * runtimes (Mysql, Postgres). Python and Bash stay reserved for a
 * future stage — the practice contract compares tabular results
 * (rows/columns plus the canonical hash), which executing arbitrary
 * code does not fit yet.
 */
enum PracticeRuntime: string
{
    case Sqlite = 'sqlite';

    case Mysql = 'mysql';

    case Postgres = 'postgres';

    public function label(): string
    {
        return match ($this) {
            self::Sqlite => 'SQLite (локальный файл)',
            self::Mysql => 'MySQL (Docker)',
            self::Postgres => 'PostgreSQL (Docker)',
        };
    }

    /**
     * Options for <select> controls and label lookups: every case paired
     * with its Russian label. Passed to Vue as a prop so the frontend does
     * not duplicate the enum values in JS constants.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $runtime): array => ['value' => $runtime->value, 'label' => $runtime->label()],
            self::cases(),
        );
    }
}
