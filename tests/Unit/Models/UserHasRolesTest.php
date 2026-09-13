<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use Tests\TestCase;

class UserHasRolesTest extends TestCase
{
    public function test_user_has_role_returns_false_for_nonexistent_role(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasRole('nonexistent'));
    }
}
