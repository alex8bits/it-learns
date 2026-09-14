<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\PaymentStatus;
use PHPUnit\Framework\TestCase;

class PaymentStatusTest extends TestCase
{
    public function test_pending_case_returns_string_value(): void
    {
        $this->assertSame('Pending', PaymentStatus::Pending->value);
    }

    public function test_pending_case_returns_human_label(): void
    {
        $this->assertSame('Ожидает', PaymentStatus::Pending->label());
    }

    public function test_succeeded_case_returns_string_value(): void
    {
        $this->assertSame('Succeeded', PaymentStatus::Succeeded->value);
    }

    public function test_succeeded_case_returns_human_label(): void
    {
        $this->assertSame('Успешен', PaymentStatus::Succeeded->label());
    }

    public function test_failed_case_returns_string_value(): void
    {
        $this->assertSame('Failed', PaymentStatus::Failed->value);
    }

    public function test_failed_case_returns_human_label(): void
    {
        $this->assertSame('Неудачен', PaymentStatus::Failed->label());
    }

    public function test_refunded_case_returns_string_value(): void
    {
        $this->assertSame('Refunded', PaymentStatus::Refunded->value);
    }

    public function test_refunded_case_returns_human_label(): void
    {
        $this->assertSame('Возвращён', PaymentStatus::Refunded->label());
    }

    public function test_it_has_four_cases(): void
    {
        $this->assertCount(4, PaymentStatus::cases());
    }

    public function test_options_pair_every_case_value_with_its_label(): void
    {
        $this->assertSame([
            ['value' => 'Pending', 'label' => 'Ожидает'],
            ['value' => 'Succeeded', 'label' => 'Успешен'],
            ['value' => 'Failed', 'label' => 'Неудачен'],
            ['value' => 'Refunded', 'label' => 'Возвращён'],
        ], PaymentStatus::options());
    }
}
