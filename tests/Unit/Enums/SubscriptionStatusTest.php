<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\SubscriptionStatus;
use PHPUnit\Framework\TestCase;

class SubscriptionStatusTest extends TestCase
{
    public function test_active_case_returns_string_value(): void
    {
        $this->assertSame('Active', SubscriptionStatus::Active->value);
    }

    public function test_active_case_returns_human_label(): void
    {
        $this->assertSame('Активна', SubscriptionStatus::Active->label());
    }

    public function test_cancelled_case_returns_string_value(): void
    {
        $this->assertSame('Cancelled', SubscriptionStatus::Cancelled->value);
    }

    public function test_cancelled_case_returns_human_label(): void
    {
        $this->assertSame('Отменена', SubscriptionStatus::Cancelled->label());
    }

    public function test_expired_case_returns_string_value(): void
    {
        $this->assertSame('Expired', SubscriptionStatus::Expired->value);
    }

    public function test_expired_case_returns_human_label(): void
    {
        $this->assertSame('Истекла', SubscriptionStatus::Expired->label());
    }

    public function test_pending_case_returns_string_value(): void
    {
        $this->assertSame('Pending', SubscriptionStatus::Pending->value);
    }

    public function test_pending_case_returns_human_label(): void
    {
        $this->assertSame('Ожидает оплаты', SubscriptionStatus::Pending->label());
    }

    public function test_it_has_four_cases(): void
    {
        $this->assertCount(4, SubscriptionStatus::cases());
    }

    public function test_options_pair_every_case_value_with_its_label(): void
    {
        $this->assertSame([
            ['value' => 'Active', 'label' => 'Активна'],
            ['value' => 'Cancelled', 'label' => 'Отменена'],
            ['value' => 'Expired', 'label' => 'Истекла'],
            ['value' => 'Pending', 'label' => 'Ожидает оплаты'],
        ], SubscriptionStatus::options());
    }
}
