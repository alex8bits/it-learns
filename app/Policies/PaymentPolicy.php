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

    /**
     * Admin can refund a payment. The business guards (status must be
     * `Succeeded`, provider must match the active gateway) live in the
     * `RefundPayment` action, not here — the policy answers "who may
     * attempt a refund", the action answers "is this payment refundable".
     */
    public function refund(User $user, Payment $payment): bool
    {
        return $user->hasRole(UserRole::Admin->value);
    }

    /*
     * Intentionally no create/update/delete methods. The only payment
     * mutation is `refund` (the audit-logged `RefundPayment` action,
     * Stage 9); manually marking a payment as paid stays out of scope.
     * Laravel's Gate denies these abilities by default, so any accidental
     * authorize('create', $payment) call throws AuthorizationException
     * instead of silently allowing it.
     */
}
