<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminAuditLogIndexRequest;
use App\Models\AdminAuditLog;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    /**
     * Paginated audit log with optional filters: `action`, `admin_id`,
     * and a `date_from` / `date_to` range over `created_at` (both bounds
     * include the whole day, see `AdminAuditLog::scopeFiltered`). The
     * `admin` relation is eager-loaded to avoid an N+1 when rendering
     * the "performed by" column.
     *
     * `latest('created_at')` is explicit because `AdminAuditLog`
     * disables `updated_at` (`$timestamps = false`), so the default
     * `latest()` would fall back to the primary key.
     */
    public function index(AdminAuditLogIndexRequest $request): Response
    {
        $this->authorize('viewAny', AdminAuditLog::class);

        $filters = $request->validated();

        $logs = AdminAuditLog::query()
            ->filtered(
                action: isset($filters['action']) ? AdminAuditAction::from((string) $filters['action']) : null,
                adminId: isset($filters['admin_id']) ? (int) $filters['admin_id'] : null,
                dateFrom: isset($filters['date_from']) ? (string) $filters['date_from'] : null,
                dateTo: isset($filters['date_to']) ? (string) $filters['date_to'] : null,
            )
            ->with('admin')
            ->latest('created_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/AuditLogs/Index', [
            'logs' => $logs,
            'filters' => $filters,
        ]);
    }

    public function show(AdminAuditLog $log): Response
    {
        $this->authorize('view', $log);
        $log->load('admin');

        return Inertia::render('Admin/AuditLogs/Show', ['log' => $log]);
    }
}
