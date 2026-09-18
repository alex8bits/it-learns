<?php

declare(strict_types=1);

namespace App\Services\Practice\Docker\Dto;

/**
 * Outcome of a single `docker exec` invocation. Unlike the
 * student-facing ExecutionResult this DTO stays infra-shaped: the exit
 * code is preserved as-is, the outputs are raw, and no guard verdicts
 * are applied — mapping engine output onto the practice contract is
 * the job of DockerPracticeEnvironment.
 */
final readonly class DockerExecResult
{
    /**
     * @param  int  $exitCode  exit code of the in-container command; 124 marks a locally hard-killed run (timeout)
     * @param  string  $output  raw stdout of the command
     * @param  string  $errorOutput  raw stderr of the command
     * @param  float  $durationMs  wall-clock duration of the exec in milliseconds, measured via hrtime
     */
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
        public float $durationMs,
    ) {}
}
