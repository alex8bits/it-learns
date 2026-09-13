<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Admin;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Admin\AdminDashboardService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDashboardServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The `admin()` factory state expects the Admin role to exist.
        Role::findOrCreate(UserRole::Admin->value, 'web');
        Role::findOrCreate(UserRole::User->value, 'web');
    }

    public function test_counters_on_empty_db(): void
    {
        $counters = app(AdminDashboardService::class)->counters();

        $this->assertSame([
            'users_total' => 0,
            'admins_total' => 0,
            'users_blocked' => 0,
            'premium_active' => 0,
            'payments_month' => 0,
            'courses_published' => 0,
        ], $counters);
    }

    public function test_counters_with_data(): void
    {
        User::factory()->count(3)->create();
        User::factory()->admin()->create();
        User::factory()->blocked()->create();

        $counters = app(AdminDashboardService::class)->counters();

        $this->assertSame(5, $counters['users_total']);
        $this->assertSame(1, $counters['admins_total']);
        $this->assertSame(1, $counters['users_blocked']);
        $this->assertSame(0, $counters['premium_active']);
        $this->assertSame(0, $counters['payments_month']);
        $this->assertSame(0, $counters['courses_published']);
    }
}
