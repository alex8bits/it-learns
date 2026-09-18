<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Admin\AdminAuditLogger;
use App\Services\Admin\AdminDashboardService;
use App\Services\Ai\AiConfigValidator;
use App\Services\Ai\LlmClient;
use App\Services\Payments\PaymentGateway;
use App\Services\Practice\Docker\CliDockerClient;
use App\Services\Practice\Docker\DockerClient;
use App\Services\Practice\PracticeEnvironmentManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AdminAuditLogger::class);
        $this->app->singleton(AdminDashboardService::class);

        $this->registerPaymentGateway();
        $this->registerLlmClient();
        $this->registerPracticeEnvironmentManager();
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
     * Bind the payment gateway selected by PAYMENT_PROVIDER.
     *
     * Fail loud: a provider missing from the whitelist — or the
     * production gateway without its merchant credentials — must crash
     * the application on boot instead of silently resolving to nothing.
     */
    private function registerPaymentGateway(): void
    {
        /** @var string $provider */
        $provider = config('payments.provider');

        /** @var array<string, class-string<PaymentGateway>> $gateways */
        $gateways = config('payments.gateways');

        if (! isset($gateways[$provider])) {
            throw new RuntimeException("Payment gateway [{$provider}] is not whitelisted in config/payments.php");
        }

        if ($provider === 'yookassa' && ! $this->yookassaCredentialsConfigured()) {
            throw new RuntimeException('Payment gateway [yookassa] requires YOOKASSA_SHOP_ID and YOOKASSA_SECRET_KEY');
        }

        $this->app->singleton(PaymentGateway::class, $gateways[$provider]);
    }

    /**
     * Are the YooKassa merchant credentials present (non-empty)?
     * Inline check instead of a dedicated validator class — unlike the
     * AI config there is no SSRF-guard logic to carry (YAGNI).
     */
    private function yookassaCredentialsConfigured(): bool
    {
        /** @var array{shop_id?: string|null, secret_key?: string|null} $yookassa */
        $yookassa = config('payments.yookassa', []);

        foreach (['shop_id', 'secret_key'] as $key) {
            $value = $yookassa[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Bind the LLM client selected by AI_PROVIDER.
     *
     * Fail loud: AiConfigValidator checks the whitelist membership,
     * the credentials of non-dummy providers and the base_url SSRF
     * guard, so an unusable AI configuration must crash the
     * application on boot instead of silently resolving to nothing.
     */
    private function registerLlmClient(): void
    {
        /** @var array<string, mixed> $aiConfig */
        $aiConfig = config('ai');

        (new AiConfigValidator)->validate($aiConfig);

        /** @var string $provider */
        $provider = $aiConfig['provider'];

        /** @var array<string, class-string<LlmClient>> $clients */
        $clients = $aiConfig['clients'];

        $this->app->singleton(LlmClient::class, $clients[$provider]);
    }

    /**
     * Bind the practice environment manager selected by
     * PRACTICE_DRIVER.
     *
     * Fail loud: a driver missing from the whitelist — or the docker
     * driver without a complete practice.docker config — must crash
     * the application on boot instead of silently resolving to
     * nothing. Docker daemon availability is deliberately NOT probed
     * here: the daemon is caught at provision time with a clear
     * RuntimeException, so machines without Docker can still boot
     * (and run the test suite).
     */
    private function registerPracticeEnvironmentManager(): void
    {
        /** @var string $driver */
        $driver = config('practice.driver');

        /** @var array<string, class-string<PracticeEnvironmentManager>> $managers */
        $managers = config('practice.managers');

        if (! isset($managers[$driver])) {
            throw new RuntimeException("Practice environment driver [{$driver}] is not whitelisted in config/practice.php");
        }

        // The docker control-plane client behind the docker driver and
        // the Stage 10 pruner; bound unconditionally — the binding is
        // inert unless something resolves it.
        $this->app->singleton(DockerClient::class, CliDockerClient::class);

        if ($driver === 'docker' && ! $this->dockerConfigured()) {
            throw new RuntimeException('Practice driver [docker] requires a complete practice.docker config');
        }

        $this->app->singleton(PracticeEnvironmentManager::class, $managers[$driver]);
    }

    /**
     * Is the practice.docker config complete enough to run containers:
     * a non-empty binary path, a non-empty image for every runtime and
     * positive numeric limits. Inline check instead of a dedicated
     * validator class — no cross-field logic to carry (YAGNI).
     */
    private function dockerConfigured(): bool
    {
        /** @var array<string, mixed> $docker */
        $docker = config('practice.docker', []);

        $binary = $docker['binary'] ?? null;

        if (! is_string($binary) || trim($binary) === '') {
            return false;
        }

        /** @var array<string, mixed> $runtimes */
        $runtimes = $docker['runtimes'] ?? [];

        foreach (['mysql', 'postgres'] as $key) {
            $runtime = $runtimes[$key] ?? null;

            $image = is_array($runtime) ? ($runtime['image'] ?? null) : null;

            if (! is_string($image) || trim($image) === '') {
                return false;
            }
        }

        foreach (['memory_mb', 'cpus', 'pids_limit', 'timeout_seconds', 'max_result_bytes', 'provision_timeout_seconds'] as $key) {
            $value = $docker[$key] ?? null;

            if (! is_numeric($value) || (float) $value <= 0) {
                return false;
            }
        }

        return true;
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

        RateLimiter::for('subscription', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('payment-webhook', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('practice-submit', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Проверка эталонного запроса в админке исполняется в той же
        // изолированной среде, что и попытки студентов (CPU-платная
        // операция) — тот же per-user паттерн, что у practice-submit.
        RateLimiter::for('practice-check', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('theory-answer', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // LLM-стоимость: каждый запрос к ИИ тратит токены дневного бюджета,
        // поэтому премиум ИИ-роуты (Этап 8) режутся отдельными лимитёрами —
        // не исчерпавшими ни LLM-лимит, ни терпение провайдера.
        RateLimiter::for('ai-feedback', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('ai-extra-task', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        // Playground тоже тратит токены реального провайдера — тот же пер-юзер
        // лимит, что у премиум ИИ-роутов (правило №11).
        RateLimiter::for('ai-playground', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
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
