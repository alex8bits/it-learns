<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PracticeTaskFeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['practice_task_submission_id', 'user_id', 'body', 'created_at'])]
class PracticeTaskFeedback extends Model
{
    /** @use HasFactory<PracticeTaskFeedbackFactory> */
    use HasFactory;

    /**
     * AI feedback is append-only: no updated_at, only `created_at`.
     */
    public $timestamps = false;

    /**
     * Explicit table name: "feedback" is uncountable, so the convention
     * guess would resolve to `practice_task_feedback`.
     */
    protected $table = 'practice_task_feedbacks';

    /**
     * The unsuccessful attempt the feedback was generated for. The FK is
     * explicit: the convention would derive `submission_id` from the
     * method name (the `admin()` pattern of AdminAuditLog).
     *
     * @return BelongsTo<PracticeTaskSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(PracticeTaskSubmission::class, 'practice_task_submission_id');
    }

    /**
     * The premium user the feedback belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
