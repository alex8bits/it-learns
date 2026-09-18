<?php

declare(strict_types=1);

namespace App\Services\Practice\Dto;

use App\Enums\PracticeAttemptStatus;

/**
 * Final outcome of a practice attempt as produced by
 * RunPracticeTaskAction: the attempt status, the raw execution result
 * and the expected hash the attempt was compared against. The former
 * inline AI feedback field is gone (Stage 8, design В2-B): premium
 * feedback is an explicit route over the stored submission.
 */
final readonly class PracticeRunOutcome
{
    /**
     * @param  PracticeAttemptStatus  $status  attempt status (Busy marks a lost environment lock race)
     * @param  ExecutionResult|null  $result  raw execution result; null when the attempt never executed (lock race)
     * @param  string|null  $expectedHash  the 64-hex hash the attempt was compared against, echoed back to the UI
     */
    public function __construct(
        public PracticeAttemptStatus $status,
        public ?ExecutionResult $result,
        public ?string $expectedHash,
    ) {}
}
