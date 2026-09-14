<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'Pending';
    case Succeeded = 'Succeeded';
    case Failed = 'Failed';
    case Refunded = 'Refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает',
            self::Succeeded => 'Успешен',
            self::Failed => 'Неудачен',
            self::Refunded => 'Возвращён',
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
            static fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
