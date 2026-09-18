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
 * MiniMax implementation of LlmClient.
 *
 * Contract fixed at Stage 4 against MiniMax ChatCompletion v2; if the
 * provider API drifts, only this class changes (the whitelist factory
 * keeps it a single point of extension). Wire format: POST
 * /v1/text/chatcompletion_v2 with Bearer auth and an OpenAI-shaped
 * messages array (system prompt first, user payload last). Response
 * contract: choices[0].message.content and usage.total_tokens; when
 * usage is missing, the shared strlen-based token estimate applies.
 * MiniMax reports API errors inside a HTTP 200 body via
 * base_resp.status_code (0 = success, e.g. 1004 = invalid api key) —
 * a non-zero code fails loud instead of yielding an empty completion,
 * as does a HTTP 200 body without the expected content path (proxy
 * HTML page, empty body, contract drift).
 */
class MiniMaxLlmClient implements LlmClient
{
    use LogsLlmCalls;

    private const ENDPOINT = 'https://api.minimax.io/v1/text/chatcompletion_v2';

    /**
     * Send the completion request to MiniMax.
     *
     * @param  array<string, mixed>  $options  provider-specific parameters (max_tokens, temperature, ...) merged over the client payload
     *
     * @throws ConnectionException on a network failure (timeout, DNS)
     * @throws RequestException on a provider HTTP error (fail loud, no retries)
     * @throws RuntimeException on a non-zero base_resp.status_code inside a 200 response or on a malformed 200 response missing choices[0].message.content
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
        return AiProvider::Minimax;
    }

    /**
     * Extract choices[0].message.content from the provider payload,
     * failing loud on a non-zero base_resp.status_code first (MiniMax
     * error channel inside a HTTP 200 body) and on a missing content
     * path after that.
     *
     * @param  mixed  $json  decoded response body
     *
     * @throws RuntimeException when base_resp.status_code is non-zero or choices[0].message.content is missing (malformed 200 response)
     */
    private function extractContent(mixed $json): string
    {
        $this->assertNoBaseRespError($json);

        $choices = is_array($json) ? ($json['choices'] ?? null) : null;
        $choice = is_array($choices) ? ($choices[0] ?? null) : null;
        $message = is_array($choice) ? ($choice['message'] ?? null) : null;
        $content = is_array($message) ? ($message['content'] ?? null) : null;

        if (! is_string($content)) {
            throw new RuntimeException('Unexpected MiniMax API response structure: missing choices[0].message.content');
        }

        return $content;
    }

    /**
     * Guard the MiniMax-specific base_resp error channel: status_code 0
     * (or an absent base_resp) means success, any other value is a
     * provider error that must fail loud (e.g. 1004 = invalid api key).
     *
     * @param  mixed  $json  decoded response body
     *
     * @throws RuntimeException when base_resp.status_code is present and non-zero
     */
    private function assertNoBaseRespError(mixed $json): void
    {
        $baseResp = is_array($json) ? ($json['base_resp'] ?? null) : null;
        $statusCode = is_array($baseResp) ? ($baseResp['status_code'] ?? null) : null;

        if (is_numeric($statusCode) && (int) $statusCode !== 0) {
            $statusMsg = is_string($baseResp['status_msg'] ?? null) ? $baseResp['status_msg'] : '';

            throw new RuntimeException(sprintf('MiniMax API error %d: %s', (int) $statusCode, $statusMsg));
        }
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
