<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\AiPromptVersion;
use App\Models\User;

/**
 * Admin-only access to AI prompt versions (Stage 4). A separate
 * per-course prompt policy is not needed (Stage 4 plan, item 8):
 * course-scoped keys resolve to the same `AiPromptVersion` model.
 */
class AiPromptVersionPolicy
{
    /**
     * Admin can browse prompt versions (editor + history pages).
     */
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Admin can view any single prompt version.
     */
    public function view(User $user, AiPromptVersion $version): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Admin can create new versions and roll back existing ones. The
     * model argument is nullable because the global-prompt editor
     * authorizes against the class name (`authorize('update',
     * AiPromptVersion::class)`): Gate strips the class-string argument
     * before invoking the policy, so the method runs with the user only.
     */
    public function update(User $user, ?AiPromptVersion $version = null): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Admin can run the prompt playground (a live LlmClient test call).
     * Nullable model — the controller authorizes against the class name.
     */
    public function playground(User $user, ?AiPromptVersion $version = null): bool
    {
        return $this->isAdmin($user);
    }

    /*
     * Intentionally no create/delete methods: prompt versions are
     * append-only, writes flow exclusively through
     * PromptVersionService::createNewVersion/rollbackTo.
     */

    private function isAdmin(User $user): bool
    {
        return $user->hasRole(UserRole::Admin->value);
    }
}
