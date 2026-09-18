<?php

declare(strict_types=1);

namespace App\Services\Practice\Dto;

use App\Models\PracticeTaskSubmission;

/**
 * Result of SubmitPracticeTaskSolution: the run outcome plus the
 * submission row the attempt produced (the "second value" the flash
 * payload needs). The submission is null for a Busy outcome — a lost
 * lock race means no attempt ever happened, so nothing was stored.
 */
final readonly class SubmitSolutionOutcome
{
    /**
     * @param  PracticeRunOutcome  $outcome  the attempt outcome as produced by RunPracticeTaskAction
     * @param  PracticeTaskSubmission|null  $submission  the persisted attempt row; null on Busy
     */
    public function __construct(
        public PracticeRunOutcome $outcome,
        public ?PracticeTaskSubmission $submission,
    ) {}
}
