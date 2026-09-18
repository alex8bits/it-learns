<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\AdminAuditAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminAuditActionTest extends TestCase
{
    public function test_user_role_changed_case_returns_string_value(): void
    {
        $this->assertSame('UserRoleChanged', AdminAuditAction::UserRoleChanged->value);
    }

    public function test_user_blocked_case_returns_string_value(): void
    {
        $this->assertSame('UserBlocked', AdminAuditAction::UserBlocked->value);
    }

    public function test_user_unblocked_case_returns_string_value(): void
    {
        $this->assertSame('UserUnblocked', AdminAuditAction::UserUnblocked->value);
    }

    public function test_prompt_version_created_case_returns_string_value(): void
    {
        $this->assertSame('PromptVersionCreated', AdminAuditAction::PromptVersionCreated->value);
    }

    public function test_prompt_playground_run_case_returns_string_value(): void
    {
        $this->assertSame('PromptPlaygroundRun', AdminAuditAction::PromptPlaygroundRun->value);
    }

    public function test_user_llm_limit_adjusted_case_returns_string_value(): void
    {
        $this->assertSame('UserLlmLimitAdjusted', AdminAuditAction::UserLlmLimitAdjusted->value);
    }

    public function test_payment_refunded_case_returns_string_value(): void
    {
        $this->assertSame('PaymentRefunded', AdminAuditAction::PaymentRefunded->value);
    }

    /**
     * @return array<string, array{0: AdminAuditAction, 1: string}>
     */
    public static function courseActionProvider(): array
    {
        return [
            'course created' => [AdminAuditAction::CourseCreated, 'Курс создан'],
            'course updated' => [AdminAuditAction::CourseUpdated, 'Курс обновлён'],
            'course published' => [AdminAuditAction::CoursePublished, 'Курс опубликован'],
            'course archived' => [AdminAuditAction::CourseArchived, 'Курс отправлен в архив'],
            'course deleted' => [AdminAuditAction::CourseDeleted, 'Курс удалён'],
            'level created' => [AdminAuditAction::LevelCreated, 'Уровень создан'],
            'level updated' => [AdminAuditAction::LevelUpdated, 'Уровень обновлён'],
            'level deleted' => [AdminAuditAction::LevelDeleted, 'Уровень удалён'],
            'lesson created' => [AdminAuditAction::LessonCreated, 'Урок создан'],
            'lesson updated' => [AdminAuditAction::LessonUpdated, 'Урок обновлён'],
            'lesson deleted' => [AdminAuditAction::LessonDeleted, 'Урок удалён'],
            'theory task created' => [AdminAuditAction::TheoryTaskCreated, 'Создано теоретическое задание'],
            'theory task updated' => [AdminAuditAction::TheoryTaskUpdated, 'Теоретическое задание обновлено'],
            'theory task deleted' => [AdminAuditAction::TheoryTaskDeleted, 'Теоретическое задание удалено'],
            'practice task created' => [AdminAuditAction::PracticeTaskCreated, 'Создано практическое задание'],
            'practice task updated' => [AdminAuditAction::PracticeTaskUpdated, 'Обновлено практическое задание'],
            'practice task deleted' => [AdminAuditAction::PracticeTaskDeleted, 'Удалено практическое задание'],
        ];
    }

    #[DataProvider('courseActionProvider')]
    public function test_course_action_case_resolves_from_value_with_its_label(
        AdminAuditAction $action,
        string $expectedLabel,
    ): void {
        $this->assertSame($action, AdminAuditAction::from($action->value));
        $this->assertSame($expectedLabel, $action->label());
    }

    public function test_user_role_changed_label(): void
    {
        $this->assertSame('Смена роли пользователя', AdminAuditAction::UserRoleChanged->label());
    }

    public function test_user_blocked_label(): void
    {
        $this->assertSame('Блокировка пользователя', AdminAuditAction::UserBlocked->label());
    }

    public function test_user_unblocked_label(): void
    {
        $this->assertSame('Разблокировка пользователя', AdminAuditAction::UserUnblocked->label());
    }

    public function test_prompt_version_created_label(): void
    {
        $this->assertSame('Создана версия промпта', AdminAuditAction::PromptVersionCreated->label());
    }

    public function test_prompt_playground_run_label(): void
    {
        $this->assertSame('Тестовый запуск промпта (playground)', AdminAuditAction::PromptPlaygroundRun->label());
    }

    public function test_prompt_playground_run_resolves_from_its_value(): void
    {
        $this->assertSame(AdminAuditAction::PromptPlaygroundRun, AdminAuditAction::from('PromptPlaygroundRun'));
    }

    public function test_user_llm_limit_adjusted_label(): void
    {
        $this->assertSame('Корректировка LLM-лимита пользователя', AdminAuditAction::UserLlmLimitAdjusted->label());
    }

    public function test_payment_refunded_label(): void
    {
        $this->assertSame('Возврат платежа', AdminAuditAction::PaymentRefunded->label());
    }

    public function test_payment_refunded_resolves_from_its_value(): void
    {
        $this->assertSame(AdminAuditAction::PaymentRefunded, AdminAuditAction::from('PaymentRefunded'));
    }

    public function test_it_has_twenty_four_cases(): void
    {
        $this->assertCount(24, AdminAuditAction::cases());
    }

    public function test_options_pair_every_case_value_with_its_label(): void
    {
        $this->assertSame([
            ['value' => 'UserRoleChanged', 'label' => 'Смена роли пользователя'],
            ['value' => 'UserBlocked', 'label' => 'Блокировка пользователя'],
            ['value' => 'UserUnblocked', 'label' => 'Разблокировка пользователя'],
            ['value' => 'PromptVersionCreated', 'label' => 'Создана версия промпта'],
            ['value' => 'PromptPlaygroundRun', 'label' => 'Тестовый запуск промпта (playground)'],
            ['value' => 'UserLlmLimitAdjusted', 'label' => 'Корректировка LLM-лимита пользователя'],
            ['value' => 'CourseCreated', 'label' => 'Курс создан'],
            ['value' => 'CourseUpdated', 'label' => 'Курс обновлён'],
            ['value' => 'CoursePublished', 'label' => 'Курс опубликован'],
            ['value' => 'CourseArchived', 'label' => 'Курс отправлен в архив'],
            ['value' => 'CourseDeleted', 'label' => 'Курс удалён'],
            ['value' => 'LevelCreated', 'label' => 'Уровень создан'],
            ['value' => 'LevelUpdated', 'label' => 'Уровень обновлён'],
            ['value' => 'LevelDeleted', 'label' => 'Уровень удалён'],
            ['value' => 'LessonCreated', 'label' => 'Урок создан'],
            ['value' => 'LessonUpdated', 'label' => 'Урок обновлён'],
            ['value' => 'LessonDeleted', 'label' => 'Урок удалён'],
            ['value' => 'TheoryTaskCreated', 'label' => 'Создано теоретическое задание'],
            ['value' => 'TheoryTaskUpdated', 'label' => 'Теоретическое задание обновлено'],
            ['value' => 'TheoryTaskDeleted', 'label' => 'Теоретическое задание удалено'],
            ['value' => 'PracticeTaskCreated', 'label' => 'Создано практическое задание'],
            ['value' => 'PracticeTaskUpdated', 'label' => 'Обновлено практическое задание'],
            ['value' => 'PracticeTaskDeleted', 'label' => 'Удалено практическое задание'],
            ['value' => 'PaymentRefunded', 'label' => 'Возврат платежа'],
        ], AdminAuditAction::options());
    }
}
