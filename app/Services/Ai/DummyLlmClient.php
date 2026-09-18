<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiProvider;
use Illuminate\Support\Facades\Log;

/**
 * Fictitious LLM client for dev/tests/demo (consistent with
 * DummyPaymentGateway): "asked the model -> immediately got an answer"
 * without any network calls. Deterministic — the same input always
 * yields the same content and tokensUsed, which is what the tests
 * rely on.
 */
class DummyLlmClient implements LlmClient
{
    /**
     * Deterministic completion: a fixed marker string carrying the
     * user message length, and the strlen-based token estimate from
     * the platform plan (Stage 4: ~4 characters per token).
     */
    public function complete(string $systemPrompt, string $userMessage, array $options = []): LlmResponse
    {
        $startedAt = microtime(true);

        $content = '[dummy-llm] ok ('.strlen($userMessage).' chars)';
        $tokensUsed = max(1, intdiv(strlen($systemPrompt) + strlen($userMessage), 4));

        Log::info('ai.llm_call', [
            'provider' => AiProvider::Dummy->value,
            'model' => null,
            'system_prompt_length' => strlen($systemPrompt),
            'user_message_length' => strlen($userMessage),
            'response_length' => strlen($content),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return new LlmResponse(
            content: $content,
            tokensUsed: $tokensUsed,
        );
    }
}
