<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\RefundPayment;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminPaymentIndexRequest;
use App\Http\Requests\Admin\AdminRefundPaymentRequest;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class PaymentController extends Controller
{
    public function __construct(private RefundPayment $refunds) {}

    /**
     * Paginated payment list with optional filters: `status`, payer
     * `email`, and a `date_from` / `date_to` range over `created_at`
     * (both bounds include the whole day, see `Payment::scopeFiltered`).
     * The `user` and `subscription` relations are eager-loaded to avoid
     * an N+1 when rendering the table columns.
     *
     * `latest('id')` is used instead of `latest('created_at')`: both
     * order chronologically for this table, and the `id` ordering keeps
     * a stable total order for rows created within the same second.
     */
    public function index(AdminPaymentIndexRequest $request): Response
    {
        $this->authorize('viewAny', Payment::class);

        $filters = $request->validated();

        $payments = Payment::query()
            ->filtered(
                status: isset($filters['status']) ? PaymentStatus::from((string) $filters['status']) : null,
                email: isset($filters['email']) ? (string) $filters['email'] : null,
                dateFrom: isset($filters['date_from']) ? (string) $filters['date_from'] : null,
                dateTo: isset($filters['date_to']) ? (string) $filters['date_to'] : null,
            )
            ->with(['user', 'subscription'])
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Payments/Index', [
            'payments' => $payments,
            'filters' => $filters,
            'statuses' => PaymentStatus::options(),
        ]);
    }

    /**
     * Single payment card: all fields including the raw provider
     * `payload`, the payer, and the related subscription (when the
     * payment is tied to one). The one-shot `error` flash carries the
     * human-readable refund refusal from the POST route below (the
     * LessonController `ai_feedback` pattern) — `status` is shared
     * globally by HandleInertiaRequests and needs no prop.
     */
    public function show(Request $request, Payment $payment): Response
    {
        $this->authorize('view', $payment);
        $payment->loadMissing(['user', 'subscription']);

        return Inertia::render('Admin/Payments/Show', [
            'payment' => $payment,
            'statuses' => PaymentStatus::options(),
            'subscriptionStatuses' => SubscriptionStatus::options(),
            'tiers' => SubscriptionTier::options(),
            'error' => $request->session()->get('error'),
        ]);
    }

    /**
     * POST /admin/payments/{payment}/refund (Stage 9): the FormRequest
     * validates the optional reason, the policy answers "who may attempt
     * a refund", the `RefundPayment` action moves the money and writes
     * the `PaymentRefunded` audit entry. Expected refusals — a
     * not-`Succeeded` payment, a foreign provider, the provider
     * rejecting the refund — come back as a human-readable flash
     * instead of a 500 (minimal local handling: the project has no
     * unified admin try/catch pattern).
     */
    public function refund(AdminRefundPaymentRequest $request, Payment $payment): RedirectResponse
    {
        $this->authorize('refund', $payment);

        try {
            ($this->refunds)($payment, $request->validated('reason'));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Платёж возвращён.');
    }
}
