<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stage 2 placeholder. Real course management lands in Stage 5+.
 *
 * Like `PaymentController`, no `$this->authorize(...)` is called:
 * `CoursePolicy` is a stub returning `false` (T03), so invoking it
 * would 403 the admin on a page that should render the placeholder.
 * The `role:admin` middleware already guarantees the right audience.
 */
class CourseController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Courses/Index', [
            'stage' => 5,
        ]);
    }

    public function show(int $course): Response
    {
        return Inertia::render('Admin/Courses/Show', [
            'stage' => 5,
            'courseId' => $course,
        ]);
    }
}
