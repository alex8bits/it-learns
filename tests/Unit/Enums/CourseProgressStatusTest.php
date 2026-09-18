<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\CourseProgressStatus;
use PHPUnit\Framework\TestCase;

class CourseProgressStatusTest extends TestCase
{
    public function test_in_progress_case_returns_string_value(): void
    {
        $this->assertSame('InProgress', CourseProgressStatus::InProgress->value);
    }

    public function test_in_progress_case_returns_human_label(): void
    {
        $this->assertSame('В процессе', CourseProgressStatus::InProgress->label());
    }

    public function test_completed_case_returns_string_value(): void
    {
        $this->assertSame('Completed', CourseProgressStatus::Completed->value);
    }

    public function test_completed_case_returns_human_label(): void
    {
        $this->assertSame('Пройден', CourseProgressStatus::Completed->label());
    }

    public function test_it_has_two_cases(): void
    {
        $this->assertCount(2, CourseProgressStatus::cases());
    }

    public function test_options_pair_every_case_value_with_its_label(): void
    {
        $this->assertSame([
            ['value' => 'InProgress', 'label' => 'В процессе'],
            ['value' => 'Completed', 'label' => 'Пройден'],
        ], CourseProgressStatus::options());
    }
}
