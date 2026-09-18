<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserLlmLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_admin_adjusts_user_llm_limit(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($admin)->patch(route('admin.users.llm-limit.update', $user), [
            'extra_tokens' => 500,
        ]);

        $response->assertRedirect(route('admin.users.show', $user));
        $this->assertDatabaseHas('user_llm_limits', [
            'user_id' => $user->id,
            'extra_tokens' => 500,
        ]);

        $log = AdminAuditLog::query()->where('subject_id', $user->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::UserLlmLimitAdjusted, $log->action);
        $this->assertSame(['old_extra' => 0, 'new_extra' => 500], $log->meta);
    }

    public function test_non_admin_gets_403_on_llm_limit(): void
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::User->value);

        $this->actingAs($user)
            ->get(route('admin.users.llm-limit.edit', $user))
            ->assertForbidden();
    }
}
