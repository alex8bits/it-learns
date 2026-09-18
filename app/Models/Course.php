<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CourseStatus;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['slug', 'title', 'description', 'preview_image_path', 'status', 'sort_order', 'ai_course_prompt', 'created_by'])]
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    /**
     * Computed attributes appended to every array/JSON serialization.
     *
     * @var list<string>
     */
    protected $appends = ['preview_image_url'];

    /**
     * Levels of the course, ordered by the `order` column (Beginner to
     * Advanced by convention, `order` mirrors `Level::defaultOrder()`).
     *
     * @return HasMany<Level, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(Level::class)->orderBy('order');
    }

    /**
     * Administrator that created the course (nullable: legacy/seeded
     * courses may have no creator).
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Only published courses (public catalog and course card).
     *
     * @param  Builder<Course>  $query
     * @return Builder<Course>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', CourseStatus::Published);
    }

    /**
     * Catalog ordering: manual `sort_order` first (ascending — smaller
     * values sit higher in the catalog), newest courses as the
     * tie-breaker. The default `sort_order = 0` keeps the legacy
     * newest-first behaviour for courses without a manual order.
     *
     * @param  Builder<Course>  $query
     * @return Builder<Course>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderByDesc('created_at');
    }

    /**
     * Public URL of the preview image stored on the `public` disk, or
     * null when the course has no preview. Appended as
     * `preview_image_url` so resources and Vue pages receive a ready
     * URL instead of the raw storage path.
     *
     * @return Attribute<string|null, never>
     */
    protected function previewImageUrl(): Attribute
    {
        // Constructed via `new Attribute(get: ...)` rather than
        // `Attribute::get(...)`: PHPStan cannot prove the invariant
        // template types of the static factory's return against the
        // docblock, while the constructor form type-checks cleanly.
        return new Attribute(get: function (): ?string {
            $path = $this->preview_image_path;

            return $path === null ? null : Storage::disk('public')->url($path);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CourseStatus::class,
            'sort_order' => 'integer',
        ];
    }
}
