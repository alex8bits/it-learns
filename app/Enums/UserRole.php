<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case User = 'User';
    case Admin = 'Admin';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Пользователь',
            self::Admin => 'Администратор',
        };
    }
}
