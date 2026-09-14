<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Payment;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class PaymentUniqueConstraintTest extends TestCase
{
    public function test_duplicate_provider_and_external_id_is_rejected(): void
    {
        Payment::factory()->create([
            'provider' => 'dummy',
            'external_id' => 'webhook-42',
        ]);

        $this->expectException(QueryException::class);

        Payment::factory()->create([
            'provider' => 'dummy',
            'external_id' => 'webhook-42',
        ]);
    }

    public function test_same_external_id_with_different_provider_is_allowed(): void
    {
        Payment::factory()->create([
            'provider' => 'dummy',
            'external_id' => 'shared-id',
        ]);

        $payment = Payment::factory()->create([
            'provider' => 'stripe',
            'external_id' => 'shared-id',
        ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'provider' => 'stripe',
            'external_id' => 'shared-id',
        ]);
    }
}
