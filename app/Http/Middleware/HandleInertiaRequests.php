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
     * Flash `status` (e.g. from Fortify's forgot-password /
     * reset-password redirects) is resolved lazily, so plain GETs without
     * flash data do not touch the session. `is_premium` is likewise lazy
     * and only evaluated for authenticated users, so guest requests never
     * hit the database for it.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'status' => fn () => $request->session()->get('status'),
            'auth' => [
                'user' => $user ? array_merge(
                    $user->only(['id', 'name', 'email']),
                    [
                        'roles' => $user->getRoleNames()->all(),
                        'is_blocked' => (bool) $user->is_blocked,
                        'is_premium' => fn () => $this->subscriptions->isActive($user),
                    ],
                ) : null,
            ],
        ];
    }
}
