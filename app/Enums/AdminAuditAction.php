<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminAuditAction: string
{
    case UserRoleChanged = 'UserRoleChanged';
    case UserBlocked = 'UserBlocked';
    case UserUnblocked = 'UserUnblocked';

    public function label(): string
    {
        return match ($this) {
            self::UserRoleChanged => 'Смена роли пользователя',
            self::UserBlocked => 'Блокировка пользователя',
            self::UserUnblocked => 'Разблокировка пользователя',
        };
    }
}
