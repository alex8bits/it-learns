<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\CreateTheoryTask;
use App\Actions\Admin\DeleteTheoryTask;
use App\Actions\Admin\UpdateTheoryTask;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminTheoryTaskRequest;
use App\Models\Lesson;
use App\Models\TheoryTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Nested theory-task management: every ability resolves over the course
 * root of the task's lesson (`CoursePolicy@update`), mirroring
 * LessonController. Forms live on dedicated pages; the results land back
 * on the lesson edit page (`admin.lessons.edit`).
 */
class TheoryTaskController extends Controller
{
    public function create(Lesson $lesson): Response
    {
        $this->authorize('update', $lesson->level->course);

        return Inertia::render('Admin/TheoryTasks/Create', [
            'lesson' => $lesson->load('level'),
        ]);
    }

    /**
     * Create the task with its options via the `CreateTheoryTask` Action
     * (order auto-append, audited atomically).
     */
    public function store(AdminTheoryTaskRequest $request, Lesson $lesson): RedirectResponse
    {
        $this->authorize('update', $lesson->level->course);

        app(CreateTheoryTask::class)->execute($lesson, $request->validated(), $request->user());

        return redirect()
            ->route('admin.lessons.edit', $lesson)
            ->with('status', 'Теоретическое задание создано');
    }

    public function edit(TheoryTask $task): Response
    {
        $this->authorize('update', $task->lesson->level->course);

        return Inertia::render('Admin/TheoryTasks/Edit', [
            'task' => $task->load(['options', 'lesson.level']),
        ]);
    }

    public function update(AdminTheoryTaskRequest $request, TheoryTask $task): RedirectResponse
    {
        $this->authorize('update', $task->lesson->level->course);

        app(UpdateTheoryTask::class)->execute($task, $request->validated(), $request->user());

        return redirect()
            ->route('admin.lessons.edit', $task->lesson)
            ->with('status', 'Теоретическое задание обновлено');
    }

    public function destroy(Request $request, TheoryTask $task): RedirectResponse
    {
        $lesson = $task->lesson;
        $this->authorize('update', $lesson->level->course);

        app(DeleteTheoryTask::class)->execute($task, $request->user());

        return redirect()
            ->route('admin.lessons.edit', $lesson)
            ->with('status', 'Теоретическое задание удалено');
    }
}
