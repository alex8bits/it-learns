<?php

declare(strict_types=1);

namespace App\Services\Practice;

use App\Models\PracticeEnvironment;
use App\Models\User;
use App\Services\Practice\Dto\ExecutionResult;
use App\Services\Practice\Dto\PracticeTaskInput;
use RuntimeException;

/**
 * Contract of the isolated practice runtime (Stage 5, the third
 * application of the PaymentGateway/LlmClient whitelist pattern).
 * The implementation is selected via config('practice.driver') and
 * bound in AppServiceProvider — switching to the future Docker
 * runtime is a one-line config change, no Action/Controller edits.
 */
interface PracticeEnvironmentManager
{
    /**
     * Bring up a disposable environment for the given user and task
     * (a fresh SQLite file with the trusted seed script applied in
     * the local runtime). Persists a Ready PracticeEnvironment row.
     *
     * @throws RuntimeException on an infrastructure failure — a Failed
     *                          row is persisted first, the half-created
     *                          environment is removed, then the
     *                          exception propagates (HTTP 500).
     */
    public function provision(User $user, PracticeTaskInput $task): PracticeEnvironment;

    /**
     * Execute the student's code inside the environment behind the
     * mandatory safety guards. Guard violations, SQL errors, timeouts
     * and size limits are reported via ExecutionResult::error — they
     * never throw.
     */
    public function execute(PracticeEnvironment $environment, string $code): ExecutionResult;

    /**
     * Compare an executed result against the expected 64-hex hash
     * using the canonical serialization. Always returns false for a
     * result carrying an error.
     */
    public function compare(ExecutionResult $actual, string $expectedHash): bool;

    /**
     * Tear the environment down (delete the database file, mark the
     * row Destroyed). Must be called by the calling code only from a
     * `finally` block so the environment is destroyed even when the
     * attempt throws. Idempotent: destroying twice must not fail.
     */
    public function destroy(PracticeEnvironment $environment): void;
}
