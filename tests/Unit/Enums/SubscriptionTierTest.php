<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\SubscriptionTier;
use PHPUnit\Framework\TestCase;

class SubscriptionTierTest extends TestCase
{
    public function test_free_case_returns_string_value(): void
    {
        $this->assertSame('Free', SubscriptionTier::Free->value);
    }

    public function test_free_case_returns_human_label(): void
    {
        $this->assertSame('Бесплатный', SubscriptionTier::Free->label());
    }

    public function test_premium_case_returns_string_value(): void
    {
        $this->assertSame('Premium', SubscriptionTier::Premium->value);
    }

    public function test_premium_case_returns_human_label(): void
    {
        $this->assertSame('Премиум', SubscriptionTier::Premium->label());
    }

    public function test_it_has_free_and_premium_cases(): void
    {
        $this->assertCount(2, SubscriptionTier::cases());
    }

    public function test_options_pair_every_case_value_with_its_label(): void
    {
        $this->assertSame([
            ['value' => 'Free', 'label' => 'Бесплатный'],
            ['value' => 'Premium', 'label' => 'Премиум'],
        ], SubscriptionTier::options());
    }
}
