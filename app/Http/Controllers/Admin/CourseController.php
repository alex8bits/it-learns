<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\CreateCourse;
use App\Actions\Admin\DeleteCourse;
use App\Actions\Admin\UpdateCourse;
use App\Enums\CourseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminCourseIndexRequest;
use App\Http\Requests\Admin\AdminCreateCourseRequest;
use App\Http\Requests\Admin\AdminUpdateCourseRequest;
use App\Models\Course;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CourseController extends Controller
{
    /**
     * Paginated admin course list with a `levels_count` column for the
     * table (eager counting instead of an N+1 per row) and an optional
     * status filter. Ordered through `Course::scopeOrdered` so the admin
     * table mirrors the public catalog order (`sort_order`, then newest
     * first). The enum option list and the active filter travel
     * alongside so the Vue page renders Russian labels and re-seeds the
     * filter select without duplicating the enum values in JS constants.
     */
    public function index(AdminCourseIndexRequest $request): Response
    {
        $this->authorize('viewAny', Course::class);

        $status = $request->validated('status');

        $courses = Course::query()
            ->withCount('levels')
            ->when(
                $status !== null,
                fn ($query) => $query->where('status', (string) $status),
            )
            ->ordered()
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Courses/Index', [
            'courses' => $courses,
            'statuses' => CourseStatus::options(),
            'filters' => ['status' => $status],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Course::class);

        return Inertia::render('Admin/Courses/Create', [
            'statuses' => CourseStatus::options(),
        ]);
    }

    /**
     * Create the course through the `CreateCourse` Action: slug
     * generation, preview processing and the optional first prompt
     * version land in one transaction with the audit entry (rule #17).
     * Redirects to the course card — the workspace for managing
     * levels and lessons (consistent with `update()`).
     */
    public function store(AdminCreateCourseRequest $request): RedirectResponse
    {
        $this->authorize('create', Course::class);

        $course = app(CreateCourse::class)->execute(
            $request->validated(),
            $request->file('preview_image'),
            $request->user(),
        );

        return redirect()
            ->route('admin.courses.show', $course)
            ->with('status', 'Курс создан');
    }

    /**
     * Course card with the full level → lesson tree and the creator
     * eager-loaded (`levels.lessons`, `creator`), the admin workspace for
     * managing both. The status option list travels alongside so the Vue
     * page renders Russian labels without duplicating the enum values in
     * JS constants.
     *
     * The `creator` relation is reduced to `id`/`name` and unset from the
     * course before rendering: Eloquent would otherwise serialize the
     * whole `User` model inside the `course` prop (its hidden list only
     * covers password/remember_token, so the creator email and
     * timestamps would leak into the page payload).
     */
    public function show(Course $course): Response
    {
        $this->authorize('view', $course);
        $course->load(['levels.lessons', 'creator']);

        $creator = $course->creator?->only(['id', 'name']);
        $course->unsetRelation('creator');

        return Inertia::render('Admin/Courses/Show', [
            'course' => $course,
            'statuses' => CourseStatus::options(),
            'creator' => $creator,
        ]);
    }

    public function edit(Course $course): Response
    {
        $this->authorize('update', $course);

        return Inertia::render('Admin/Courses/Edit', [
            'course' => $course,
            'statuses' => CourseStatus::options(),
        ]);
    }

    /**
     * Update the scalar fields and the optional preview replacement
     * through the `UpdateCourse` Action (status-transition-aware audit
     * entries, old preview file deleted after commit).
     */
    public function update(AdminUpdateCourseRequest $request, Course $course): RedirectResponse
    {
        $this->authorize('update', $course);

        app(UpdateCourse::class)->execute(
            $course,
            $request->validated(),
            $request->file('preview_image'),
            $request->user(),
        );

        return redirect()
            ->route('admin.courses.show', $course)
            ->with('status', 'Курс обновлён');
    }

    public function destroy(Request $request, Course $course): RedirectResponse
    {
        $this->authorize('delete', $course);

        app(DeleteCourse::class)->execute($course, $request->user());

        return redirect()
            ->route('admin.courses.index')
            ->with('status', 'Курс удалён');
    }
}
