<?php

declare(strict_types=1);

namespace App\Http\Controllers\Courses;

use App\Actions\Progress\StartCourse;
use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Single-action controller: POST /courses/{course}/start (Stage 7).
 * One endpoint serves both «Начать курс» and «Продолжить»: StartCourse
 * resolves the current lesson (the first published lesson without a
 * Completed progress row) and the user is redirected straight to it.
 * The draft-course guard lives inside the Action (404).
 */
class CourseStartController extends Controller
{
    public function __invoke(Request $request, Course $course): RedirectResponse
    {
        $lesson = app(StartCourse::class)->execute($request->user(), $course);

        return redirect()->route('lessons.show', $lesson->slug);
    }
}
