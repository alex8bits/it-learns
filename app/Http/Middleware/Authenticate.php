<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

/**
 * Platform-wide change (Stage 7, design A1a): an unauthenticated
 * full-page visit is redirected to the login page instead of the
 * framework's bare 401. Inertia XHR requests (JSON Accept) keep the
 * previous behaviour — a 401 JSON response — because the parent's
 * `unauthenticated()` only consults `redirectTo()` for non-JSON
 * requests (`shouldRenderJsonWhen` in bootstrap/app.php governs the
 * exception rendering itself).
 */
class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not
     * authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        return route('login');
    }
}
