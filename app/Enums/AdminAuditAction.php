<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminAuditAction: string
{
    case UserRoleChanged = 'UserRoleChanged';
    case UserBlocked = 'UserBlocked';
    case UserUnblocked = 'UserUnblocked';
    case PromptVersionCreated = 'PromptVersionCreated';
    case PromptPlaygroundRun = 'PromptPlaygroundRun';
    case UserLlmLimitAdjusted = 'UserLlmLimitAdjusted';
    case CourseCreated = 'CourseCreated';
    case CourseUpdated = 'CourseUpdated';
    case CoursePublished = 'CoursePublished';
    case CourseArchived = 'CourseArchived';
    case CourseDeleted = 'CourseDeleted';
    case LevelCreated = 'LevelCreated';
    case LevelUpdated = 'LevelUpdated';
    case LevelDeleted = 'LevelDeleted';
    case LessonCreated = 'LessonCreated';
    case LessonUpdated = 'LessonUpdated';
    case LessonDeleted = 'LessonDeleted';
    case TheoryTaskCreated = 'TheoryTaskCreated';
    case TheoryTaskUpdated = 'TheoryTaskUpdated';
    case TheoryTaskDeleted = 'TheoryTaskDeleted';
    case PracticeTaskCreated = 'PracticeTaskCreated';
    case PracticeTaskUpdated = 'PracticeTaskUpdated';
    case PracticeTaskDeleted = 'PracticeTaskDeleted';
    case PaymentRefunded = 'PaymentRefunded';

    public function label(): string
    {
        return match ($this) {
            self::UserRoleChanged => 'Смена роли пользователя',
            self::UserBlocked => 'Блокировка пользователя',
            self::UserUnblocked => 'Разблокировка пользователя',
            self::PromptVersionCreated => 'Создана версия промпта',
            self::PromptPlaygroundRun => 'Тестовый запуск промпта (playground)',
            self::UserLlmLimitAdjusted => 'Корректировка LLM-лимита пользователя',
            self::CourseCreated => 'Курс создан',
            self::CourseUpdated => 'Курс обновлён',
            self::CoursePublished => 'Курс опубликован',
            self::CourseArchived => 'Курс отправлен в архив',
            self::CourseDeleted => 'Курс удалён',
            self::LevelCreated => 'Уровень создан',
            self::LevelUpdated => 'Уровень обновлён',
            self::LevelDeleted => 'Уровень удалён',
            self::LessonCreated => 'Урок создан',
            self::LessonUpdated => 'Урок обновлён',
            self::LessonDeleted => 'Урок удалён',
            self::TheoryTaskCreated => 'Создано теоретическое задание',
            self::TheoryTaskUpdated => 'Теоретическое задание обновлено',
            self::TheoryTaskDeleted => 'Теоретическое задание удалено',
            self::PracticeTaskCreated => 'Создано практическое задание',
            self::PracticeTaskUpdated => 'Обновлено практическое задание',
            self::PracticeTaskDeleted => 'Удалено практическое задание',
            self::PaymentRefunded => 'Возврат платежа',
        };
    }

    /**
     * Options for admin <select> controls: every case paired with its
     * Russian label. Passed to Vue as a prop so the frontend does not
     * duplicate the enum values in JS constants.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $action): array => ['value' => $action->value, 'label' => $action->label()],
            self::cases(),
        );
    }
}
