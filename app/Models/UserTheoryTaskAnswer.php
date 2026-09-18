<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserTheoryTaskAnswerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'theory_task_id', 'option_id', 'is_correct', 'answered_at'])]
class UserTheoryTaskAnswer extends Model
{
    /** @use HasFactory<UserTheoryTaskAnswerFactory> */
    use HasFactory;

    /**
     * Answers are an upserted latest state, not history: no
     * created_at/updated_at, only `answered_at`.
     */
    public $timestamps = false;

    /**
     * The user who answered the theory task.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The theory task the answer belongs to.
     *
     * @return BelongsTo<TheoryTask, $this>
     */
    public function theoryTask(): BelongsTo
    {
        return $this->belongsTo(TheoryTask::class);
    }

    /**
     * The option the user picked.
     *
     * @return BelongsTo<TheoryTaskOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(TheoryTaskOption::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_correct' => 'bool',
            'answered_at' => 'datetime',
        ];
    }
}
