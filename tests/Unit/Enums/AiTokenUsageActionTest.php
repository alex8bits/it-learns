<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\AiTokenUsageAction;
use PHPUnit\Framework\TestCase;

class AiTokenUsageActionTest extends TestCase
{
    public function test_feedback_case_returns_string_value(): void
    {
        $this->assertSame('Feedback', AiTokenUsageAction::Feedback->value);
    }

    public function test_extra_task_case_returns_string_value(): void
    {
        $this->assertSame('ExtraTask', AiTokenUsageAction::ExtraTask->value);
    }

    public function test_playground_case_returns_string_value(): void
    {
        $this->assertSame('Playground', AiTokenUsageAction::Playground->value);
    }

    public function test_feedback_label(): void
    {
        $this->assertSame('Фидбэк по ошибке', AiTokenUsageAction::Feedback->label());
    }

    public function test_extra_task_label(): void
    {
        $this->assertSame('Генерация доп. задачи', AiTokenUsageAction::ExtraTask->label());
    }

    public function test_playground_label(): void
    {
        $this->assertSame('Тестовый запуск промпта', AiTokenUsageAction::Playground->label());
    }

    public function test_playground_resolves_from_its_value(): void
    {
        $this->assertSame(AiTokenUsageAction::Playground, AiTokenUsageAction::from('Playground'));
    }

    public function test_it_has_three_cases(): void
    {
        $this->assertCount(3, AiTokenUsageAction::cases());
    }
}
