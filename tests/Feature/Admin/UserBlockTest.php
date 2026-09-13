<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_admin_can_block_user_and_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $this->assertFalse((bool) $user->is_blocked);

        $response = $this->actingAs($admin)->post(route('admin.users.block', $user));

        $response->assertRedirect(route('admin.users.show', $user));
        $this->assertTrue((bool) $user->fresh()->is_blocked);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::UserBlocked->value,
            'admin_id' => $admin->id,
            'subject_id' => $user->id,
        ]);
    }

    public function test_admin_can_unblock_user_and_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->blocked()->create();
        $this->assertTrue((bool) $user->is_blocked);

        $response = $this->actingAs($admin)->post(route('admin.users.unblock', $user));

        $response->assertRedirect(route('admin.users.show', $user));
        $this->assertFalse((bool) $user->fresh()->is_blocked);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::UserUnblocked->value,
            'admin_id' => $admin->id,
            'subject_id' => $user->id,
        ]);
    }

    public function test_admin_cannot_block_self(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.users.block', $admin));

        $response->assertForbidden();
        $this->assertFalse((bool) $admin->fresh()->is_blocked);
        $this->assertDatabaseMissing('admin_audit_logs', [
            'admin_id' => $admin->id,
            'subject_id' => $admin->id,
        ]);
    }
}
