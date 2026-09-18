<?php

declare(strict_types=1);

namespace App\Http\Controllers\Courses;

use App\Http\Controllers\Controller;
use App\Services\Courses\CourseCatalog;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public course catalog (Stage 6): the list of published courses shown
 * on `/` and `/courses`. Guest-accessible — no auth middleware. Only
 * published courses are visible; unpublished ones are filtered by the
 * `published` scope, not by a policy (guests have no user to authorize).
 */
class CatalogController extends Controller
{
    public function __construct(private readonly CourseCatalog $catalog) {}

    /**
     * Catalog of published courses, newest first, 12 per page — the
     * shared `CourseCatalog` query (also backs the user dashboard).
     */
    public function index(): Response
    {
        return Inertia::render('Courses/Index', [
            'courses' => $this->catalog->paginate(),
        ]);
    }
}
