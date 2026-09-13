<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\UnblockUser;
use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UnblockUserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_sets_is_blocked_false_and_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->blocked()->create();
        $this->actingAs($admin);

        $this->assertTrue((bool) $target->fresh()->is_blocked);

        app(UnblockUser::class)->execute($target, $admin);

        $this->assertFalse((bool) $target->fresh()->is_blocked);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::UserUnblocked->value,
            'admin_id' => $admin->id,
            'subject_type' => (new User)->getMorphClass(),
            'subject_id' => $target->id,
        ]);
    }

    public function test_transaction_rollback_on_audit_failure(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->blocked()->create();
        $this->actingAs($admin);

        $mock = Mockery::mock(AdminAuditLogger::class);
        $mock->shouldReceive('log')
            ->once()
            ->andThrow(new RuntimeException('boom'));
        $this->instance(AdminAuditLogger::class, $mock);

        try {
            app(UnblockUser::class)->execute($target, $admin);
            $this->fail('Expected RuntimeException to bubble out of the action.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertDatabaseMissing('admin_audit_logs', [
            'subject_id' => $target->id,
        ]);
        $this->assertTrue((bool) $target->fresh()->is_blocked);
    }
}
