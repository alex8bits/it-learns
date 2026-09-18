<?php

declare(strict_types=1);

namespace App\Enums;

enum CourseProgressStatus: string
{
    case InProgress = 'InProgress';
    case Completed = 'Completed';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'В процессе',
            self::Completed => 'Пройден',
        };
    }

    /**
     * Options for <select> controls and label lookups: every case paired
     * with its Russian label. Passed to Vue as a prop so the frontend does
     * not duplicate the enum values in JS constants. «Не начат» is the
     * absence of a progress row, not a case.
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
