<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PracticeAttemptStatus;
use Database\Factories\PracticeTaskSubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'practice_task_id', 'code', 'status', 'result_diff', 'error_text', 'duration_ms', 'created_at'])]
class PracticeTaskSubmission extends Model
{
    /** @use HasFactory<PracticeTaskSubmissionFactory> */
    use HasFactory;

    /**
     * Attempts are an append-only history, not an upserted latest
     * state: no updated_at, only `created_at` (unlike theory answers).
     */
    public $timestamps = false;

    /**
     * The user who made the attempt.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The practice task the attempt belongs to.
     *
     * @return BelongsTo<PracticeTask, $this>
     */
    public function practiceTask(): BelongsTo
    {
        return $this->belongsTo(PracticeTask::class);
    }

    /**
     * Premium AI feedback generated for this (unsuccessful) attempt.
     *
     * @return HasMany<PracticeTaskFeedback, $this>
     */
    public function feedbacks(): HasMany
    {
        return $this->hasMany(PracticeTaskFeedback::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PracticeAttemptStatus::class,
            'result_diff' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
