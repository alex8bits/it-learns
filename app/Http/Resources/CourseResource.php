<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\CourseStatus;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public course shape of the catalog API. Levels are included only when
 * the relation is eager-loaded (`whenLoaded`) — the detail endpoint loads
 * them, the paginated list does not (rule #9: eager loading, the resource
 * never triggers a lazy load). `levels_count` appears only when the
 * caller ran `withCount('levels')` (the list endpoint).
 *
 * @property CourseStatus $status
 *
 * @mixin Course
 *
 * @param  Course  $resource
 */
class CourseResource extends JsonResource
{
    /**
     * No `data` wrapper: the top-level shape is the payload itself
     * (the same per-class shadowing as PracticeSubmissionResource;
     * Laravel's pagination wrapper on collections is unaffected).
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array{id: int, slug: string, title: string, description: string, status: string, preview_image_url: string|null, created_at: string|null, levels_count?: int, levels?: AnonymousResourceCollection}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'preview_image_url' => $this->preview_image_url,
            'created_at' => $this->created_at?->toIso8601String(),
            'levels_count' => $this->whenCounted('levels'),
            'levels' => $this->whenLoaded(
                'levels',
                fn (): AnonymousResourceCollection => LevelResource::collection($this->levels),
            ),
        ];
    }
}
