<?php

declare(strict_types=1);

namespace App\Services\Progress\Dto;

/**
 * Outcome of answering a theory task as produced by AnswerTheoryTask:
 * whether the picked option was correct and, for an incorrect pick, the
 * option's explanation shown to the user (null for a correct answer).
 */
final readonly class AnswerOutcome
{
    /**
     * @param  bool  $isCorrect  whether the picked option is the correct one
     * @param  string|null  $errorText  the picked option's explanation of why it is wrong; null on a correct answer
     */
    public function __construct(
        public bool $isCorrect,
        public ?string $errorText,
    ) {}
}
