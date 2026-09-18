<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminCoursePromptRequest;
use App\Models\Course;
use App\Services\Ai\PromptKeys;
use App\Services\Ai\PromptVersionService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Course-specific clarifying prompt editor — the only write path for
 * `courses.ai_course_prompt` (Stage 6 design, decision #1): the prompt
 * is versioned through PromptVersionService instead of being a field
 * of the course form. A direct mirror of `GlobalPromptController`.
 */
class CoursePromptController extends Controller
{
    /**
     * The textarea is seeded from the `courses.ai_course_prompt` cache
     * column — the fast-read copy of the active version's body
     * maintained by PromptVersionService. The course is passed as a
     * plain id/title pair: the page needs no other course fields.
     */
    public function edit(Course $course): Response
    {
        $this->authorize('update', $course);

        return Inertia::render('Admin/Courses/Prompt', [
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
            ],
            'prompt' => $course->ai_course_prompt ?? '',
            'promptKey' => PromptKeys::forCourse($course->id),
        ]);
    }

    /**
     * Save the edited prompt as a brand-new version via
     * PromptVersionService (append-only history + course cache refresh
     * + audit log, all in one transaction).
     */
    public function update(AdminCoursePromptRequest $request, Course $course): RedirectResponse
    {
        $this->authorize('update', $course);

        $comment = $request->validated('comment');

        app(PromptVersionService::class)->createNewVersion(
            PromptKeys::forCourse($course->id),
            (string) $request->validated('body'),
            is_string($comment) ? $comment : null,
            $request->user(),
        );

        return redirect()
            ->route('admin.courses.prompt.edit', $course)
            ->with('status', 'Промпт курса обновлён — создана новая версия');
    }
}
