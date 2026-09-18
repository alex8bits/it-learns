<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\CreateLevel;
use App\Actions\Admin\DeleteLevel;
use App\Actions\Admin\UpdateLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminLevelRequest;
use App\Http\Requests\Admin\AdminUpdateLevelRequest;
use App\Models\Course;
use App\Models\Level;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Nested level management: every ability resolves over the parent
 * course (`CoursePolicy@update`) — levels have no policy of their own.
 * All pages live on the course card (`admin.courses.show`), so there
 * is no index route.
 */
class LevelController extends Controller
{
    /**
     * Attach a difficulty level to the course via the `CreateLevel`
     * Action (duplicate-guarded, audited atomically).
     */
    public function store(AdminLevelRequest $request, Course $course): RedirectResponse
    {
        $this->authorize('update', $course);

        app(CreateLevel::class)->execute($course, $request->validated(), $request->user());

        return redirect()
            ->route('admin.courses.show', $course)
            ->with('status', 'Уровень добавлен к курсу');
    }

    public function update(AdminUpdateLevelRequest $request, Level $level): RedirectResponse
    {
        $course = $level->course;
        $this->authorize('update', $course);

        app(UpdateLevel::class)->execute($level, $request->validated(), $request->user());

        return redirect()
            ->route('admin.courses.show', $course)
            ->with('status', 'Уровень обновлён');
    }

    public function destroy(Request $request, Level $level): RedirectResponse
    {
        $course = $level->course;
        $this->authorize('update', $course);

        app(DeleteLevel::class)->execute($level, $request->user());

        return redirect()
            ->route('admin.courses.show', $course)
            ->with('status', 'Уровень удалён');
    }
}
