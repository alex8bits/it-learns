<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Seed the application's roles (and leave the door open for permissions
     * added in later stages). Idempotent — safe to call repeatedly.
     */
    public function run(): void
    {
        Role::firstOrCreate(['name' => UserRole::User->value, 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => UserRole::Admin->value, 'guard_name' => 'web']);
    }
}
