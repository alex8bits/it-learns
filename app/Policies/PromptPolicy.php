<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Stub Policy for the not-yet-existing `Prompt` model.
 *
 * Stage 2 ships a placeholder `PromptController@index` page without
 * any CRUD. Every ability returns `false` until Stage 4 (AI) defines
 * the real rules.
 *
 * `?User $user` accepts guests; `mixed $prompt` avoids referencing a
 * model class that does not exist yet.
 */
class PromptPolicy
{
    public function viewAny(?User $user): bool
    {
        return false;
    }

    public function view(?User $user, mixed $prompt): bool
    {
        return false;
    }

    public function create(?User $user): bool
    {
        return false;
    }

    public function update(?User $user, mixed $prompt): bool
    {
        return false;
    }

    public function delete(?User $user, mixed $prompt): bool
    {
        return false;
    }
}
