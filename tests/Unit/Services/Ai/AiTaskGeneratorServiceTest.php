<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Enums\AiTokenUsageAction;
use App\Models\AiTokenUsage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiLimitExceededException;
use App\Services\Ai\AiTaskGeneratorService;
use App\Services\Ai\Dto\ExtraTaskInput;
use App\Services\Ai\LlmClient;
use App\Services\Ai\LlmResponse;
use App\Services\Ai\PromptKeys;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AiTaskGeneratorServiceTest extends TestCase
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

    public function test_parses_marked_response_and_records_usage(): void
    {
        $user = User::factory()->create();
        $this->mockClientReturning(
            content: "=== TASK ===\nВыведите топ-5 клиентов.\n=== EXPECTED ===\nSELECT name FROM clients ORDER BY revenue DESC LIMIT 5",
            tokensUsed: 7,
        );

        $task = app(AiTaskGeneratorService::class)->generateExtraTask(
            new ExtraTaskInput(
                taskText: 'Выведите топ-3 клиента.',
                expectedResult: 'SELECT ... LIMIT 3',
            ),
            $user,
        );

        $this->assertSame('Выведите топ-5 клиентов.', $task->taskText);
        $this->assertSame('SELECT name FROM clients ORDER BY revenue DESC LIMIT 5', $task->expectedResult);

        $this->assertSame('', $this->capturedSystem);
        $this->assertStringContainsString('Выведите топ-3 клиента.', $this->capturedUserMessage);
        $this->assertStringContainsString('SELECT ... LIMIT 3', $this->capturedUserMessage);

        $this->assertDatabaseHas('ai_token_usages', [
            'user_id' => $user->id,
            'action' => AiTokenUsageAction::ExtraTask->value,
            'tokens' => 7,
            'model' => 'dummy',
        ]);
    }

    public function test_usage_model_follows_configured_model(): void
    {
        config(['ai.model' => 'gpt-4o-mini']);
        $user = User::factory()->create();
        $this->mockClientReturning(content: '=== TASK ===', tokensUsed: 3);

        app(AiTaskGeneratorService::class)->generateExtraTask(
            new ExtraTaskInput('task', 'expected'),
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
        $this->mockClientReturning(content: '=== TASK ===', tokensUsed: 1);

        app(AiTaskGeneratorService::class)->generateExtraTask(
            new ExtraTaskInput('task', 'expected', coursePrompt: 'Course prompt text'),
            User::factory()->create(),
        );

        $this->assertSame("Global prompt text\n\nCourse prompt text", $this->capturedSystem);
    }

    public function test_degrades_to_whole_text_when_markers_are_missing(): void
    {
        $this->mockClientReturning(content: 'Модель ответила свободным текстом без разметки.', tokensUsed: 4);

        $task = app(AiTaskGeneratorService::class)->generateExtraTask(
            new ExtraTaskInput('task', 'expected'),
            User::factory()->create(),
        );

        $this->assertSame('Модель ответила свободным текстом без разметки.', $task->taskText);
        $this->assertSame('', $task->expectedResult);
    }

    public function test_degrades_when_expected_marker_precedes_task_marker(): void
    {
        $content = "=== EXPECTED ===\nперепутан порядок\n=== TASK ===\nблоки перемешаны";
        $this->mockClientReturning(content: $content, tokensUsed: 4);

        $task = app(AiTaskGeneratorService::class)->generateExtraTask(
            new ExtraTaskInput('task', 'expected'),
            User::factory()->create(),
        );

        $this->assertSame($content, $task->taskText);
        $this->assertSame('', $task->expectedResult);
    }

    public function test_trims_whitespace_around_sections(): void
    {
        $this->mockClientReturning(
            content: "=== TASK ===\n\n   Новое задание.   \n\n=== EXPECTED ===\n\n  Ожидаемый ответ.  \n",
            tokensUsed: 4,
        );

        $task = app(AiTaskGeneratorService::class)->generateExtraTask(
            new ExtraTaskInput('task', 'expected'),
            User::factory()->create(),
        );

        $this->assertSame('Новое задание.', $task->taskText);
        $this->assertSame('Ожидаемый ответ.', $task->expectedResult);
    }

    public function test_user_limit_exhausted_blocks_the_llm_call(): void
    {
        config(['ai.token_limit_per_user_per_day' => 100]);
        $user = User::factory()->create();
        AiTokenUsage::factory()->extraTask()->create([
            'user_id' => $user->id,
            'tokens' => 100,
        ]);

        $this->mockClientNeverCalled();

        try {
            app(AiTaskGeneratorService::class)->generateExtraTask(
                new ExtraTaskInput('task', 'expected'),
                $user,
            );
            $this->fail('AiLimitExceededException was not thrown.');
        } catch (AiLimitExceededException $e) {
            $this->assertFalse($e->isGlobalLimit);
        }

        // Only the pre-seeded spend exists — nothing was recorded by the service.
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
            app(AiTaskGeneratorService::class)->generateExtraTask(
                new ExtraTaskInput('task', 'expected'),
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
