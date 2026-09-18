<?php

declare(strict_types=1);

namespace App\Http\Controllers\Practice;

use App\Actions\Practice\SubmitPracticeTaskSolution;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitPracticeTaskRequest;
use App\Models\PracticeTask;
use Illuminate\Http\RedirectResponse;

/**
 * Single-action controller: POST /practice-tasks/{task}/submit (Stage 8).
 * Inertia POST → redirect back: the action runs the attempt, persists the
 * submission and the progress rows atomically, and the one-shot
 * `practice_feedback` flash carries the outcome (result, diff, error) of
 * this particular attempt; the lesson page re-reads everything
 * server-side on the follow-up GET.
 */
class SubmitPracticeTaskController extends Controller
{
    public function __invoke(SubmitPracticeTaskRequest $request, PracticeTask $task): RedirectResponse
    {
        $result = app(SubmitPracticeTaskSolution::class)->execute(
            $request->user(),
            $task,
            (string) $request->validated('code'),
        );

        $outcome = $result->outcome;

        return redirect()->back()->with('practice_feedback', [
            'task_id' => $task->id,
            'status' => $outcome->status->value,
            'result' => $outcome->result === null ? null : [
                'rows' => $outcome->result->rows,
                'columns' => $outcome->result->columns,
                'duration_ms' => $outcome->result->durationMs,
                'error' => $outcome->result->error,
            ],
            'diff' => $result->submission?->result_diff,
            'error_text' => $result->submission?->error_text,
        ]);
    }
}
