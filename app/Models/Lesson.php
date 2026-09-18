<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['level_id', 'slug', 'title', 'order', 'material', 'is_published'])]
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory;

    /**
     * The level the lesson belongs to.
     *
     * @return BelongsTo<Level, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /**
     * Theory tasks of the lesson, ordered by the `order` column.
     *
     * @return HasMany<TheoryTask, $this>
     */
    public function theoryTasks(): HasMany
    {
        return $this->hasMany(TheoryTask::class)->orderBy('order');
    }

    /**
     * Practice tasks of the lesson, ordered by the `order` column.
     *
     * @return HasMany<PracticeTask, $this>
     */
    public function practiceTasks(): HasMany
    {
        return $this->hasMany(PracticeTask::class)->orderBy('order');
    }

    /**
     * Per-user progress rows for the lesson.
     *
     * @return HasMany<UserLessonProgress, $this>
     */
    public function progresses(): HasMany
    {
        return $this->hasMany(UserLessonProgress::class);
    }

    /**
     * Only published lessons (public course card).
     *
     * @param  Builder<Lesson>  $query
     * @return Builder<Lesson>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Order lessons by their `order` column.
     *
     * @param  Builder<Lesson>  $query
     * @return Builder<Lesson>
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
