<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle stage of an isolated practice environment. Stage 5 (local
 * SQLite) only persists Ready, Failed and Destroyed; Provisioning and
 * Running are reserved for the async/Docker stage.
 */
enum PracticeEnvironmentStatus: string
{
    case Provisioning = 'provisioning';
    case Ready = 'ready';
    case Running = 'running';
    case Failed = 'failed';
    case Destroyed = 'destroyed';

    public function label(): string
    {
        return match ($this) {
            self::Provisioning => 'Среда подготавливается',
            self::Ready => 'Среда готова',
            self::Running => 'Запрос исполняется',
            self::Failed => 'Ошибка среды',
            self::Destroyed => 'Среда уничтожена',
        };
    }

    /**
     * Options for <select> controls and label lookups: every case paired
     * with its Russian label. Passed to Vue as a prop so the frontend does
     * not duplicate the enum values in JS constants.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
