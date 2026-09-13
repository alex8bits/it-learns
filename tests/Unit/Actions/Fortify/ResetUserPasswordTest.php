<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Fortify;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ResetUserPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resets_password_with_valid_token(): void
    {
        $user = User::factory()->create();

        app(ResetUserPassword::class)->reset($user, [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
    }

    public function test_it_throws_validation_exception_on_short_password(): void
    {
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(ResetUserPassword::class)->reset($user, [
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);
    }

    public function test_it_throws_validation_exception_on_missing_password_confirmation(): void
    {
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(ResetUserPassword::class)->reset($user, [
            'password' => 'newpassword123',
        ]);
    }
}
