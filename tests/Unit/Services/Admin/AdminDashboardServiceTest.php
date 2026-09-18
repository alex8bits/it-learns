<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Admin;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Admin\AdminDashboardService;
use Illuminate\Support\Carbon;
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
        Course::factory()->count(2)->published()->create();
        Course::factory()->create();
        Course::factory()->archived()->create();

        $counters = app(AdminDashboardService::class)->counters();

        $this->assertSame(5, $counters['users_total']);
        $this->assertSame(1, $counters['admins_total']);
        $this->assertSame(1, $counters['users_blocked']);
        $this->assertSame(0, $counters['premium_active']);
        $this->assertSame(0, $counters['payments_month']);
        // 2 published courses count; the draft and the archived one do not.
        $this->assertSame(2, $counters['courses_published']);
    }

    public function test_premium_active_counts_in_force_subscriptions_including_cancelled(): void
    {
        Subscription::factory()->count(2)->create();
        Subscription::factory()->cancelled()->create();
        Subscription::factory()->expired()->create();
        Subscription::factory()->pending()->create();
        Subscription::factory()->create([
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);

        $counters = app(AdminDashboardService::class)->counters();

        // 2 in-force Active + 1 Cancelled whose paid period has not ended;
        // Expired, Pending and a lapsed Active period do not count.
        $this->assertSame(3, $counters['premium_active']);
    }

    public function test_payments_month_counts_only_succeeded_payments_of_current_month(): void
    {
        Payment::factory()->count(3)->succeeded()->create();
        Payment::factory()->failed()->create();
        Payment::factory()->refunded()->create();
        $this->createPaymentAt(now()->startOfMonth()->subSecond());

        $counters = app(AdminDashboardService::class)->counters();

        $this->assertSame(3, $counters['payments_month']);
    }

    /**
     * Create a payment with an explicit `created_at`. The timestamp is
     * written via a direct attribute update, so the factory default
     * (real `now()`) does not interfere.
     */
    private function createPaymentAt(Carbon $createdAt): Payment
    {
        $payment = Payment::factory()->succeeded()->create();

        $payment->created_at = $createdAt;
        $payment->save();

        return $payment;
    }
}
