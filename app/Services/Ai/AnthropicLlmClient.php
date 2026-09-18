<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiProvider;
use App\Services\Ai\Concerns\LogsLlmCalls;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Anthropic implementation of LlmClient: POST /v1/messages with x-api-key
 * auth and a pinned anthropic-version header. Unlike the OpenAI-family
 * clients the system prompt travels as a dedicated top-level `system`
 * field, and max_tokens is required by the API (client default 1024,
 * overridable via $options). Response contract: content[0].text and
 * usage.input_tokens + usage.output_tokens; when usage is missing, the
 * shared strlen-based token estimate applies. A HTTP 200 body without
 * the expected content path (proxy HTML page, empty body, contract
 * drift) fails loud instead of yielding an empty completion.
 */
class AnthropicLlmClient implements LlmClient
{
    use LogsLlmCalls;

    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    private const DEFAULT_MAX_TOKENS = 1024;

    /**
     * Send the completion request to Anthropic.
     *
     * @param  array<string, mixed>  $options  provider-specific parameters (max_tokens, temperature, ...) merged over the client payload
     *
     * @throws ConnectionException on a network failure (timeout, DNS)
     * @throws RequestException on a provider HTTP error (fail loud, no retries)
     * @throws RuntimeException on a malformed 200 response missing content[0].text
     */
    public function complete(string $systemPrompt, string $userMessage, array $options = []): LlmResponse
    {
        $apiKey = (string) config('ai.api_key');
        $model = (string) config('ai.model');

        $payload = array_merge([
            'model' => $model,
            'max_tokens' => self::DEFAULT_MAX_TOKENS,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ], $options);

        $startedAt = microtime(true);

        try {
            $json = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => self::ANTHROPIC_VERSION,
            ])
                ->timeout(30)
                ->post(self::ENDPOINT, $payload)
                ->throw()
                ->json();

            $content = $this->extractContent($json);
            $tokensUsed = $this->extractTokensUsed($json, $content);
        } catch (Throwable $exception) {
            $this->logCall($systemPrompt, $userMessage, null, $model, $startedAt, $exception);

            throw $exception;
        }

        $this->logCall($systemPrompt, $userMessage, $content, $model, $startedAt, null);

        return new LlmResponse(
            content: $content,
            tokensUsed: $tokensUsed,
        );
    }

    /**
     * Provider enum case written into every ai.llm_call record.
     */
    protected function llmProvider(): AiProvider
    {
        return AiProvider::Anthropic;
    }

    /**
     * Extract content[0].text from the provider payload, failing loud
     * when the expected path is missing.
     *
     * @param  mixed  $json  decoded response body
     *
     * @throws RuntimeException when content[0].text is missing (malformed 200 response)
     */
    private function extractContent(mixed $json): string
    {
        $blocks = is_array($json) ? ($json['content'] ?? null) : null;
        $block = is_array($blocks) ? ($blocks[0] ?? null) : null;
        $text = is_array($block) ? ($block['text'] ?? null) : null;

        if (! is_string($text)) {
            throw new RuntimeException('Unexpected Anthropic API response structure: missing content[0].text');
        }

        return $text;
    }

    /**
     * Extract usage.input_tokens + usage.output_tokens, falling back to
     * the strlen-based estimate (~4 characters per token, consistent
     * with DummyLlmClient).
     *
     * @param  mixed  $json  decoded response body
     */
    private function extractTokensUsed(mixed $json, string $content): int
    {
        $usage = is_array($json) ? ($json['usage'] ?? null) : null;
        $inputTokens = is_array($usage) ? ($usage['input_tokens'] ?? null) : null;
        $outputTokens = is_array($usage) ? ($usage['output_tokens'] ?? null) : null;

        if (is_numeric($inputTokens) && is_numeric($outputTokens)) {
            return (int) $inputTokens + (int) $outputTokens;
        }

        return max(1, intdiv(strlen($content), 4));
    }
}
