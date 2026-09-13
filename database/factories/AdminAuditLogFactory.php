<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminAuditLog>
 */
class AdminAuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admin_id' => User::factory()->admin(),
            'action' => AdminAuditAction::UserRoleChanged,
            'subject_type' => (new User)->getMorphClass(),
            'subject_id' => User::factory(),
            'meta' => [
                'old' => [UserRole::User->value],
                'new' => UserRole::Admin->value,
            ],
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }

    /**
     * Indicate the audited action is a user block. `BlockUser` records no meta.
     */
    public function userBlocked(): static
    {
        return $this->state(fn (): array => [
            'action' => AdminAuditAction::UserBlocked,
            'meta' => [],
        ]);
    }

    /**
     * Indicate the audited action is a user unblock. `UnblockUser` records no meta.
     */
    public function userUnblocked(): static
    {
        return $this->state(fn (): array => [
            'action' => AdminAuditAction::UserUnblocked,
            'meta' => [],
        ]);
    }
}
