<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\PracticeEnvironmentStatus;
use PHPUnit\Framework\TestCase;

class PracticeEnvironmentStatusTest extends TestCase
{
    public function test_provisioning_case_returns_string_value(): void
    {
        $this->assertSame('provisioning', PracticeEnvironmentStatus::Provisioning->value);
    }

    public function test_provisioning_case_returns_human_label(): void
    {
        $this->assertSame('Среда подготавливается', PracticeEnvironmentStatus::Provisioning->label());
    }

    public function test_ready_case_returns_string_value(): void
    {
        $this->assertSame('ready', PracticeEnvironmentStatus::Ready->value);
    }

    public function test_ready_case_returns_human_label(): void
    {
        $this->assertSame('Среда готова', PracticeEnvironmentStatus::Ready->label());
    }

    public function test_running_case_returns_string_value(): void
    {
        $this->assertSame('running', PracticeEnvironmentStatus::Running->value);
    }

    public function test_running_case_returns_human_label(): void
    {
        $this->assertSame('Запрос исполняется', PracticeEnvironmentStatus::Running->label());
    }

    public function test_failed_case_returns_string_value(): void
    {
        $this->assertSame('failed', PracticeEnvironmentStatus::Failed->value);
    }

    public function test_failed_case_returns_human_label(): void
    {
        $this->assertSame('Ошибка среды', PracticeEnvironmentStatus::Failed->label());
    }

    public function test_destroyed_case_returns_string_value(): void
    {
        $this->assertSame('destroyed', PracticeEnvironmentStatus::Destroyed->value);
    }

    public function test_destroyed_case_returns_human_label(): void
    {
        $this->assertSame('Среда уничтожена', PracticeEnvironmentStatus::Destroyed->label());
    }

    public function test_it_has_five_cases(): void
    {
        $this->assertCount(5, PracticeEnvironmentStatus::cases());
    }

    public function test_options_pair_every_case_value_with_its_label(): void
    {
        $this->assertSame([
            ['value' => 'provisioning', 'label' => 'Среда подготавливается'],
            ['value' => 'ready', 'label' => 'Среда готова'],
            ['value' => 'running', 'label' => 'Запрос исполняется'],
            ['value' => 'failed', 'label' => 'Ошибка среды'],
            ['value' => 'destroyed', 'label' => 'Среда уничтожена'],
        ], PracticeEnvironmentStatus::options());
    }
}
