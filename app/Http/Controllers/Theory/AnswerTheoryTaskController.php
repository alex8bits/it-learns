<?php

declare(strict_types=1);

namespace App\Http\Controllers\Theory;

use App\Actions\Progress\AnswerTheoryTask;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnswerTheoryTaskRequest;
use App\Models\TheoryTask;
use App\Models\TheoryTaskOption;
use Illuminate\Http\RedirectResponse;

/**
 * Single-action controller: POST /theory-tasks/{task}/answer (Stage 7).
 * Inertia POST → redirect back: the action persists the answer and the
 * progress rows atomically, the one-shot `theory_feedback` flash carries
 * the outcome of this particular pick, and the lesson page re-reads
 * everything server-side on the follow-up GET.
 */
class AnswerTheoryTaskController extends Controller
{
    public function __invoke(AnswerTheoryTaskRequest $request, TheoryTask $task): RedirectResponse
    {
        // The exists-rule guarantees the id belongs to this very task;
        // the int cast narrows findOrFail() to the model (not a collection).
        $option = TheoryTaskOption::findOrFail((int) $request->validated('option_id'));

        $outcome = app(AnswerTheoryTask::class)->execute($request->user(), $task, $option);

        return redirect()->back()->with('theory_feedback', [
            'task_id' => $task->id,
            'is_correct' => $outcome->isCorrect,
            'error_text' => $outcome->errorText,
        ]);
    }
}
