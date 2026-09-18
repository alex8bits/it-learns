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
 * OpenAI implementation of LlmClient: POST /v1/chat/completions with
 * Bearer auth, the system prompt as the leading message and the user
 * payload as the last one. Response contract: choices[0].message.content
 * and usage.total_tokens; when usage is missing, the shared strlen-based
 * token estimate applies. A HTTP 200 body without the expected content
 * path (proxy HTML page, empty body, contract drift) fails loud instead
 * of yielding an empty completion.
 */
class OpenAiLlmClient implements LlmClient
{
    use LogsLlmCalls;

    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /**
     * Send the completion request to OpenAI.
     *
     * @param  array<string, mixed>  $options  provider-specific parameters (max_tokens, temperature, ...) merged over the client payload
     *
     * @throws ConnectionException on a network failure (timeout, DNS)
     * @throws RequestException on a provider HTTP error (fail loud, no retries)
     * @throws RuntimeException on a malformed 200 response missing choices[0].message.content
     */
    public function complete(string $systemPrompt, string $userMessage, array $options = []): LlmResponse
    {
        $apiKey = (string) config('ai.api_key');
        $model = (string) config('ai.model');

        $payload = array_merge([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
        ], $options);

        $startedAt = microtime(true);

        try {
            $json = Http::withToken($apiKey)
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
        return AiProvider::Openai;
    }

    /**
     * Extract choices[0].message.content from the provider payload,
     * failing loud when the expected path is missing.
     *
     * @param  mixed  $json  decoded response body
     *
     * @throws RuntimeException when choices[0].message.content is missing (malformed 200 response)
     */
    private function extractContent(mixed $json): string
    {
        $choices = is_array($json) ? ($json['choices'] ?? null) : null;
        $choice = is_array($choices) ? ($choices[0] ?? null) : null;
        $message = is_array($choice) ? ($choice['message'] ?? null) : null;
        $content = is_array($message) ? ($message['content'] ?? null) : null;

        if (! is_string($content)) {
            throw new RuntimeException('Unexpected OpenAI API response structure: missing choices[0].message.content');
        }

        return $content;
    }

    /**
     * Extract usage.total_tokens, falling back to the strlen-based
     * estimate (~4 characters per token, consistent with DummyLlmClient).
     *
     * @param  mixed  $json  decoded response body
     */
    private function extractTokensUsed(mixed $json, string $content): int
    {
        $usage = is_array($json) ? ($json['usage'] ?? null) : null;
        $totalTokens = is_array($usage) ? ($usage['total_tokens'] ?? null) : null;

        if (is_numeric($totalTokens)) {
            return (int) $totalTokens;
        }

        return max(1, intdiv(strlen($content), 4));
    }
}
