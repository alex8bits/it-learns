<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Practice\RunPracticeTaskAction;
use App\Models\User;
use App\Services\Practice\CanonicalResultSerializer;
use App\Services\Practice\Dto\PracticeRunOutcome;
use App\Services\Practice\Dto\PracticeTaskInput;

/**
 * Admin-side verification of a practice task draft (design
 * 2026-09-16-admin-practice-task-verification): derive the expected
 * hash from the raw form rows, assemble a one-shot PracticeTaskInput
 * from primitives and delegate to the standard harness. The check is
 * stateless — nothing is persisted and no audit row is written (the
 * run only leaves the usual practice_environments telemetry inside
 * RunPracticeTaskAction; rule #17 covers state-changing operations).
 *
 * The environment invariant (provision -> execute -> compare ->
 * destroy in `finally`) is NOT duplicated here: the only execution
 * path is RunPracticeTaskAction.
 */
final class VerifyPracticeTask
{
    public function __construct(
        private CanonicalResultSerializer $serializer,
        private RunPracticeTaskAction $runPracticeTask,
    ) {}

    /**
     * Run the reference `code` against the seed script and compare the
     * actual rows with the expected rows, when any were supplied.
     *
     * @param  list<array<string, mixed>>|null  $expectedRows  decoded expected rows; null when the check runs without a reference result
     */
    public function execute(User $admin, string $code, ?string $seedSql, ?array $expectedRows): PracticeRunOutcome
    {
        $hash = null;

        if ($expectedRows !== null && $expectedRows !== []) {
            $hash = $this->serializer->hash($expectedRows, array_keys($expectedRows[0]));
        }

        $input = new PracticeTaskInput(
            taskId: null,
            taskText: null,
            seedScript: $seedSql,
            expectedHash: $hash,
        );

        return $this->runPracticeTask->execute($admin, $input, $code);
    }
}
