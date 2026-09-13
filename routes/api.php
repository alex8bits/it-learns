<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * API surface. Laravel mounts this file under the `/api` prefix with the
 * `api` middleware group (see `withRouting(api: ...)` in bootstrap/app.php).
 *
 * Rule #10 (AGENTS.md): API versioning from day one — every endpoint lives
 * inside a `v{N}/` group with the `api.v{N}.` name prefix. Domain endpoints
 * arrive in stages 3+ and must be added inside their own version group,
 * never at the root of this file.
 */

/*
 * Rate limiting decision (rule #11): `throttle` is deliberately NOT attached
 * here. /health is a cheap stateless diagnostic endpoint with no side
 * effects; throttling targets critical endpoints (login, registration,
 * password reset, payments, public forms).
 */
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', HealthController::class)->name('health');
});
