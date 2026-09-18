<?php

declare(strict_types=1);

namespace App\Services\Ai\Concerns;

use App\Enums\AiProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared ai.llm_call logging for the HTTP LLM clients. The log record is
 * provider-agnostic: the provider enum case is supplied by the using
 * client through llmProvider(), everything else is the fixed contract
 * (metadata only by default, full prompt and response texts exclusively
 * when log_full_prompts is enabled).
 */
trait LogsLlmCalls
{
    /**
     * Provider enum case written into every ai.llm_call record.
     */
    abstract protected function llmProvider(): AiProvider;

    /**
     * Write the shared ai.llm_call record: metadata only by default, the
     * full prompt and response texts are included exclusively when
     * log_full_prompts is enabled.
     *
     * @param  float  $startedAt  microtime of the HTTP call start
     * @param  string|null  $content  generated text, null when the call failed
     */
    private function logCall(string $systemPrompt, string $userMessage, ?string $content, string $model, float $startedAt, ?Throwable $error): void
    {
        $context = [
            'provider' => $this->llmProvider()->value,
            'model' => $model,
            'system_prompt_length' => strlen($systemPrompt),
            'user_message_length' => strlen($userMessage),
            'response_length' => $content === null ? null : strlen($content),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error' => $error === null ? null : $error->getMessage(),
        ];

        if (config('ai.log_full_prompts') === true && $content !== null) {
            $context['system_prompt'] = $systemPrompt;
            $context['response'] = $content;
        }

        Log::info('ai.llm_call', $context);
    }
}
