<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminAuditAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['admin_id', 'action', 'subject_type', 'subject_id', 'meta', 'ip', 'user_agent'])]
class AdminAuditLog extends Model
{
    /**
     * Audit log is append-only: no updated_at, only created_at.
     */
    public $timestamps = false;

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
