<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subscription_id' => Subscription::factory(),
            'amount' => 99900,
            'currency' => 'RUB',
            'status' => PaymentStatus::Succeeded,
            'external_id' => (string) Str::uuid(),
            'provider' => 'dummy',
            'payload' => null,
        ];
    }

    /**
     * Succeeded payment (the default state, spelled out for readability).
     */
    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Succeeded,
        ]);
    }

    /**
     * Failed payment attempt.
     */
    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Failed,
        ]);
    }

    /**
     * Refunded payment.
     */
    public function refunded(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Refunded,
        ]);
    }

    /**
     * Payment awaiting confirmation from the provider.
     */
    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Pending,
        ]);
    }
}
