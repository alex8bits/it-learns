<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * `SubscriptionService` is injected through the constructor: the
     * routing pipeline passes only `($request, $next)` into `handle()`
     * (extra middleware parameters come from the middleware string), so
     * constructor injection is the supported DI point for middleware.
     */
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * This middleware is appended to the `web` group (bootstrap/app.php),
     * i.e. after StartSession — that placement is what makes the eagerly
     * resolved `errors` from `parent::share()` see the flashed validation
     * errors. A global-stack placement instead computes it before the
     * session starts and every page silently renders with empty errors.
     *
     * `status` and `auth` stay lazy closures: route-level `auth` middleware
     * runs after the group middleware, so `$request->user()` is not reliably
     * populated when `share()` itself executes — closures are evaluated by
     * the props resolver at render time instead, when the user resolver is
     * attached — an eagerly evaluated `auth.user` would serialize
     * `{"user": null}` to pages with route auth.
     *
     * `is_premium` is computed eagerly inside the `auth` closure, not as a
     * nested closure: the laziness of `auth` alone guarantees the
     * subscription query runs only for authenticated users and only at
     * render time (guest requests never hit SubscriptionService). Note that
     * this version of inertia-laravel would resolve nested closures too
     * (`PropsResolver::resolveProps()` recurses into arrays returned by
     * closures and passes every child through `resolveCallable`), but a
     * single level of laziness keeps the shared shape independent of that
     * resolver implementation detail.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'status' => fn () => $request->session()->get('status'),
            'auth' => fn () => [
                'user' => ($user = $request->user()) ? array_merge(
                    $user->only(['id', 'name', 'email']),
                    [
                        'roles' => $user->getRoleNames()->all(),
                        'is_blocked' => (bool) $user->is_blocked,
                        'is_premium' => $this->subscriptions->isActive($user),
                    ],
                ) : null,
            ],
        ];
    }
}
