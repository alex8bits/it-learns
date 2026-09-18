<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PracticeRuntime;
use App\Services\Practice\Dto\PracticeTaskInput;
use Database\Factories\PracticeTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property PracticeRuntime|null $runtime per-task practice runtime (Stage 10); the
 *                                         migration default backfills existing rows to sqlite
 */
#[Fillable(['lesson_id', 'statement', 'expected_result_text', 'expected_rows', 'seed_sql', 'expected_hash', 'order', 'is_published', 'runtime'])]
class PracticeTask extends Model
{
    /** @use HasFactory<PracticeTaskFactory> */
    use HasFactory;

    /**
     * The lesson the practice task belongs to.
     *
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Attempt history of the task (append-only submissions).
     *
     * @return HasMany<PracticeTaskSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(PracticeTaskSubmission::class);
    }

    /**
     * Assemble the practice environment input from the model (the
     * PHPDoc contract of PracticeTaskInput: Stage 8 builds it via a
     * thin wrapper without changing the manager contract). Pure
     * data-mapping, no business logic.
     */
    public function toInput(): PracticeTaskInput
    {
        return new PracticeTaskInput(
            taskId: $this->id,
            taskText: $this->statement,
            seedScript: $this->seed_sql,
            expectedHash: $this->expected_hash,
            runtime: $this->runtime,
        );
    }

    /**
     * Only published tasks (visible in the lesson flow).
     *
     * @param  Builder<PracticeTask>  $query
     * @return Builder<PracticeTask>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Order tasks by their `order` column.
     *
     * @param  Builder<PracticeTask>  $query
     * @return Builder<PracticeTask>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('order');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array{expected_rows: 'array', is_published: 'bool', runtime: class-string<PracticeRuntime>}
     */
    protected function casts(): array
    {
        return [
            'expected_rows' => 'array',
            'is_published' => 'bool',
            'runtime' => PracticeRuntime::class,
        ];
    }
}
