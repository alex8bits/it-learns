<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Aggregates the headline counters shown on the admin dashboard.
 *
 * Only `users_total`, `admins_total`, and `users_blocked` are backed by
 * real queries today. `premium_active`, `payments_month`, and
 * `courses_published` are pinned to `0` because their underlying models
 * and seed data land in later stages (3 and 5+); the frontend renders
 * a "Запланировано в Этапе N" placeholder for those slots.
 */
class AdminDashboardService
{
    /**
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
            'premium_active' => 0,
            'payments_month' => 0,
            'courses_published' => 0,
        ];
    }
}
