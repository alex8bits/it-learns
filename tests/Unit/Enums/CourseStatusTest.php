<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\CourseStatus;
use PHPUnit\Framework\TestCase;

class CourseStatusTest extends TestCase
{
    public function test_draft_case_returns_string_value(): void
    {
        $this->assertSame('Draft', CourseStatus::Draft->value);
    }

    public function test_draft_case_returns_human_label(): void
    {
        $this->assertSame('Черновик', CourseStatus::Draft->label());
    }

    public function test_published_case_returns_string_value(): void
    {
        $this->assertSame('Published', CourseStatus::Published->value);
    }

    public function test_published_case_returns_human_label(): void
    {
        $this->assertSame('Опубликован', CourseStatus::Published->label());
    }

    public function test_archived_case_returns_string_value(): void
    {
        $this->assertSame('Archived', CourseStatus::Archived->value);
    }

    public function test_archived_case_returns_human_label(): void
    {
        $this->assertSame('В архиве', CourseStatus::Archived->label());
    }

    public function test_it_has_three_cases(): void
    {
        $this->assertCount(3, CourseStatus::cases());
    }

    public function test_options_pair_every_case_value_with_its_label(): void
    {
        $this->assertSame([
            ['value' => 'Draft', 'label' => 'Черновик'],
            ['value' => 'Published', 'label' => 'Опубликован'],
            ['value' => 'Archived', 'label' => 'В архиве'],
        ], CourseStatus::options());
    }
}
