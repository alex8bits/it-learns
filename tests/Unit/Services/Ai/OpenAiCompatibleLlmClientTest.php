<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\LlmClient;
use App\Services\Ai\OpenAiCompatibleLlmClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OpenAiCompatibleLlmClientTest extends TestCase
{
    private OpenAiCompatibleLlmClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.api_key' => 'test-key',
            'ai.model' => 'gpt-test',
            'ai.base_url' => 'https://llm.example.com',
            'ai.log_full_prompts' => false,
        ]);

        $this->client = new OpenAiCompatibleLlmClient;
    }

    public function test_implements_the_llm_client_contract(): void
    {
        $this->assertInstanceOf(LlmClient::class, $this->client);
    }

    #[DataProvider('baseUrls')]
    public function test_sends_the_request_to_the_base_url_chat_completions_endpoint(string $baseUrl): void
    {
        config(['ai.base_url' => $baseUrl]);

        Http::fake(['*' => Http::response($this->completionResponse())]);

        $this->client->complete('You are a tutor.', 'Explain loops.');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://llm.example.com/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request->data() === [
                    'model' => 'gpt-test',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a tutor.'],
                        ['role' => 'user', 'content' => 'Explain loops.'],
                    ],
                ];
        });
    }

    public function test_merges_options_over_the_client_payload(): void
    {
        Http::fake(['*' => Http::response($this->completionResponse())]);

        $this->client->complete('You are a tutor.', 'Explain loops.', [
            'max_tokens' => 256,
            'temperature' => 0.2,
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request['max_tokens'] === 256
                && $request['temperature'] === 0.2
                && $request['messages'] === [
                    ['role' => 'system', 'content' => 'You are a tutor.'],
                    ['role' => 'user', 'content' => 'Explain loops.'],
                ];
        });
    }

    public function test_parses_content_and_usage_tokens_from_the_response(): void
    {
        Http::fake(['*' => Http::response($this->completionResponse(313))]);

        $response = $this->client->complete('You are a tutor.', 'Explain loops.');

        $this->assertSame('Loops repeat code.', $response->content);
        $this->assertSame(313, $response->tokensUsed);
    }

    public function test_estimates_tokens_from_content_length_when_usage_is_missing(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => str_repeat('a', 44)]]],
        ])]);

        $response = $this->client->complete('You are a tutor.', 'Explain loops.');

        $this->assertSame(max(1, intdiv(44, 4)), $response->tokensUsed);
        $this->assertSame(11, $response->tokensUsed);
    }

    public function test_token_estimate_is_at_least_one_for_empty_content(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => '']]],
        ])]);

        $response = $this->client->complete('You are a tutor.', 'Explain loops.');

        $this->assertSame('', $response->content);
        $this->assertSame(1, $response->tokensUsed);
    }

    #[DataProvider('malformedSuccessBodies')]
    public function test_malformed_200_body_fails_loud_and_is_logged_with_error(string $body): void
    {
        Http::fake(['*' => Http::response($body, 200)]);

        Log::shouldReceive('info')
            ->once()
            ->with('ai.llm_call', Mockery::on(function (array $context): bool {
                return $context['provider'] === 'openai_compatible'
                    && $context['model'] === 'gpt-test'
                    && $context['system_prompt_length'] === strlen('You are a tutor.')
                    && $context['user_message_length'] === strlen('Explain loops.')
                    && $context['response_length'] === null
                    && $context['duration_ms'] >= 0
                    && str_contains((string) $context['error'], 'missing choices[0].message.content')
                    && ! array_key_exists('system_prompt', $context)
                    && ! array_key_exists('response', $context);
            }));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected OpenAI-compatible API response structure');

        $this->client->complete('You are a tutor.', 'Explain loops.');
    }

    /**
     * HTTP 200 bodies that do not carry the expected content path.
     *
     * @return array<string, array{string}>
     */
    public static function malformedSuccessBodies(): array
    {
        return [
            'empty body' => [''],
            'html page from a proxy' => ['<html><body><h1>502 Bad Gateway</h1></body></html>'],
            'json without choices' => ['{"usage":{"total_tokens":42}}'],
            'json with empty choices' => ['{"choices":[]}'],
            'json with message without content' => ['{"choices":[{"message":{"role":"assistant"}}]}'],
        ];
    }

    public function test_logs_call_metadata_without_prompt_texts_by_default(): void
    {
        Http::fake(['*' => Http::response($this->completionResponse())]);

        Log::shouldReceive('info')
            ->once()
            ->with('ai.llm_call', Mockery::on(function (array $context): bool {
                return $context['provider'] === 'openai_compatible'
                    && $context['model'] === 'gpt-test'
                    && $context['system_prompt_length'] === strlen('You are a tutor.')
                    && $context['user_message_length'] === strlen('Explain loops.')
                    && $context['response_length'] === strlen('Loops repeat code.')
                    && $context['duration_ms'] >= 0
                    && $context['error'] === null
                    && ! array_key_exists('system_prompt', $context)
                    && ! array_key_exists('response', $context);
            }));

        $this->client->complete('You are a tutor.', 'Explain loops.');
    }

    public function test_logs_full_prompt_and_response_texts_when_full_prompt_logging_is_enabled(): void
    {
        config(['ai.log_full_prompts' => true]);
        Http::fake(['*' => Http::response($this->completionResponse())]);

        Log::shouldReceive('info')
            ->once()
            ->with('ai.llm_call', Mockery::on(function (array $context): bool {
                return $context['system_prompt'] === 'You are a tutor.'
                    && $context['response'] === 'Loops repeat code.';
            }));

        $this->client->complete('You are a tutor.', 'Explain loops.');
    }

    public function test_provider_error_is_logged_with_error_and_rethrown(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        Log::shouldReceive('info')
            ->once()
            ->with('ai.llm_call', Mockery::on(function (array $context): bool {
                return $context['provider'] === 'openai_compatible'
                    && $context['model'] === 'gpt-test'
                    && $context['system_prompt_length'] === strlen('You are a tutor.')
                    && $context['user_message_length'] === strlen('Explain loops.')
                    && $context['response_length'] === null
                    && $context['duration_ms'] >= 0
                    && is_string($context['error']) && $context['error'] !== ''
                    && ! array_key_exists('system_prompt', $context)
                    && ! array_key_exists('response', $context);
            }));

        $this->expectException(RequestException::class);

        $this->client->complete('You are a tutor.', 'Explain loops.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function baseUrls(): array
    {
        return [
            'no trailing slash' => ['https://llm.example.com'],
            'trailing slash' => ['https://llm.example.com/'],
        ];
    }

    /**
     * OpenAI-shaped completion response with a configurable usage block.
     *
     * @param  int  $totalTokens  usage.total_tokens of the fake response
     * @return array<string, mixed>
     */
    private function completionResponse(int $totalTokens = 100): array
    {
        return [
            'choices' => [['message' => ['content' => 'Loops repeat code.']]],
            'usage' => ['total_tokens' => $totalTokens],
        ];
    }
}
