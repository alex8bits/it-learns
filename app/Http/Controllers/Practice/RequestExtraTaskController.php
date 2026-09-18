<?php

declare(strict_types=1);

namespace App\Http\Controllers\Practice;

use App\Actions\Practice\RequestExtraTask;
use App\Http\Controllers\Controller;
use App\Models\PracticeTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Single-action controller: POST /practice-tasks/{task}/ai-extra-task
 * (Stage 8, premium). There are no user-supplied fields to validate
 * (the DeleteTheoryTask precedent) — the reference task comes from
 * route model binding. Inertia POST → redirect back: the one-shot
 * `extra_task` flash carries the generated task; the result is shown
 * to the student only and is never persisted (design non-goal).
 */
class RequestExtraTaskController extends Controller
{
    public function __invoke(Request $request, PracticeTask $task): RedirectResponse
    {
        $result = app(RequestExtraTask::class)->execute($request->user(), $task);

        return redirect()->back()->with('extra_task', [
            'task_id' => $task->id,
            'task_text' => $result->taskText,
            'expected_result' => $result->expectedResult,
        ]);
    }
}
