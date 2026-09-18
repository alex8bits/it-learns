<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\AdjustUserLlmLimit;
use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Models\UserLlmLimit;
use App\Services\Admin\AdminAuditLogger;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdjustUserLlmLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_creates_limit_row_when_missing_and_audits(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        $this->actingAs($admin);
        $this->assertDatabaseMissing('user_llm_limits', ['user_id' => $target->id]);

        app(AdjustUserLlmLimit::class)->execute($target, 500, $admin);

        $this->assertDatabaseHas('user_llm_limits', [
            'user_id' => $target->id,
            'extra_tokens' => 500,
        ]);

        $log = AdminAuditLog::query()->where('subject_id', $target->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::UserLlmLimitAdjusted, $log->action);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame((new User)->getMorphClass(), $log->subject_type);
        $this->assertSame(['old_extra' => 0, 'new_extra' => 500], $log->meta);
    }

    public function test_updates_existing_limit_row_and_audits_old_value(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        UserLlmLimit::factory()->for($target)->withExtra(200)->create();

        app(AdjustUserLlmLimit::class)->execute($target, 750, $admin);

        $this->assertDatabaseHas('user_llm_limits', [
            'user_id' => $target->id,
            'extra_tokens' => 750,
        ]);
        $this->assertSame(1, UserLlmLimit::query()->where('user_id', $target->id)->count());

        $log = AdminAuditLog::query()->where('subject_id', $target->id)->firstOrFail();
        $this->assertSame(['old_extra' => 200, 'new_extra' => 750], $log->meta);
    }

    public function test_audit_subject_is_the_target_user(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        app(AdjustUserLlmLimit::class)->execute($target, 100, $admin);

        $log = AdminAuditLog::query()->where('subject_id', $target->id)->firstOrFail();
        $this->assertSame($target->id, $log->subject_id);
        $this->assertSame((new User)->getMorphClass(), $log->subject_type);
        $this->assertInstanceOf(User::class, $log->subject);
        $this->assertSame($target->id, $log->subject->getKey());
    }

    public function test_transaction_rollback_on_audit_failure(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $mock = Mockery::mock(AdminAuditLogger::class);
        $mock->shouldReceive('log')
            ->once()
            ->andThrow(new RuntimeException('boom'));
        $this->instance(AdminAuditLogger::class, $mock);

        try {
            app(AdjustUserLlmLimit::class)->execute($target, 500, $admin);
            $this->fail('Expected RuntimeException to bubble out of the action.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertDatabaseMissing('user_llm_limits', ['user_id' => $target->id]);
        $this->assertDatabaseMissing('admin_audit_logs', ['subject_id' => $target->id]);
    }
}
