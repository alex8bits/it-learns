<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;

class SubscriptionPolicy
{
    public function __construct(private SubscriptionService $service) {}

    /**
     * Does the user have premium access right now? Delegates entirely to
     * SubscriptionService::isActive — the single source of truth for the
     * in-force paid period (design decision A1: no `is_premium` column
     * anywhere, `Active` or `Cancelled` with a future `ends_at` counts).
     *
     * Called as `Gate::allows('accessPremium', Subscription::class)` or
     * `$this->authorize('accessPremium', Subscription::class)`; consumed by
     * the `ensurepremium` middleware and the AI routes (Stage 4).
     */
    public function accessPremium(User $user): bool
    {
        return $this->service->isActive($user);
    }
}
