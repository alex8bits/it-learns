<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Result of LlmClient::complete(): the generated text and how many
 * tokens the call consumed.
 */
final readonly class LlmResponse
{
    /**
     * @param  string  $content  generated text returned by the provider
     * @param  int  $tokensUsed  tokens consumed by the call (provider-reported, or a strlen-based estimate when the provider omits usage data)
     */
    public function __construct(
        public string $content,
        public int $tokensUsed,
    ) {}
}
