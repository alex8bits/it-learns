<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\PracticeAttemptStatus;
use App\Services\Practice\Dto\PracticeRunOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON payload of the admin "check reference query" endpoint
 * (POST /admin/practice-tasks/check) — the fetch consumer in the
 * Create/Edit practice-task forms renders the actual rows, the
 * duration and the comparison chip from this shape.
 *
 * @property PracticeRunOutcome $resource
 */
class PracticeCheckResource extends JsonResource
{
    /**
     * No `data` wrapper: the top-level shape is the payload itself (the
     * same per-class shadowing as CourseResource).
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * `matched` is null when the check ran without a reference result
     * (nothing to compare against — RunPracticeTaskAction reports Failed
     * informationally in that case); otherwise it mirrors the Passed
     * status of the outcome.
     *
     * @return array{status: string, matched: bool|null, result: array{rows: array<int, array<string, mixed>>|null, columns: list<string>|null, duration_ms: float, error: string|null}|null}
     */
    public function toArray(Request $request): array
    {
        $outcome = $this->resource;

        return [
            'status' => $outcome->status->value,
            'matched' => $outcome->expectedHash === null
                ? null
                : $outcome->status === PracticeAttemptStatus::Passed,
            'result' => $outcome->result === null ? null : [
                'rows' => $outcome->result->rows,
                'columns' => $outcome->result->columns,
                'duration_ms' => $outcome->result->durationMs,
                'error' => $outcome->result->error,
            ],
        ];
    }
}
