<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\AnthropicLlmClient;
use App\Services\Ai\LlmClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AnthropicLlmClientTest extends TestCase
{
    private AnthropicLlmClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.api_key' => 'test-key',
            'ai.model' => 'gpt-test',
            'ai.base_url' => 'https://llm.example.com',
            'ai.log_full_prompts' => false,
        ]);

        $this->client = new AnthropicLlmClient;
    }

    public function test_implements_the_llm_client_contract(): void
    {
        $this->assertInstanceOf(LlmClient::class, $this->client);
    }

    public function test_sends_the_request_with_api_key_and_version_headers_and_system_field(): void
    {
        Http::fake(['*' => Http::response($this->messagesResponse())]);

        $this->client->complete('You are a tutor.', 'Explain loops.');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'test-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && ! $request->hasHeader('Authorization')
                && $request->data() === [
                    'model' => 'gpt-test',
                    'max_tokens' => 1024,
                    'system' => 'You are a tutor.',
                    'messages' => [
                        ['role' => 'user', 'content' => 'Explain loops.'],
                    ],
                ];
        });
    }

    public function test_merges_options_over_the_client_defaults(): void
    {
        Http::fake(['*' => Http::response($this->messagesResponse())]);

        $this->client->complete('You are a tutor.', 'Explain loops.', [
            'max_tokens' => 512,
            'temperature' => 0.7,
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request['max_tokens'] === 512
                && $request['temperature'] === 0.7
                && $request['system'] === 'You are a tutor.'
                && $request['messages'] === [
                    ['role' => 'user', 'content' => 'Explain loops.'],
                ];
        });
    }

    public function test_parses_content_and_sums_usage_tokens_from_the_response(): void
    {
        Http::fake(['*' => Http::response($this->messagesResponse(210, 103))]);

        $response = $this->client->complete('You are a tutor.', 'Explain loops.');

        $this->assertSame('Loops repeat code.', $response->content);
        $this->assertSame(313, $response->tokensUsed);
    }

    public function test_estimates_tokens_from_content_length_when_usage_is_missing(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => str_repeat('a', 44)]],
        ])]);

        $response = $this->client->complete('You are a tutor.', 'Explain loops.');

        $this->assertSame(max(1, intdiv(44, 4)), $response->tokensUsed);
        $this->assertSame(11, $response->tokensUsed);
    }

    public function test_partial_usage_falls_back_to_the_content_based_estimate(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => str_repeat('a', 40)]],
            'usage' => ['output_tokens' => 55],
        ])]);

        $response = $this->client->complete('You are a tutor.', 'Explain loops.');

        $this->assertSame(10, $response->tokensUsed);
    }

    public function test_token_estimate_is_at_least_one_for_empty_content(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => '']],
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
                return $context['provider'] === 'anthropic'
                    && $context['model'] === 'gpt-test'
                    && $context['system_prompt_length'] === strlen('You are a tutor.')
                    && $context['user_message_length'] === strlen('Explain loops.')
                    && $context['response_length'] === null
                    && $context['duration_ms'] >= 0
                    && str_contains((string) $context['error'], 'missing content[0].text')
                    && ! array_key_exists('system_prompt', $context)
                    && ! array_key_exists('response', $context);
            }));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected Anthropic API response structure');

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
            'json without content blocks' => ['{"usage":{"input_tokens":7,"output_tokens":35}}'],
            'json with empty content' => ['{"content":[]}'],
            'json with block without text' => ['{"content":[{"type":"text"}]}'],
        ];
    }

    public function test_logs_call_metadata_without_prompt_texts_by_default(): void
    {
        Http::fake(['*' => Http::response($this->messagesResponse())]);

        Log::shouldReceive('info')
            ->once()
            ->with('ai.llm_call', Mockery::on(function (array $context): bool {
                return $context['provider'] === 'anthropic'
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
        Http::fake(['*' => Http::response($this->messagesResponse())]);

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
                return $context['provider'] === 'anthropic'
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
     * Anthropic-shaped Messages response with a configurable usage block.
     *
     * @param  int  $inputTokens  usage.input_tokens of the fake response
     * @param  int  $outputTokens  usage.output_tokens of the fake response
     * @return array<string, mixed>
     */
    private function messagesResponse(int $inputTokens = 100, int $outputTokens = 50): array
    {
        return [
            'content' => [['type' => 'text', 'text' => 'Loops repeat code.']],
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ];
    }
}
