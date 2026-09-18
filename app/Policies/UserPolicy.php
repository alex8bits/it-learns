<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    /**
     * Admin can browse the full user list.
     */
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Admin can view any user's profile card.
     */
    public function view(User $user, User $target): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Admin can edit any user except themselves.
     */
    public function update(User $user, User $target): bool
    {
        return $this->isAdmin($user) && $user->id !== $target->id;
    }

    /**
     * Admin can delete any user except themselves.
     * Real user deletion is out of scope for Stage 2.
     */
    public function delete(User $user, User $target): bool
    {
        return $this->isAdmin($user) && $user->id !== $target->id;
    }

    /**
     * Only an admin can change roles, and never on themselves.
     */
    public function changeRole(User $user, User $target): bool
    {
        return $this->isAdmin($user) && $user->id !== $target->id;
    }

    /**
     * Only an admin can block users, and never themselves.
     */
    public function block(User $user, User $target): bool
    {
        return $this->isAdmin($user) && $user->id !== $target->id;
    }

    /**
     * Only an admin can unblock users, and never themselves.
     */
    public function unblock(User $user, User $target): bool
    {
        return $this->isAdmin($user) && $user->id !== $target->id;
    }

    /**
     * Only an admin can adjust a user's LLM token budget, and never
     * their own (self-granted tokens would bypass the daily quota).
     */
    public function updateLlmLimit(User $user, User $target): bool
    {
        return $this->isAdmin($user) && $user->id !== $target->id;
    }

    private function isAdmin(User $user): bool
    {
        return $user->hasRole(UserRole::Admin->value);
    }
}
