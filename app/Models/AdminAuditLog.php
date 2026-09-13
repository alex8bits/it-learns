<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminAuditAction;
use Database\Factories\AdminAuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

#[Fillable(['admin_id', 'action', 'subject_type', 'subject_id', 'meta', 'ip', 'user_agent'])]
class AdminAuditLog extends Model
{
    /** @use HasFactory<AdminAuditLogFactory> */
    use HasFactory;

    /**
     * Audit log is append-only: no updated_at, only created_at.
     */
    public $timestamps = false;

    /**
     * Apply the audit-log index filters (validated by
     * `AdminAuditLogIndexRequest`, passed as-is).
     *
     * Date semantics: `dateFrom` includes the whole starting day
     * (`>= dateFrom 00:00:00`) and `dateTo` includes the whole ending day
     * (`<= dateTo 23:59:59`), so a bare `Y-m-d` date does not cut off
     * entries written later that day. `whereDate()` is deliberately avoided:
     * it wraps `created_at` in a function and defeats the index on it.
     *
     * @param  Builder<AdminAuditLog>  $query
     * @return Builder<AdminAuditLog>
     */
    public function scopeFiltered(
        Builder $query,
        ?AdminAuditAction $action = null,
        ?int $adminId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): Builder {
        if ($action !== null) {
            $query->where('action', $action->value);
        }

        if ($adminId !== null) {
            $query->where('admin_id', $adminId);
        }

        if ($dateFrom !== null) {
            $query->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
        }

        if ($dateTo !== null) {
            $query->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());
        }

        return $query;
    }

    /**
     * Administrator that performed the audited action.
     *
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * The subject (target) of the audited action.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'action' => AdminAuditAction::class,
            'created_at' => 'datetime',
        ];
    }
}
