<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\CreatePracticeTask;
use App\Actions\Admin\DeletePracticeTask;
use App\Actions\Admin\UpdatePracticeTask;
use App\Enums\PracticeRuntime;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminPracticeTaskRequest;
use App\Models\Lesson;
use App\Models\PracticeTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Nested practice-task management: every ability resolves over the
 * course root of the task's lesson (`CoursePolicy@update`), mirroring
 * TheoryTaskController. Forms live on dedicated pages; the results land
 * back on the lesson edit page (`admin.lessons.edit`).
 */
class PracticeTaskController extends Controller
{
    public function create(Lesson $lesson): Response
    {
        $this->authorize('update', $lesson->level->course);

        return Inertia::render('Admin/PracticeTasks/Create', [
            'lesson' => $lesson->load('level'),
            'runtimeOptions' => PracticeRuntime::options(),
        ]);
    }

    /**
     * Create the task via the `CreatePracticeTask` Action (server-side
     * expected hash derivation, order auto-append, audited atomically).
     */
    public function store(AdminPracticeTaskRequest $request, Lesson $lesson): RedirectResponse
    {
        $this->authorize('update', $lesson->level->course);

        app(CreatePracticeTask::class)->execute($lesson, $request->validated(), $request->user());

        return redirect()
            ->route('admin.lessons.edit', $lesson)
            ->with('status', 'Практическое задание создано');
    }

    public function edit(PracticeTask $task): Response
    {
        $this->authorize('update', $task->lesson->level->course);

        return Inertia::render('Admin/PracticeTasks/Edit', [
            'task' => $task->load('lesson.level'),
            'runtimeOptions' => PracticeRuntime::options(),
        ]);
    }

    public function update(AdminPracticeTaskRequest $request, PracticeTask $task): RedirectResponse
    {
        $this->authorize('update', $task->lesson->level->course);

        app(UpdatePracticeTask::class)->execute($task, $request->validated(), $request->user());

        return redirect()
            ->route('admin.lessons.edit', $task->lesson)
            ->with('status', 'Практическое задание обновлено');
    }

    public function destroy(Request $request, PracticeTask $task): RedirectResponse
    {
        $lesson = $task->lesson;
        $this->authorize('update', $lesson->level->course);

        app(DeletePracticeTask::class)->execute($task, $request->user());

        return redirect()
            ->route('admin.lessons.edit', $lesson)
            ->with('status', 'Практическое задание удалено');
    }
}
