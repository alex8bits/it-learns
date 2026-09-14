<?php

declare(strict_types=1);

namespace App\Enums;

enum SubscriptionTier: string
{
    case Free = 'Free';
    case Premium = 'Premium';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Бесплатный',
            self::Premium => 'Премиум',
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
            static fn (self $tier): array => ['value' => $tier->value, 'label' => $tier->label()],
            self::cases(),
        );
    }
}
