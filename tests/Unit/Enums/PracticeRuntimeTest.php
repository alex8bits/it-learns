<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\PracticeRuntime;
use PHPUnit\Framework\TestCase;

class PracticeRuntimeTest extends TestCase
{
    public function test_sqlite_case_returns_string_value(): void
    {
        $this->assertSame('sqlite', PracticeRuntime::Sqlite->value);
    }

    public function test_mysql_case_returns_string_value(): void
    {
        $this->assertSame('mysql', PracticeRuntime::Mysql->value);
    }

    public function test_postgres_case_returns_string_value(): void
    {
        $this->assertSame('postgres', PracticeRuntime::Postgres->value);
    }

    public function test_sqlite_case_returns_human_label(): void
    {
        $this->assertSame('SQLite (локальный файл)', PracticeRuntime::Sqlite->label());
    }

    public function test_mysql_case_returns_human_label(): void
    {
        $this->assertSame('MySQL (Docker)', PracticeRuntime::Mysql->label());
    }

    public function test_postgres_case_returns_human_label(): void
    {
        $this->assertSame('PostgreSQL (Docker)', PracticeRuntime::Postgres->label());
    }

    public function test_it_has_exactly_the_three_stage10_runtimes(): void
    {
        $this->assertSame(
            [PracticeRuntime::Sqlite, PracticeRuntime::Mysql, PracticeRuntime::Postgres],
            PracticeRuntime::cases(),
        );
    }

    public function test_options_pair_every_case_value_with_its_label(): void
    {
        $this->assertSame([
            ['value' => 'sqlite', 'label' => 'SQLite (локальный файл)'],
            ['value' => 'mysql', 'label' => 'MySQL (Docker)'],
            ['value' => 'postgres', 'label' => 'PostgreSQL (Docker)'],
        ], PracticeRuntime::options());
    }
}
