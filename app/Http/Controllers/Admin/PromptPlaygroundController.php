<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\RunPromptPlayground;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminPromptPlaygroundRequest;
use App\Models\AiPromptVersion;
use App\Models\Course;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PromptPlaygroundController extends Controller
{
    /**
     * Playground form: the course selector lets the admin verify the
     * global + course prompt glue (PromptResolver) against the active
     * LlmClient without touching the student zone. The previous run's
     * result arrives via the `playground_result` flash.
     */
    public function edit(): Response
    {
        $this->authorize('playground', AiPromptVersion::class);

        return Inertia::render('Admin/Prompts/Playground', [
            'courses' => Course::query()->orderBy('title')->get(['id', 'title', 'status']),
            'result' => session('playground_result'),
        ]);
    }

    /**
     * Run the test call and redirect back with the result. The
     * AiLimitExceededException is deliberately not caught: the global
     * renderable (bootstrap/app.php) maps it to a redirect back with the
     * `errors.ai` bag — no tokens are spent on a refused run.
     */
    public function run(AdminPromptPlaygroundRequest $request): RedirectResponse
    {
        $this->authorize('playground', AiPromptVersion::class);

        $courseId = $request->validated('course_id');
        $course = $courseId === null ? null : Course::find((int) $courseId);

        $result = app(RunPromptPlayground::class)->execute(
            $request->user(),
            (string) $request->validated('user_message'),
            $course,
        );

        return redirect()
            ->route('admin.prompts.playground')
            ->with('playground_result', $result);
    }
}
