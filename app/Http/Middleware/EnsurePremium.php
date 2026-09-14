<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Subscription;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EnsurePremium
{
    /**
     * Gate the request through `SubscriptionPolicy@accessPremium` (rule №7:
     * authorization via Gate only — never a manual `is_premium`/role check).
     * Users with an in-force subscription continue; everyone else is
     * redirected to the pricing page (web) or aborted with 403 (JSON).
     *
     * No routes use this middleware in Stage 3 — it is the infrastructure
     * the AI routes will hang on in Stage 4.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Gate::allows('accessPremium', Subscription::class)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Доступно только с премиум-подпиской.');
        }

        return redirect()
            ->route('pricing')
            ->with('status', 'Эта возможность доступна только с премиум-подпиской.');
    }
}
