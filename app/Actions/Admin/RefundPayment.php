<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Admin\AdminAuditLogger;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RefundPayment
{
    public function __construct(
        private PaymentGateway $gateway,
        private AdminAuditLogger $audit,
    ) {}

    /**
     * Refund a succeeded payment through the active payment gateway and
     * record the `PaymentRefunded` audit entry.
     *
     * The gateway call (external HTTP) happens *outside* the transaction —
     * same ordering as `SubscriptionService::startCheckout` (persist/plan,
     * then call, then record): a database failure after a successful
     * provider refund is safe to retry because the gateway uses a
     * deterministic idempotence key. The status flip and the audit row are
     * written atomically inside one transaction (rule №17: no admin
     * mutation without an audit entry).
     *
     * Only `Succeeded` payments of the currently active provider are
     * refundable: money moves only through the gateway that charged it.
     * Refunding does not revoke the subscription — premium access lasts
     * until `ends_at` (design decision, Stage 9 non-goal).
     *
     * @throws InvalidArgumentException When the payment is not `Succeeded`
     *                                  or belongs to another provider.
     */
    public function __invoke(Payment $payment, ?string $reason = null): Payment
    {
        /** @var PaymentStatus $status */
        $status = $payment->status;

        if ($status !== PaymentStatus::Succeeded) {
            throw new InvalidArgumentException(
                "Возврат возможен только для успешного платежа (текущий статус: «{$status->label()}»).",
            );
        }

        /** @var string $activeProvider */
        $activeProvider = config('payments.provider');

        if ($payment->provider !== $activeProvider) {
            throw new InvalidArgumentException(
                "Возврат возможен только для платежа активного провайдера «{$activeProvider}» (платёж: «{$payment->provider}»).",
            );
        }

        $this->gateway->refund($payment);

        return DB::transaction(function () use ($payment, $reason, $activeProvider): Payment {
            $payment->update(['status' => PaymentStatus::Refunded]);

            $this->audit->log(
                AdminAuditAction::PaymentRefunded,
                $payment,
                [
                    'provider' => $activeProvider,
                    'external_id' => $payment->external_id,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'reason' => $reason,
                ],
            );

            return $payment;
        });
    }
}
