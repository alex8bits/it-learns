<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

/**
 * A daily AI token budget is exhausted (rule #16 of AGENTS.md).
 *
 * Thrown by {@see AiLimitGuard} before any LLM call — fail loud, no
 * retries, no silent resets. The future HTTP layer (Stage 8) maps this
 * to a 429 response; there is no HTTP surface in Stage 4.
 */
final class AiLimitExceededException extends RuntimeException
{
    /**
     * @param  bool  $isGlobalLimit  `true` — the platform-wide budget is
     *                               exhausted, `false` — the user's own.
     */
    public function __construct(public readonly bool $isGlobalLimit)
    {
        parent::__construct($isGlobalLimit
            ? 'Глобальный дневной лимит токенов ИИ исчерпан'
            : 'Дневной лимит токенов ИИ пользователя исчерпан');
    }
}
