<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public function test_user_case_returns_string_value(): void
    {
        $this->assertSame('User', UserRole::User->value);
    }

    public function test_user_case_returns_human_label(): void
    {
        $this->assertSame('Пользователь', UserRole::User->label());
    }

    public function test_admin_case_returns_string_value(): void
    {
        $this->assertSame('Admin', UserRole::Admin->value);
    }

    public function test_admin_case_returns_human_label(): void
    {
        $this->assertSame('Администратор', UserRole::Admin->label());
    }

    public function test_it_has_user_and_admin_cases(): void
    {
        $this->assertCount(2, UserRole::cases());
    }
}
