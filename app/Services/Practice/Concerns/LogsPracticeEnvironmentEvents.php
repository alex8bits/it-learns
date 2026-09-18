<?php

declare(strict_types=1);

namespace App\Services\Practice\Concerns;

use App\Enums\PracticeRuntime;
use Illuminate\Support\Facades\Log;

/**
 * Shared practice.environment_* structured logging for the practice
 * environment drivers (the LogsLlmCalls pattern): metadata only — the
 * student's SQL never enters the log, just the driver, runtime, task
 * id, duration and the rejection reason when there is one.
 */
trait LogsPracticeEnvironmentEvents
{
    /**
     * Log practice.environment_provisioned — after a Ready row was
     * persisted, or after a failure was persisted and rethrown.
     */
    private function logProvisioned(string $driver, PracticeRuntime $runtime, ?int $taskId, float $durationMs, ?string $error = null): void
    {
        $this->logEnvironmentEvent('provisioned', $driver, $runtime, $taskId, $durationMs, $error);
    }

    /**
     * Log practice.environment_executed — one record per execute()
     * call, successful or rejected; the reason comes from the result's
     * error field.
     */
    private function logExecuted(string $driver, PracticeRuntime $runtime, ?int $taskId, float $durationMs, ?string $error = null): void
    {
        $this->logEnvironmentEvent('executed', $driver, $runtime, $taskId, $durationMs, $error);
    }

    /**
     * Log practice.environment_destroyed — teardown carries no
     * meaningful duration, so the record stays minimal.
     */
    private function logDestroyed(string $driver, PracticeRuntime $runtime, ?int $taskId): void
    {
        Log::info('practice.environment_destroyed', [
            'driver' => $driver,
            'runtime' => $runtime->value,
            'task_id' => $taskId,
        ]);
    }

    /**
     * Log practice.environment_pruned — the TTL safety net
     * (practice:prune-environments) claimed a dangling environment row
     * and/or its stale labeled container left behind by a crashed
     * process. Container-only prunes carry no runtime/environment_id.
     */
    private function logPruned(string $reason, ?PracticeRuntime $runtime, ?int $environmentId, ?string $containerId = null): void
    {
        Log::info('practice.environment_pruned', [
            'runtime' => $runtime?->value,
            'environment_id' => $environmentId,
            'container_id' => $containerId,
            'reason' => $reason,
        ]);
    }

    /**
     * Assemble and write the shared record.
     */
    private function logEnvironmentEvent(string $event, string $driver, PracticeRuntime $runtime, ?int $taskId, float $durationMs, ?string $error): void
    {
        Log::info("practice.environment_{$event}", [
            'driver' => $driver,
            'runtime' => $runtime->value,
            'task_id' => $taskId,
            'duration_ms' => (int) round($durationMs),
            'error' => $error,
        ]);
    }
}
