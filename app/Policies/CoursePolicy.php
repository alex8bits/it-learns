<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Stub Policy for the not-yet-existing `Course` model.
 *
 * Stage 2 ships a placeholder `CourseController@index`/`show` page
 * without any CRUD. Every ability here returns `false` so that no
 * guest, regular user, or even admin can perform course actions
 * until Stage 5+ fills the Policy in.
 *
 * The `?User` parameter accepts guests without a TypeError, and
 * `mixed $course` avoids referencing a model class that does not
 * exist yet.
 */
class CoursePolicy
{
    public function viewAny(?User $user): bool
    {
        return false;
    }

    public function view(?User $user, mixed $course): bool
    {
        return false;
    }

    public function create(?User $user): bool
    {
        return false;
    }

    public function update(?User $user, mixed $course): bool
    {
        return false;
    }

    public function delete(?User $user, mixed $course): bool
    {
        return false;
    }
}
