<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Fortify;

use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UpdateUserPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_password_with_correct_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        app(UpdateUserPassword::class)->update($user, [
            'current_password' => 'password',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
    }

    public function test_it_throws_validation_exception_on_wrong_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->expectException(ValidationException::class);

        app(UpdateUserPassword::class)->update($user, [
            'current_password' => 'wrong',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);
    }

    public function test_it_hashes_new_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        app(UpdateUserPassword::class)->update($user, [
            'current_password' => 'password',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $freshUser = $user->fresh();
        $this->assertNotSame('newpassword123', $freshUser->password);
        $this->assertTrue(Hash::check('newpassword123', $freshUser->password));
    }
}
