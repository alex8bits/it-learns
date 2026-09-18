<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TheoryTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['lesson_id', 'question', 'order', 'is_published'])]
class TheoryTask extends Model
{
    /** @use HasFactory<TheoryTaskFactory> */
    use HasFactory;

    /**
     * The lesson the theory task belongs to.
     *
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Answer options of the task, ordered by the `order` column.
     *
     * @return HasMany<TheoryTaskOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(TheoryTaskOption::class)->orderBy('order');
    }

    /**
     * Only published tasks (visible in the lesson flow).
     *
     * @param  Builder<TheoryTask>  $query
     * @return Builder<TheoryTask>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Order tasks by their `order` column.
     *
     * @param  Builder<TheoryTask>  $query
     * @return Builder<TheoryTask>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('order');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_published' => 'bool',
        ];
    }
}
