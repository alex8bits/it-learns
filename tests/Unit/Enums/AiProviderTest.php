<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\AiProvider;
use PHPUnit\Framework\TestCase;

class AiProviderTest extends TestCase
{
    public function test_openai_case_returns_string_value(): void
    {
        $this->assertSame('openai', AiProvider::Openai->value);
    }

    public function test_anthropic_case_returns_string_value(): void
    {
        $this->assertSame('anthropic', AiProvider::Anthropic->value);
    }

    public function test_minimax_case_returns_string_value(): void
    {
        $this->assertSame('minimax', AiProvider::Minimax->value);
    }

    public function test_openai_compatible_case_returns_string_value(): void
    {
        $this->assertSame('openai_compatible', AiProvider::OpenaiCompatible->value);
    }

    public function test_dummy_case_returns_string_value(): void
    {
        $this->assertSame('dummy', AiProvider::Dummy->value);
    }

    public function test_openai_label(): void
    {
        $this->assertSame('OpenAI', AiProvider::Openai->label());
    }

    public function test_anthropic_label(): void
    {
        $this->assertSame('Anthropic', AiProvider::Anthropic->label());
    }

    public function test_minimax_label(): void
    {
        $this->assertSame('MiniMax', AiProvider::Minimax->label());
    }

    public function test_openai_compatible_label(): void
    {
        $this->assertSame('OpenAI-совместимый', AiProvider::OpenaiCompatible->label());
    }

    public function test_dummy_label(): void
    {
        $this->assertSame('Фиктивный (dev/тесты)', AiProvider::Dummy->label());
    }

    public function test_it_has_five_cases(): void
    {
        $this->assertCount(5, AiProvider::cases());
    }
}
