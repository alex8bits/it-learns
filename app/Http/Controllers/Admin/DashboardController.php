<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminDashboardService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly AdminDashboardService $service) {}

    /**
     * Render the admin landing page with the headline counters.
     *
     * No `$this->authorize(...)` is invoked: the surrounding route group
     * already enforces the `role:admin` middleware, and `UserPolicy::viewAny`
     * is the redundant rule #7 trap the design calls out.
     */
    public function index(): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'counters' => $this->service->counters(),
        ]);
    }
}
