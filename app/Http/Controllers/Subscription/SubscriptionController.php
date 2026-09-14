<?php

declare(strict_types=1);

namespace App\Http\Controllers\Subscription;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\CheckoutReturnRequest;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * User-facing subscription zone (Stage 3): status page, checkout start,
 * checkout return and cancellation. Thin controller — every business
 * operation is delegated to `SubscriptionService` (rule №3), the only
 * branching is the nullable `startCheckout` contract.
 */
class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $service) {}

    /**
     * Subscription status page. `isActive`/`expiresAt` answer through the
     * service; the latest subscription row is fetched with a direct query
     * (no lazy relation load, N+1-free by construction) and passed as a
     * narrow `status`-only shape — the raw model never leaks `external_id`,
     * `provider` or `user_id` to the frontend.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $lastSubscription = $user->subscriptions()->latest('id')->first();
        // `instanceof` doubles as the null check here: the cast guarantees a
        // `SubscriptionStatus` at runtime, but Larastan cannot infer it.
        $lastStatus = $lastSubscription?->status;

        return Inertia::render('Subscription/Index', [
            'isPremium' => $this->service->isActive($user),
            'expiresAt' => $this->service->expiresAt($user)?->toIso8601String(),
            'lastSubscription' => $lastStatus instanceof SubscriptionStatus ? [
                'status' => $lastStatus->value,
            ] : null,
        ]);
    }

    /**
     * Start a premium checkout. `null` from the service means the user
     * already has an in-force subscription — send them back to the status
     * page instead of the gateway.
     */
    public function checkout(Request $request): RedirectResponse
    {
        $session = $this->service->startCheckout($request->user());

        if ($session === null) {
            return redirect()
                ->route('subscription')
                ->with('status', 'У вас уже есть действующая подписка.');
        }

        return redirect($session->url);
    }

    /**
     * Checkout return URL: activate the subscription for the session id
     * from the query string. `null` from the service (unknown session,
     * foreign session, already activated by the webhook) renders the same
     * page with `activated = false`.
     */
    public function return(CheckoutReturnRequest $request): Response
    {
        $subscription = $this->service->activateFromCheckout(
            $request->user(),
            (string) $request->validated('session'),
        );

        return Inertia::render('Subscription/CheckoutReturn', [
            'activated' => $subscription !== null,
        ]);
    }

    /**
     * Cancel the active subscription; the service decides whether there
     * was anything to cancel.
     */
    public function cancel(Request $request): RedirectResponse
    {
        $subscription = $this->service->cancel($request->user());

        return redirect()
            ->route('subscription')
            ->with('status', $subscription !== null
                ? 'Подписка отменена. Доступ сохранится до конца оплаченного периода.'
                : 'Нет активной подписки.');
    }
}
