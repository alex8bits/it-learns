<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\RunPromptPlayground;
use App\Enums\AdminAuditAction;
use App\Enums\AiTokenUsageAction;
use App\Models\AdminAuditLog;
use App\Models\AiTokenUsage;
use App\Models\Course;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiLimitExceededException;
use App\Services\Ai\LlmClient;
use App\Services\Ai\LlmResponse;
use App\Services\Ai\PromptKeys;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RunPromptPlaygroundTest extends TestCase
{
    /** Arguments captured from the mocked LlmClient. */
    private string $capturedSystem = '';

    private string $capturedUserMessage = '';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.token_limit_per_user_per_day' => 1000,
            'ai.token_limit_global_per_day' => 10000,
        ]);
    }

    protected function tearDown(): void
    {
        // The rollback test registers a throwing `creating` hook on the
        // audit log; drop it so it does not leak into other tests.
        AdminAuditLog::flushEventListeners();

        parent::tearDown();
    }

    public function test_returns_result_and_records_usage_and_audit_without_course(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        Setting::factory()->create([
            'key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'value' => 'Global prompt text',
        ]);
        $this->mockClientReturning(content: 'Ответ модели', tokensUsed: 42);

        $result = app(RunPromptPlayground::class)->execute($admin, 'Тестовое сообщение', null);

        $this->assertSame('Global prompt text', $this->capturedSystem);
        $this->assertSame('Тестовое сообщение', $this->capturedUserMessage);

        $this->assertSame([
            'content' => 'Ответ модели',
            'tokens_used' => 42,
            'model' => 'dummy',
            'system_prompt' => 'Global prompt text',
        ], $result);

        $this->assertDatabaseHas('ai_token_usages', [
            'user_id' => $admin->id,
            'action' => AiTokenUsageAction::Playground->value,
            'tokens' => 42,
            'model' => 'dummy',
        ]);

        $log = AdminAuditLog::query()
            ->where('action', AdminAuditAction::PromptPlaygroundRun->value)
            ->firstOrFail();
        $this->assertEquals($admin->id, $log->admin_id);
        $this->assertNull($log->subject_type);
        $this->assertNull($log->subject_id);
        $this->assertSame([
            'course_id' => null,
            'system_prompt_chars' => mb_strlen('Global prompt text'),
            'user_message_chars' => mb_strlen('Тестовое сообщение'),
            'tokens_used' => 42,
            'model' => 'dummy',
        ], $log->meta);
    }

    public function test_course_prompt_is_glued_and_course_is_the_audit_subject(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        Setting::factory()->create([
            'key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'value' => 'Global prompt text',
        ]);
        $course = Course::factory()->create(['ai_course_prompt' => 'Course prompt text']);
        $this->mockClientReturning(content: 'ok', tokensUsed: 7);

        $result = app(RunPromptPlayground::class)->execute($admin, 'проверка склейки', $course);

        $this->assertSame("Global prompt text\n\nCourse prompt text", $this->capturedSystem);
        $this->assertSame($this->capturedSystem, $result['system_prompt']);

        $log = AdminAuditLog::query()
            ->where('action', AdminAuditAction::PromptPlaygroundRun->value)
            ->firstOrFail();
        $this->assertSame((new Course)->getMorphClass(), $log->subject_type);
        $this->assertEquals($course->id, $log->subject_id);
        $this->assertSame([
            'course_id' => $course->id,
            'system_prompt_chars' => mb_strlen("Global prompt text\n\nCourse prompt text"),
            'user_message_chars' => mb_strlen('проверка склейки'),
            'tokens_used' => 7,
            'model' => 'dummy',
        ], $log->meta);
    }

    public function test_usage_model_follows_configured_model(): void
    {
        config(['ai.model' => 'gpt-4o-mini']);
        $admin = User::factory()->create();
        $this->mockClientReturning(content: 'ok', tokensUsed: 5);

        $result = app(RunPromptPlayground::class)->execute($admin, 'msg', null);

        $this->assertSame('gpt-4o-mini', $result['model']);
        $this->assertDatabaseHas('ai_token_usages', [
            'user_id' => $admin->id,
            'model' => 'gpt-4o-mini',
        ]);
    }

    public function test_user_limit_exhausted_blocks_the_llm_call(): void
    {
        config(['ai.token_limit_per_user_per_day' => 100]);
        $admin = User::factory()->create();
        AiTokenUsage::factory()->create([
            'user_id' => $admin->id,
            'tokens' => 100,
        ]);

        $this->mockClientNeverCalled();

        try {
            app(RunPromptPlayground::class)->execute($admin, 'msg', null);
            $this->fail('AiLimitExceededException was not thrown.');
        } catch (AiLimitExceededException $e) {
            $this->assertFalse($e->isGlobalLimit);
        }

        // Only the pre-seeded spend exists — nothing was recorded by the action.
        $this->assertDatabaseCount('ai_token_usages', 1);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_global_limit_exhausted_blocks_the_llm_call(): void
    {
        config(['ai.token_limit_global_per_day' => 50]);
        $spender = User::factory()->create();
        AiTokenUsage::factory()->create([
            'user_id' => $spender->id,
            'tokens' => 50,
        ]);

        $this->mockClientNeverCalled();

        // A fresh admin with an intact personal budget must still be blocked.
        try {
            app(RunPromptPlayground::class)->execute(User::factory()->create(), 'msg', null);
            $this->fail('AiLimitExceededException was not thrown.');
        } catch (AiLimitExceededException $e) {
            $this->assertTrue($e->isGlobalLimit);
        }

        $this->assertDatabaseCount('ai_token_usages', 1);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_client_exception_propagates_without_usage_or_audit_recording(): void
    {
        $admin = User::factory()->create();
        $client = Mockery::mock(LlmClient::class);
        $client->shouldReceive('complete')
            ->once()
            ->andThrow(new RuntimeException('boom'));
        $this->instance(LlmClient::class, $client);

        try {
            app(RunPromptPlayground::class)->execute($admin, 'msg', null);
            $this->fail('Expected RuntimeException to bubble out of the action.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertDatabaseCount('ai_token_usages', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_transaction_rolls_back_usage_when_audit_fails(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $this->mockClientReturning(content: 'ok', tokensUsed: 9);

        AdminAuditLog::creating(static function (): void {
            throw new RuntimeException('boom');
        });

        try {
            app(RunPromptPlayground::class)->execute($admin, 'msg', null);
            $this->fail('Expected RuntimeException to bubble out of the action.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        // The usage row written inside the same transaction is rolled back.
        $this->assertDatabaseCount('ai_token_usages', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    /**
     * Bind a mocked LlmClient whose complete() captures its arguments
     * and returns the given response.
     */
    private function mockClientReturning(string $content, int $tokensUsed): void
    {
        $client = Mockery::mock(LlmClient::class);
        $client->shouldReceive('complete')
            ->once()
            ->andReturnUsing(function (string $system, string $userMessage) use ($content, $tokensUsed): LlmResponse {
                $this->capturedSystem = $system;
                $this->capturedUserMessage = $userMessage;

                return new LlmResponse(content: $content, tokensUsed: $tokensUsed);
            });
        $this->instance(LlmClient::class, $client);
    }

    /**
     * Bind a mocked LlmClient that must never be reached (the guard
     * blocks the call before it, the AiFeedbackServiceTest pattern).
     */
    private function mockClientNeverCalled(): void
    {
        $client = Mockery::mock(LlmClient::class);
        $client->shouldReceive('complete')->never();
        $this->instance(LlmClient::class, $client);
    }
}
