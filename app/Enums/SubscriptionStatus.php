<?php

declare(strict_types=1);

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active = 'Active';
    case Cancelled = 'Cancelled';
    case Expired = 'Expired';
    case Pending = 'Pending';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Активна',
            self::Cancelled => 'Отменена',
            self::Expired => 'Истекла',
            self::Pending => 'Ожидает оплаты',
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
