<?php

declare(strict_types=1);

namespace App\Services\Practice\Dto;

/**
 * Outcome of executing the student's code inside a practice
 * environment. A non-null error marks a rejected or failed attempt
 * (guard violation, SQL error, timeout, size limit) — violations are
 * reported through this DTO instead of exceptions, keeping the
 * outcome a normal attempt result the UI can render.
 */
final readonly class ExecutionResult
{
    /**
     * @param  array<int, array<string, mixed>>|null  $rows  rows of a SELECT statement; null for non-SELECT statements and for errors
     * @param  list<string>|null  $columns  column names in the result order; null when rows are null
     * @param  float  $durationMs  wall-clock execution time in milliseconds, measured via hrtime around the PDO call
     * @param  string|null  $error  human-readable rejection/failure reason; null on success
     */
    public function __construct(
        public ?array $rows,
        public ?array $columns,
        public float $durationMs,
        public ?string $error = null,
    ) {}
}
