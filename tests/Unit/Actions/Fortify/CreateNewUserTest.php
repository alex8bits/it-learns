<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Fortify;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CreateNewUserTest extends TestCase
{
    public function test_it_creates_user_with_hashed_password_and_assigns_user_role(): void
    {
        $action = app(CreatesNewUsers::class);

        $user = $action->create($this->validInput());

        $this->assertInstanceOf(User::class, $user);
        $this->assertDatabaseHas('users', [
            'email' => 'alice@example.test',
            'name' => 'Alice Example',
        ]);

        $stored = User::where('email', 'alice@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('Password123', $stored->password));

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->hasRole(UserRole::User->value));

        $role = Role::where('name', UserRole::User->value)->where('guard_name', 'web')->firstOrFail();
        $this->assertDatabaseHas('model_has_roles', [
            'model_id' => $user->id,
            'role_id' => $role->id,
            'model_type' => User::class,
        ]);
    }

    public function test_it_creates_role_if_not_exists(): void
    {
        $this->assertDatabaseCount('roles', 0);

        app(CreatesNewUsers::class)->create($this->validInput());

        $this->assertDatabaseHas('roles', [
            'name' => 'User',
            'guard_name' => 'web',
        ]);
    }

    public function test_it_throws_validation_exception_on_duplicate_email(): void
    {
        User::factory()->create([
            'email' => 'duplicate@example.test',
        ]);

        $this->expectException(ValidationException::class);

        app(CreatesNewUsers::class)->create($this->validInput([
            'email' => 'duplicate@example.test',
        ]));
    }

    public function test_it_throws_validation_exception_on_short_password(): void
    {
        $this->expectException(ValidationException::class);

        app(CreatesNewUsers::class)->create($this->validInput([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]));
    }

    public function test_it_throws_validation_exception_on_missing_name(): void
    {
        $this->expectException(ValidationException::class);

        $input = $this->validInput();
        unset($input['name']);

        app(CreatesNewUsers::class)->create($input);
    }

    public function test_it_uses_user_role_enum_value_not_hardcoded(): void
    {
        $user = app(CreatesNewUsers::class)->create($this->validInput([
            'email' => 'enum@example.test',
        ]));

        $names = $user->fresh()->roles->pluck('name')->all();

        $this->assertSame([UserRole::User->value], $names);
    }

    public function test_it_does_not_create_duplicate_role_on_subsequent_calls(): void
    {
        $action = app(CreatesNewUsers::class);

        $action->create($this->validInput([
            'email' => 'first@example.test',
        ]));

        $action->create($this->validInput([
            'name' => 'Bob Example',
            'email' => 'second@example.test',
        ]));

        $this->assertDatabaseCount('roles', 1);
        $this->assertDatabaseCount('users', 2);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Alice Example',
            'email' => 'alice@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], $overrides);
    }
}
