<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seed the development test user (`user@example.com` / `password`).
     * Idempotent — re-running `db:seed` does not overwrite the existing user.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'user@example.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
            ],
        );

        if (! $user->hasRole(UserRole::User->value)) {
            $user->assignRole(UserRole::User->value);
        }
    }
}
