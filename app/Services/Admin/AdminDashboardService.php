<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;

/**
 * Aggregates the headline counters shown on the admin dashboard.
 *
 * Only `courses_published` is pinned to `0`: the Course model and its
 * published flag land in Stage 5+, and the frontend renders a
 * "Запланировано в Этапе 5+" placeholder for that slot. Every other
 * counter is backed by a real query.
 */
class AdminDashboardService
{
    /**
     * Headline counters for the admin dashboard. `premium_active` counts
     * subscriptions with an in-force premium access — `Active`, plus
     * `Cancelled` whose paid period has not ended yet (cancelling stops
     * the renewal, not the access), via `Subscription::scopeInForce`.
     *
     * @return array{
     *     users_total: int,
     *     admins_total: int,
     *     users_blocked: int,
     *     premium_active: int,
     *     payments_month: int,
     *     courses_published: int,
     * }
     */
    public function counters(): array
    {
        return [
            'users_total' => User::count(),
            'admins_total' => User::role(UserRole::Admin->value)->count(),
            'users_blocked' => User::query()->where('is_blocked', true)->count(),
            'premium_active' => Subscription::query()->inForce()->count(),
            // Successful payments of the current calendar month only:
            // failed/refunded attempts and older payments do not count.
            'payments_month' => Payment::query()
                ->where('status', PaymentStatus::Succeeded->value)
                ->where('created_at', '>=', now()->startOfMonth())
                ->count(),
            'courses_published' => 0,
        ];
    }
}
