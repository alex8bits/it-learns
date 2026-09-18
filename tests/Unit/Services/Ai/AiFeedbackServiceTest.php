<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Enums\AiTokenUsageAction;
use App\Models\AiTokenUsage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiFeedbackService;
use App\Services\Ai\AiLimitExceededException;
use App\Services\Ai\Dto\FeedbackInput;
use App\Services\Ai\LlmClient;
use App\Services\Ai\LlmResponse;
use App\Services\Ai\PromptKeys;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AiFeedbackServiceTest extends TestCase
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

    public function test_returns_content_and_records_usage(): void
    {
        $user = User::factory()->create();
        $this->mockClientReturning(content: 'Ошибка: фильтр применён после LIMIT.', tokensUsed: 42);

        $feedback = app(AiFeedbackService::class)->generateFeedback(
            new FeedbackInput(
                taskText: 'Выведите топ-3 клиентов по выручке',
                expectedResult: '3 строки, отсортированные по убыванию',
                submittedSolution: 'SELECT name FROM clients LIMIT 3',
                errorMessage: 'Expected 3 rows, got 10',
            ),
            $user,
        );

        $this->assertSame('Ошибка: фильтр применён после LIMIT.', $feedback);

        // No settings row and no course prompt: the system prompt is empty.
        $this->assertSame('', $this->capturedSystem);
        $userMessage = $this->capturedUserMessage;
        $this->assertStringContainsString('Выведите топ-3 клиентов по выручке', $userMessage);
        $this->assertStringContainsString('3 строки, отсортированные по убыванию', $userMessage);
        $this->assertStringContainsString('SELECT name FROM clients LIMIT 3', $userMessage);
        $this->assertStringContainsString('Expected 3 rows, got 10', $userMessage);

        $this->assertDatabaseHas('ai_token_usages', [
            'user_id' => $user->id,
            'action' => AiTokenUsageAction::Feedback->value,
            'tokens' => 42,
            'model' => 'dummy',
        ]);
    }

    public function test_usage_model_follows_configured_model(): void
    {
        config(['ai.model' => 'gpt-4o-mini']);
        $user = User::factory()->create();
        $this->mockClientReturning(content: 'feedback', tokensUsed: 5);

        app(AiFeedbackService::class)->generateFeedback(
            new FeedbackInput('task', 'expected', 'solution', 'error'),
            $user,
        );

        $this->assertDatabaseHas('ai_token_usages', [
            'user_id' => $user->id,
            'model' => 'gpt-4o-mini',
        ]);
    }

    public function test_system_prompt_joins_global_and_course_parts(): void
    {
        Setting::factory()->create([
            'key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'value' => 'Global prompt text',
        ]);
        $this->mockClientReturning(content: 'feedback', tokensUsed: 1);

        app(AiFeedbackService::class)->generateFeedback(
            new FeedbackInput('task', 'expected', 'solution', 'error', coursePrompt: 'Course prompt text'),
            User::factory()->create(),
        );

        $this->assertSame("Global prompt text\n\nCourse prompt text", $this->capturedSystem);
    }

    public function test_user_limit_exhausted_blocks_the_llm_call(): void
    {
        config(['ai.token_limit_per_user_per_day' => 100]);
        $user = User::factory()->create();
        AiTokenUsage::factory()->create([
            'user_id' => $user->id,
            'tokens' => 100,
        ]);

        $this->mockClientNeverCalled();

        try {
            app(AiFeedbackService::class)->generateFeedback(
                new FeedbackInput('task', 'expected', 'solution', 'error'),
                $user,
            );
            $this->fail('AiLimitExceededException was not thrown.');
        } catch (AiLimitExceededException $e) {
            $this->assertFalse($e->isGlobalLimit);
        }

        // Only the pre-seeded spend exists — nothing was recorded by the service.
        $this->assertDatabaseCount('ai_token_usages', 1);
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

        // A fresh user with an intact personal budget must still be blocked.
        try {
            app(AiFeedbackService::class)->generateFeedback(
                new FeedbackInput('task', 'expected', 'solution', 'error'),
                User::factory()->create(),
            );
            $this->fail('AiLimitExceededException was not thrown.');
        } catch (AiLimitExceededException $e) {
            $this->assertTrue($e->isGlobalLimit);
        }

        $this->assertDatabaseCount('ai_token_usages', 1);
    }

    public function test_client_exception_propagates_without_usage_recording(): void
    {
        $user = User::factory()->create();
        $client = Mockery::mock(LlmClient::class);
        $client->shouldReceive('complete')
            ->once()
            ->andThrow(new RuntimeException('boom'));
        $this->instance(LlmClient::class, $client);

        try {
            app(AiFeedbackService::class)->generateFeedback(
                new FeedbackInput('task', 'expected', 'solution', 'error'),
                $user,
            );
            $this->fail('Expected RuntimeException to bubble out of the service.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertDatabaseCount('ai_token_usages', 0);
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
     * blocks the call before it, the SubscriptionServiceTest pattern).
     */
    private function mockClientNeverCalled(): void
    {
        $client = Mockery::mock(LlmClient::class);
        $client->shouldReceive('complete')->never();
        $this->instance(LlmClient::class, $client);
    }
}
