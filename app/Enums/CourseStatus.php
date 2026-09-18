<?php

declare(strict_types=1);

namespace App\Enums;

enum CourseStatus: string
{
    case Draft = 'Draft';
    case Published = 'Published';
    case Archived = 'Archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Published => 'Опубликован',
            self::Archived => 'В архиве',
        };
    }

    /**
     * Options for admin <select> controls and label lookups: every case
     * paired with its Russian label. Passed to Vue as a prop so the
     * frontend does not duplicate the enum values in JS constants.
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
