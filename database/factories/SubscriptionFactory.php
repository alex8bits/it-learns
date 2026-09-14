<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTier;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
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
            'tier' => SubscriptionTier::Premium,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'cancelled_at' => null,
            'external_id' => null,
            'provider' => 'dummy',
        ];
    }

    /**
     * Active subscription (the default state, spelled out for readability).
     */
    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'cancelled_at' => null,
        ]);
    }

    /**
     * Expired subscription: the period has already ended.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriptionStatus::Expired,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);
    }

    /**
     * Cancelled subscription: ended early by the user, `cancelled_at` set.
     */
    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Pending subscription: awaiting payment, so no period dates yet, but
     * the external id is already assigned by the payment provider.
     */
    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriptionStatus::Pending,
            'starts_at' => null,
            'ends_at' => null,
            'external_id' => (string) Str::uuid(),
        ]);
    }
}
