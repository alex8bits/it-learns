<?php

declare(strict_types=1);

namespace App\Services\Ai;

interface LlmClient
{
    /**
     * Send a completion request to the provider and return its result.
     *
     * The single integration seam of the AI layer: every AI call in the
     * application goes through this interface (rule "LlmClient only",
     * never a direct HTTP call to the provider), resolved by the
     * container as a singleton based on config('ai.provider').
     *
     * @param  string  $systemPrompt  system-level instructions (global + course prompt, assembled by PromptResolver)
     * @param  string  $userMessage  the concrete request payload (task text, submitted solution, error message)
     * @param  array<string, mixed>  $options  provider-specific parameters (max_tokens, temperature, ...)
     */
    public function complete(string $systemPrompt, string $userMessage, array $options = []): LlmResponse;
}
