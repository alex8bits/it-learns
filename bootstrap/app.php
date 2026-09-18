<?php

declare(strict_types=1);

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsurePremium;
use App\Http\Middleware\HandleInertiaRequests;
use App\Services\Ai\AiLimitExceededException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')->group(base_path('routes/auth.php'));
            Route::middleware(['web', 'auth', 'role:Admin'])
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Web group (after StartSession), not the global stack: the
        // Inertia base middleware resolves the shared `errors` prop
        // eagerly inside share(), so a global-stack placement computes
        // it before the session starts and every page silently renders
        // with empty validation errors.
        $middleware->web(append: [HandleInertiaRequests::class])
            ->validateCsrfTokens(except: ['subscription/webhook'])
            ->alias([
                // Overrides the framework default 'auth' alias: guests on
                // full-page auth routes get a 302 to /login (Stage 7, design
                // A1a) instead of a bare 401; Inertia XHR keeps 401 JSON.
                'auth' => Authenticate::class,
                'role' => RoleMiddleware::class,
                'permission' => PermissionMiddleware::class,
                'ensurepremium' => EnsurePremium::class,
            ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Rule #16 / concept.md §2.3: an exhausted daily AI token budget
        // fails loud — HTTP 429, no retries. The guard throws before any
        // provider call, so a refused request spends no tokens. The
        // Inertia (non-JSON) branch follows the user-zone UX: redirect
        // back with the error bag `ai` shown next to the AI buttons.
        $exceptions->render(function (AiLimitExceededException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'is_global_limit' => $e->isGlobalLimit,
                ], 429, ['Retry-After' => 60]);
            }

            return redirect()->back()->withErrors(['ai' => $e->getMessage()]);
        });
    })->create();
