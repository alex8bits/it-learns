<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    /**
     * Seed the bootstrap administrator account. Idempotent — re-running
     * `db:seed` does not overwrite the existing admin.
     */
    public function run(): void
    {
        $email = (string) env('ADMIN_EMAIL', 'admin@example.com');
        $password = (string) (env('ADMIN_PASSWORD') ?: "password");

        $admin = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin',
                'password' => Hash::make($password),
            ],
        );

        if (! $admin->hasRole(UserRole::Admin->value)) {
            $admin->assignRole(UserRole::Admin->value);
        }

        if (! env('ADMIN_PASSWORD')) {
            Log::info("AdminSeeder: сгенерирован пароль для admin'a ({$email}): {$password}");
        }
    }
}
