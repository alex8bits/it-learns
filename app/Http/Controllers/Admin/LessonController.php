<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\CreateLesson;
use App\Actions\Admin\DeleteLesson;
use App\Actions\Admin\UpdateLesson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminLessonRequest;
use App\Models\Lesson;
use App\Models\Level;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Nested lesson management: every ability resolves over the parent
 * course of the lesson's level (`CoursePolicy@update`). Forms live on
 * dedicated pages; the results land back on the course card
 * (`admin.courses.show`).
 */
class LessonController extends Controller
{
    public function create(Level $level): Response
    {
        $this->authorize('update', $level->course);

        return Inertia::render('Admin/Lessons/Create', [
            'level' => $level,
        ]);
    }

    /**
     * Create the lesson via the `CreateLesson` Action (slug generation,
     * order auto-append, audited atomically).
     */
    public function store(AdminLessonRequest $request, Level $level): RedirectResponse
    {
        $this->authorize('update', $level->course);

        app(CreateLesson::class)->execute($level, $request->validated(), $request->user());

        return redirect()
            ->route('admin.courses.show', $level->course)
            ->with('status', 'Урок создан');
    }

    public function edit(Lesson $lesson): Response
    {
        $this->authorize('update', $lesson->level->course);

        // `theoryTasks.options` feeds the theory list section on the edit
        // page (order, question, options count, publish badge);
        // `practiceTasks` feeds the practice list section (order,
        // statement, publish badge). Ordered by the relations, without a
        // published filter — admins manage drafts here too.
        return Inertia::render('Admin/Lessons/Edit', [
            'lesson' => $lesson->load(['theoryTasks.options', 'practiceTasks']),
        ]);
    }

    public function update(AdminLessonRequest $request, Lesson $lesson): RedirectResponse
    {
        $this->authorize('update', $lesson->level->course);

        app(UpdateLesson::class)->execute($lesson, $request->validated(), $request->user());

        return redirect()
            ->route('admin.courses.show', $lesson->level->course)
            ->with('status', 'Урок обновлён');
    }

    public function destroy(Request $request, Lesson $lesson): RedirectResponse
    {
        $course = $lesson->level->course;
        $this->authorize('update', $course);

        app(DeleteLesson::class)->execute($lesson, $request->user());

        return redirect()
            ->route('admin.courses.show', $course)
            ->with('status', 'Урок удалён');
    }
}
