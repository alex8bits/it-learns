<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserRoleChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_admin_can_change_user_role_and_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $user->assignRole(UserRole::User->value);
        $this->assertTrue($user->hasRole(UserRole::User->value));

        $response = $this->actingAs($admin)->patch(
            route('admin.users.update', $user),
            ['role' => UserRole::Admin->value],
        );

        $response->assertRedirect(route('admin.users.show', $user));
        $this->assertTrue($user->fresh()->hasRole(UserRole::Admin->value));

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::UserRoleChanged->value,
            'admin_id' => $admin->id,
            'subject_id' => $user->id,
        ]);
        // Проверяем meta через выборку (JSON-каст не работает в assertDatabaseHas)
        $log = AdminAuditLog::where('subject_id', $user->id)->latest('created_at')->first();
        $this->assertNotNull($log);
        $meta = $log->meta;
        $this->assertIsArray($meta);
        $this->assertSame([UserRole::User->value], $meta['old']);
        $this->assertSame(UserRole::Admin->value, $meta['new']);
    }

    public function test_admin_cannot_change_own_role(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->patch(
            route('admin.users.update', $admin),
            ['role' => UserRole::User->value],
        );

        $response->assertForbidden();
        // Audit НЕ создаётся (Policy блокирует до Action'а)
        $this->assertDatabaseMissing('admin_audit_logs', [
            'admin_id' => $admin->id,
            'subject_id' => $admin->id,
        ]);
    }

    public function test_user_cannot_change_any_role(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $response = $this->actingAs($user1)->patch(
            route('admin.users.update', $user2),
            ['role' => UserRole::Admin->value],
        );

        $response->assertForbidden();
    }

    public function test_invalid_role_value_returns_422(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        // Accept: application/json forces Laravel's exception handler to render the
        // validation failure as a 422 JSON response (instead of the default
        // 302 redirect with flashed errors that web form posts get). The testing
        // client sets a default Accept of `text/html,...,*/*;q=0.8` which trips
        // the `acceptsAnyContentType()` check, so X-Requested-With alone is not
        // enough — `wantsJson()` is the reliable lever. With JSON, the errors
        // are in the response body, not the session.
        $response = $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->patch(
                route('admin.users.update', $user),
                ['role' => 'NotAValidRole'],
            );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('role');
    }
}
