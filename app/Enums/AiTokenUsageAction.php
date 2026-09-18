<?php

declare(strict_types=1);

namespace App\Enums;

enum AiTokenUsageAction: string
{
    case Feedback = 'Feedback';
    case ExtraTask = 'ExtraTask';
    case Playground = 'Playground';

    public function label(): string
    {
        return match ($this) {
            self::Feedback => 'Фидбэк по ошибке',
            self::ExtraTask => 'Генерация доп. задачи',
            self::Playground => 'Тестовый запуск промпта',
        };
    }
}
