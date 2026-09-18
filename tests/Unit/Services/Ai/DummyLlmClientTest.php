<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\DummyLlmClient;
use App\Services\Ai\LlmClient;
use App\Services\Ai\LlmResponse;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class DummyLlmClientTest extends TestCase
{
    private DummyLlmClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new DummyLlmClient;
    }

    public function test_implements_the_llm_client_contract(): void
    {
        $this->assertInstanceOf(LlmClient::class, $this->client);
    }

    public function test_returns_an_llm_response_dto(): void
    {
        $response = $this->client->complete('You are a tutor.', 'Hello');

        $this->assertInstanceOf(LlmResponse::class, $response);
    }

    public function test_content_is_a_fixed_marker_with_the_user_message_length(): void
    {
        $response = $this->client->complete('You are a tutor.', 'Hello');

        $this->assertSame('[dummy-llm] ok (5 chars)', $response->content);
    }

    public function test_same_input_yields_identical_content_and_tokens(): void
    {
        $first = $this->client->complete('You are a tutor.', 'Hello');
        $second = $this->client->complete('You are a tutor.', 'Hello');

        $this->assertSame($first->content, $second->content);
        $this->assertSame($first->tokensUsed, $second->tokensUsed);
    }

    public function test_tokens_used_is_the_strlen_estimate_of_both_parts(): void
    {
        $systemPrompt = str_repeat('a', 12);
        $userMessage = str_repeat('b', 8);

        $response = $this->client->complete($systemPrompt, $userMessage);

        $this->assertSame(5, $response->tokensUsed);
    }

    public function test_tokens_used_is_at_least_one_for_empty_input(): void
    {
        $response = $this->client->complete('', '');

        $this->assertSame(1, $response->tokensUsed);
        $this->assertSame('[dummy-llm] ok (0 chars)', $response->content);
    }

    public function test_tokens_used_is_always_positive(): void
    {
        $short = $this->client->complete('a', 'b');
        $long = $this->client->complete(str_repeat('a', 100), str_repeat('b', 100));

        $this->assertGreaterThan(0, $short->tokensUsed);
        $this->assertGreaterThan(0, $long->tokensUsed);
        $this->assertGreaterThan($short->tokensUsed, $long->tokensUsed);
    }

    public function test_logs_the_ai_llm_call_contract_record(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with('ai.llm_call', Mockery::on(function (array $context): bool {
                return $context['provider'] === 'dummy'
                    && $context['model'] === null
                    && $context['system_prompt_length'] === 15
                    && $context['user_message_length'] === 5
                    && $context['response_length'] > 0
                    && $context['duration_ms'] >= 0;
            }));

        $this->client->complete(str_repeat('a', 15), 'Hello');
    }
}
