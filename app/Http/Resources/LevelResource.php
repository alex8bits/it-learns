<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Level;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public level shape of the course catalog API. Lessons are included
 * only when the relation is eager-loaded (`whenLoaded`) — the detail
 * endpoint loads them, list endpoints do not (rule #9: eager loading,
 * the resource never triggers a lazy load).
 *
 * @mixin Level
 *
 * @param  Level  $resource
 */
class LevelResource extends JsonResource
{
    /**
     * No `data` wrapper: the top-level shape is the payload itself
     * (the same per-class shadowing as PracticeSubmissionResource).
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array{id: int, title: string, order: int, lessons?: AnonymousResourceCollection}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'order' => $this->order,
            'lessons' => $this->whenLoaded(
                'lessons',
                fn (): AnonymousResourceCollection => LessonResource::collection($this->lessons),
            ),
        ];
    }
}
