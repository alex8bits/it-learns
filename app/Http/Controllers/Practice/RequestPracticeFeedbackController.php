<?php

declare(strict_types=1);

namespace App\Http\Controllers\Practice;

use App\Actions\Practice\RequestPracticeFeedback;
use App\Http\Controllers\Controller;
use App\Models\PracticeTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Single-action controller: POST /practice-tasks/{task}/ai-feedback
 * (Stage 8, premium). There are no user-supplied fields to validate
 * (the DeleteTheoryTask precedent) — the feedback context is assembled
 * server-side from the user's latest submission inside the action.
 * Inertia POST → redirect back: the one-shot `ai_feedback` flash
 * carries the explanation text; an exhausted token budget never
 * reaches this return (the global renderable maps it to 429).
 */
class RequestPracticeFeedbackController extends Controller
{
    public function __invoke(Request $request, PracticeTask $task): RedirectResponse
    {
        $feedback = app(RequestPracticeFeedback::class)->execute($request->user(), $task);

        return redirect()->back()->with('ai_feedback', [
            'task_id' => $task->id,
            'body' => $feedback->body,
        ]);
    }
}
