<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    /**
     * Admin can browse the payments list.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::Admin->value);
    }

    /**
     * Admin can view any single payment.
     */
    public function view(User $user, Payment $payment): bool
    {
        return $user->hasRole(UserRole::Admin->value);
    }

    /*
     * Intentionally no create/update/delete methods. Stage 3 admin
     * payment operations are read-only (manual refunds and other
     * mutations land in Stage 3.1 with their own audit-logged Actions);
     * Laravel's Gate denies these abilities by default, so any
     * accidental authorize('create', $payment) call throws
     * AuthorizationException instead of silently allowing it.
     */
}
