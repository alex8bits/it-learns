<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Outcome of a single practice attempt as returned by the submit
 * endpoint. Busy marks a lost Cache::lock race (another attempt of the
 * same user is already running), not an execution failure.
 */
enum PracticeAttemptStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Error = 'error';
    case Busy = 'busy';

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Задание пройдено',
            self::Failed => 'Результат не совпал с эталоном',
            self::Error => 'Ошибка исполнения',
            self::Busy => 'Среда уже занята другой попыткой',
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
