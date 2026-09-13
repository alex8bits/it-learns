<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\AdminAuditAction;
use PHPUnit\Framework\TestCase;

class AdminAuditActionTest extends TestCase
{
    public function test_user_role_changed_case_returns_string_value(): void
    {
        $this->assertSame('UserRoleChanged', AdminAuditAction::UserRoleChanged->value);
    }

    public function test_user_blocked_case_returns_string_value(): void
    {
        $this->assertSame('UserBlocked', AdminAuditAction::UserBlocked->value);
    }

    public function test_user_unblocked_case_returns_string_value(): void
    {
        $this->assertSame('UserUnblocked', AdminAuditAction::UserUnblocked->value);
    }

    public function test_user_role_changed_label(): void
    {
        $this->assertSame('Смена роли пользователя', AdminAuditAction::UserRoleChanged->label());
    }

    public function test_user_blocked_label(): void
    {
        $this->assertSame('Блокировка пользователя', AdminAuditAction::UserBlocked->label());
    }

    public function test_user_unblocked_label(): void
    {
        $this->assertSame('Разблокировка пользователя', AdminAuditAction::UserUnblocked->label());
    }

    public function test_it_has_three_cases(): void
    {
        $this->assertCount(3, AdminAuditAction::cases());
    }
}
