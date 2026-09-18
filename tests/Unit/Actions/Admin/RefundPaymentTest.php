<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\RefundPayment;
use App\Enums\AdminAuditAction;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\Payment;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RefundPaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    protected function tearDown(): void
    {
        // The transaction-level tracing test registers an `updating`
        // hook; drop it so it does not leak into other tests.
        Payment::flushEventListeners();

        parent::tearDown();
    }

    public function test_refunds_succeeded_payment_and_audits_with_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $payment = Payment::factory()->succeeded()->create();

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('refund')->once()->andReturn(true);
        $this->instance(PaymentGateway::class, $gateway);

        $action = app(RefundPayment::class);
        $result = $action($payment, 'Двойное списание');

        $this->assertSame(PaymentStatus::Refunded, $result->status);
        $this->assertSame($payment->id, $result->id);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Refunded->value,
        ]);

        $log = AdminAuditLog::query()->where('subject_id', $payment->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::PaymentRefunded, $log->action);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame((new Payment)->getMorphClass(), $log->subject_type);
        $this->assertInstanceOf(Payment::class, $log->subject);
        $this->assertSame([
            'provider' => $payment->provider,
            'external_id' => $payment->external_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'reason' => 'Двойное списание',
        ], $log->meta);
    }

    public function test_refund_without_reason_records_null_reason(): void
    {
        User::factory()->admin()->create();
        $payment = Payment::factory()->succeeded()->create();

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('refund')->once()->andReturn(true);
        $this->instance(PaymentGateway::class, $gateway);

        (app(RefundPayment::class))($payment);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Refunded->value,
        ]);

        $log = AdminAuditLog::query()->where('subject_id', $payment->id)->firstOrFail();
        $this->assertSame([
            'provider' => $payment->provider,
            'external_id' => $payment->external_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'reason' => null,
        ], $log->meta);
    }

    public function test_gateway_is_called_outside_the_status_update_transaction(): void
    {
        $payment = Payment::factory()->succeeded()->create();

        // The provider refund is an external HTTP call: it must happen
        // before `DB::transaction` opens, so a slow gateway never holds
        // database locks (same pinning approach as SubscriptionServiceTest).
        $trace = [];
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('refund')->once()->andReturnUsing(
            static function () use (&$trace): bool {
                $trace[] = ['gateway', DB::transactionLevel()];

                return true;
            },
        );
        $this->instance(PaymentGateway::class, $gateway);

        Payment::updating(static function () use (&$trace): void {
            $trace[] = ['update', DB::transactionLevel()];
        });

        // RefreshDatabase keeps the whole test in its own transaction, so
        // the baseline level is captured instead of hardcoded.
        $baselineLevel = DB::transactionLevel();

        try {
            (app(RefundPayment::class))($payment);
        } finally {
            Payment::flushEventListeners();
        }

        $this->assertSame(
            [['gateway', $baselineLevel], ['update', $baselineLevel + 1]],
            $trace,
        );
    }

    /**
     * @return array<string, array{0: PaymentStatus}>
     */
    public static function nonSucceededStatusProvider(): array
    {
        return [
            'pending payment' => [PaymentStatus::Pending],
            'failed payment' => [PaymentStatus::Failed],
            'already refunded payment' => [PaymentStatus::Refunded],
        ];
    }

    #[DataProvider('nonSucceededStatusProvider')]
    public function test_rejects_non_succeeded_payment_without_touching_the_database(PaymentStatus $status): void
    {
        $payment = Payment::factory()->create(['status' => $status]);

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('refund')->never();
        $this->instance(PaymentGateway::class, $gateway);

        try {
            (app(RefundPayment::class))($payment);
            $this->fail('Expected InvalidArgumentException to bubble out of the action.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                "Возврат возможен только для успешного платежа (текущий статус: «{$status->label()}»).",
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => $status->value,
        ]);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_rejects_payment_of_another_provider(): void
    {
        // A payment charged by a gateway that is no longer active cannot
        // be refunded through the current one (no real money behind it).
        $payment = Payment::factory()->succeeded()->create(['provider' => 'stripe']);

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('refund')->never();
        $this->instance(PaymentGateway::class, $gateway);

        try {
            (app(RefundPayment::class))($payment);
            $this->fail('Expected InvalidArgumentException to bubble out of the action.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Возврат возможен только для платежа активного провайдера «dummy» (платёж: «stripe»).',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Succeeded->value,
        ]);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_gateway_failure_leaves_payment_succeeded_without_audit(): void
    {
        $payment = Payment::factory()->succeeded()->create();

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('refund')->once()->andThrow(new RuntimeException('boom'));
        $this->instance(PaymentGateway::class, $gateway);

        try {
            (app(RefundPayment::class))($payment);
            $this->fail('Expected RuntimeException to bubble out of the action.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Succeeded->value,
        ]);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_transaction_rolls_back_status_when_audit_fails(): void
    {
        $payment = Payment::factory()->succeeded()->create();

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('refund')->once()->andReturn(true);
        $this->instance(PaymentGateway::class, $gateway);

        $audit = Mockery::mock(AdminAuditLogger::class);
        $audit->shouldReceive('log')
            ->once()
            ->andThrow(new RuntimeException('boom'));
        $this->instance(AdminAuditLogger::class, $audit);

        try {
            (app(RefundPayment::class))($payment);
            $this->fail('Expected RuntimeException to bubble out of the action.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        // No half-written state: the status flip and the audit row are
        // atomic, so a failed audit leaves the payment Succeeded (a safe
        // retry re-uses the gateway's deterministic idempotence key).
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Succeeded->value,
        ]);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }
}
