<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Courses\CourseCatalog;
use App\Services\Progress\CourseProgress;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * User dashboard (личный кабинет, the post-login landing): greeting
 * plus the published course catalog, every card carrying the user's
 * progress percent — the same percent the course card shows
 * (`/courses/{slug}`), because both go through CourseProgress. The
 * percents are computed on read in one batch (no N+1), not stored.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly CourseCatalog $catalog,
        private readonly CourseProgress $progress,
    ) {}

    public function __invoke(Request $request): Response
    {
        $courses = $this->catalog->paginate();

        return Inertia::render('Dashboard', [
            'user' => $request->user()->only(['id', 'name', 'email']),
            'courses' => $courses,
            'progressPercents' => $this->progress->percentByCourse(
                $request->user()->id,
                $courses->getCollection(),
            ),
        ]);
    }
}
