<?php

declare(strict_types=1);

namespace App\Services\Practice\Dto;

use App\Enums\PracticeRuntime;

/**
 * A practice task as seen by the practice environment manager. Built
 * from primitives by design (user decision 2026-09-14, the parallel of
 * FeedbackInput): before Stages 7-8 the task is supplied by the stub
 * HTTP harness, and Stage 8 assembles it from the PracticeTask model
 * via a thin wrapper — without changing the manager contract.
 */
final readonly class PracticeTaskInput
{
    /**
     * @param  int|null  $taskId  practice task identifier; null until the practice_tasks table exists (Stages 7-8)
     * @param  string|null  $taskText  task wording, consumed by the AI feedback on a failed premium attempt
     * @param  string|null  $seedScript  trusted DDL/DML executed on provision without the student-facing guards
     * @param  string|null  $expectedHash  64-hex sha256 of the canonical expected result (CanonicalResultSerializer::hash())
     * @param  PracticeRuntime|null  $runtime  runtime the task must run on; null keeps the driver's default behaviour (Stage 10: per-task runtimes)
     */
    public function __construct(
        public ?int $taskId = null,
        public ?string $taskText = null,
        public ?string $seedScript = null,
        public ?string $expectedHash = null,
        public ?PracticeRuntime $runtime = null,
    ) {}
}
