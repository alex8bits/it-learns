<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\AdminAuditAction;
use App\Models\AdminAuditLog;
use Illuminate\Database\Eloquent\Model;

class AdminAuditLogger
{
    /**
     * Record an administrative action.
     *
     * Pulls `admin_id` from the current auth context and `ip` / `user_agent`
     * from the active request when available. Does not open its own database
     * transaction — callers (Action classes) wrap this in `DB::transaction()`
     * to keep the audit row atomic with the mutation it describes.
     *
     * @param  array<string, mixed>  $meta
     */
    public function log(AdminAuditAction $action, ?Model $subject, array $meta = []): AdminAuditLog
    {
        $request = request();

        return AdminAuditLog::create([
            'admin_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'meta' => $meta,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
