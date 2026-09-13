<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Spatie\Permission\Models\Role;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ], [
            'name.required' => 'Поле «Имя» обязательно для заполнения.',
            'name.string' => 'Поле «Имя» должно быть строкой.',
            'name.max' => 'Поле «Имя» не должно превышать 255 символов.',
            'email.required' => 'Поле «Email» обязательно для заполнения.',
            'email.string' => 'Поле «Email» должно быть строкой.',
            'email.email' => 'Email должен быть корректным адресом электронной почты.',
            'email.max' => 'Поле «Email» не должно превышать 255 символов.',
            'email.unique' => 'Пользователь с таким Email уже зарегистрирован.',
            'password.required' => 'Поле «Пароль» обязательно для заполнения.',
            'password.confirmed' => 'Пароль и подтверждение должны совпадать.',
            'password.min' => 'Пароль должен содержать не менее :min символов.',
            'password.letters' => 'Пароль должен содержать хотя бы одну букву.',
            'password.numbers' => 'Пароль должен содержать хотя бы одну цифру.',
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $role = Role::findOrCreate(UserRole::User->value, 'web');

            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
            ]);

            $user->assignRole($role);

            return $user;
        });
    }
}
