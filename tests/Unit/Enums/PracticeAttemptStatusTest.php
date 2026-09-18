<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\PracticeAttemptStatus;
use PHPUnit\Framework\TestCase;

class PracticeAttemptStatusTest extends TestCase
{
    public function test_passed_case_returns_string_value(): void
    {
        $this->assertSame('passed', PracticeAttemptStatus::Passed->value);
    }

    public function test_passed_case_returns_human_label(): void
    {
        $this->assertSame('Задание пройдено', PracticeAttemptStatus::Passed->label());
    }

    public function test_failed_case_returns_string_value(): void
    {
        $this->assertSame('failed', PracticeAttemptStatus::Failed->value);
    }

    public function test_failed_case_returns_human_label(): void
    {
        $this->assertSame('Результат не совпал с эталоном', PracticeAttemptStatus::Failed->label());
    }

    public function test_error_case_returns_string_value(): void
    {
        $this->assertSame('error', PracticeAttemptStatus::Error->value);
    }

    public function test_error_case_returns_human_label(): void
    {
        $this->assertSame('Ошибка исполнения', PracticeAttemptStatus::Error->label());
    }

    public function test_busy_case_returns_string_value(): void
    {
        $this->assertSame('busy', PracticeAttemptStatus::Busy->value);
    }

    public function test_busy_case_returns_human_label(): void
    {
        $this->assertSame('Среда уже занята другой попыткой', PracticeAttemptStatus::Busy->label());
    }

    public function test_it_has_four_cases(): void
    {
        $this->assertCount(4, PracticeAttemptStatus::cases());
    }

    public function test_options_pair_every_case_value_with_its_label(): void
    {
        $this->assertSame([
            ['value' => 'passed', 'label' => 'Задание пройдено'],
            ['value' => 'failed', 'label' => 'Результат не совпал с эталоном'],
            ['value' => 'error', 'label' => 'Ошибка исполнения'],
            ['value' => 'busy', 'label' => 'Среда уже занята другой попыткой'],
        ], PracticeAttemptStatus::options());
    }
}
