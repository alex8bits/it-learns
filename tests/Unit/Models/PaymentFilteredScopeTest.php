<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Tests\TestCase;

class PaymentFilteredScopeTest extends TestCase
{
    private User $payer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payer = User::factory()->create(['email' => 'payer@example.com']);
    }

    public function test_filtered_without_filters_returns_all_payments(): void
    {
        $this->createPayment();
        $this->createPayment();

        $this->assertSame(2, Payment::query()->filtered()->count());
    }

    public function test_filtered_by_status_returns_only_matching_status(): void
    {
        $succeeded = $this->createPayment(status: PaymentStatus::Succeeded);
        $this->createPayment(status: PaymentStatus::Failed);

        $ids = Payment::query()
            ->filtered(status: PaymentStatus::Succeeded)
            ->pluck('id');

        $this->assertSame([$succeeded->id], $ids->all());
    }

    public function test_filtered_by_email_returns_only_that_users_payments(): void
    {
        $match = $this->createPayment(user: $this->payer);
        $this->createPayment();

        $ids = Payment::query()->filtered(email: 'payer@example.com')->pluck('id');

        $this->assertSame([$match->id], $ids->all());
    }

    public function test_filtered_by_email_substring_matches(): void
    {
        $match = $this->createPayment(user: $this->payer);
        $this->createPayment();

        $ids = Payment::query()->filtered(email: 'payer@')->pluck('id');

        $this->assertSame([$match->id], $ids->all());
    }

    public function test_filtered_by_null_email_returns_all_payments(): void
    {
        $this->createPayment();
        $this->createPayment();

        $this->assertSame(2, Payment::query()->filtered(email: null)->count());
    }

    public function test_filtered_by_date_from_includes_whole_starting_day(): void
    {
        $dayBefore = $this->createPayment(createdAt: '2026-09-12 21:30:00');
        $this->createPayment(createdAt: '2026-09-13 00:00:00');
        $this->createPayment(createdAt: '2026-09-13 09:45:00');

        $ids = Payment::query()->filtered(dateFrom: '2026-09-13')->pluck('id');

        $this->assertSame(2, $ids->count());
        $this->assertNotContains($dayBefore->id, $ids->all());
    }

    public function test_filtered_by_date_to_includes_payments_created_later_that_day(): void
    {
        $this->createPayment(createdAt: '2026-09-13 00:00:00');
        $noon = $this->createPayment(createdAt: '2026-09-13 12:00:00');
        $this->createPayment(createdAt: '2026-09-13 23:59:59');

        $ids = Payment::query()->filtered(dateTo: '2026-09-13')->pluck('id');

        $this->assertSame(3, $ids->count());
        $this->assertContains($noon->id, $ids->all());
    }

    public function test_filtered_by_date_to_excludes_next_day(): void
    {
        $lastMinute = $this->createPayment(createdAt: '2026-09-13 23:59:59');
        $nextDay = $this->createPayment(createdAt: '2026-09-14 00:00:00');

        $ids = Payment::query()->filtered(dateTo: '2026-09-13')->pluck('id');

        $this->assertSame([$lastMinute->id], $ids->all());
        $this->assertNotContains($nextDay->id, $ids->all());
    }

    public function test_filtered_combines_all_filters(): void
    {
        $match = $this->createPayment(
            createdAt: '2026-09-13 12:00:00',
            status: PaymentStatus::Failed,
            user: $this->payer,
        );
        $this->createPayment(createdAt: '2026-09-13 12:00:00', status: PaymentStatus::Succeeded, user: $this->payer);
        $this->createPayment(createdAt: '2026-09-13 12:00:00', status: PaymentStatus::Failed);
        $this->createPayment(createdAt: '2026-09-15 12:00:00', status: PaymentStatus::Failed, user: $this->payer);

        $ids = Payment::query()->filtered(
            status: PaymentStatus::Failed,
            email: 'payer@example.com',
            dateFrom: '2026-09-13',
            dateTo: '2026-09-14',
        )->pluck('id');

        $this->assertSame([$match->id], $ids->all());
    }

    /**
     * Create a payment with an explicit `created_at`. The boundary
     * timestamp is written via a direct attribute update, so the factory
     * default (real `now()`) does not interfere.
     */
    private function createPayment(
        string $createdAt = '2026-09-13 12:00:00',
        ?PaymentStatus $status = null,
        ?User $user = null,
    ): Payment {
        $attributes = [
            'status' => $status ?? PaymentStatus::Succeeded,
        ];

        if ($user !== null) {
            $attributes['user_id'] = $user->id;
        }

        $payment = Payment::factory()->create($attributes);

        $payment->created_at = $createdAt;
        $payment->save();

        return $payment;
    }
}
