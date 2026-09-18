<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\User;

/**
 * Admin-only course management (Stage 6). Public browsing of
 * published courses is NOT authorized through this policy: public
 * catalog routes filter by the `published` scope, and courses that
 * are not published (draft/archived) resolve to 404 instead.
 */
class CoursePolicy
{
    /**
     * Admin can browse the admin course list.
     */
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Admin can view any course, including drafts and archived ones.
     */
    public function view(User $user, Course $course): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Admin can create courses.
     */
    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Admin can update any course.
     */
    public function update(User $user, Course $course): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Admin can delete courses.
     */
    public function delete(User $user, Course $course): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Admin can preview the course as a student sees it — including
     * unpublished content, strictly read-only (no progress writes).
     */
    public function preview(User $user, Course $course): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return $user->hasRole(UserRole::Admin->value);
    }
}
