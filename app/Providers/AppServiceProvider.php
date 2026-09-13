<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Admin\AdminAuditLogger;
use App\Services\Admin\AdminDashboardService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AdminAuditLogger::class);
        $this->app->singleton(AdminDashboardService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        $this->configureRateLimiters();

        $this->attachAuthRouteThrottles();
    }

    /**
     * Configure the rate limiters for the application.
     *
     * The `login` limiter is intentionally not defined here: the effective
     * definition (5/min) lives in FortifyServiceProvider, and a duplicate
     * in this provider would be dead code — FortifyServiceProvider boots
     * later and overwrites it.
     */
    private function configureRateLimiters(): void
    {
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('forgot-password', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }

    /**
     * Attach rate-limiter middleware to Fortify's auth routes.
     *
     * Fortify v1.x only auto-applies `throttle:login` from `config('fortify.limiters')`
     * to POST /login. To honour the same contract for `register` and `forgot-password`
     * we attach the named throttles after Fortify has registered its routes. This
     * keeps the wiring inside AppServiceProvider (no edits to bootstrap/app.php or
     * routes/*) and survives route caching because the middleware is part of the
     * route action array before the cache is written.
     */
    private function attachAuthRouteThrottles(): void
    {
        $this->app->booted(function () {
            /** @var Router $router */
            $router = $this->app->make('router');
            $router->getRoutes()->refreshNameLookups();

            $throttleByRoute = [
                'register.store' => 'throttle:register',
                'password.email' => 'throttle:forgot-password',
            ];

            foreach ($throttleByRoute as $routeName => $middleware) {
                $route = $router->getRoutes()->getByName($routeName);
                if ($route !== null && ! in_array($middleware, $route->middleware(), true)) {
                    $route->middleware($middleware);
                }
            }
        });
    }
}
