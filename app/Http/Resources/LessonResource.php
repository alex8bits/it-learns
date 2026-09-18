<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public lesson shape of the course catalog API: catalog metadata only.
 *
 * Contract: the `material` field is included ONLY by the detail endpoint
 * (`GET /api/v1/courses/{slug}`) — the list endpoints never ship it
 * (docs/platform-plan.md, Stage 6: «без material в списке, с material в
 * show»). The render mode is pinned by the API entry point before the
 * controller returns: Api\V1\CourseController::show sets
 * {@see LessonResource::$withMaterial} to true, index() resets it to
 * false, so each request fixes its own mode and an FPM worker's history
 * never leaks material into later responses. The resource does not
 * reset the flag itself — serialization happens after the controller
 * returns, so the mode is owned by the entry points, not by renders.
 *
 * @mixin Lesson
 *
 * @param  Lesson  $resource
 */
class LessonResource extends JsonResource
{
    /**
     * No `data` wrapper: the top-level shape is the payload itself
     * (the same per-class shadowing as PracticeSubmissionResource).
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Whether `material` is exposed. The mode is set by the API entry
     * point on every request (show → true, index → false) before the
     * response is built; the resource never resets it — no reset is
     * needed because each entry point pins its own mode.
     */
    public static bool $withMaterial = false;

    /**
     * @return array{id: int, slug: string, title: string, order: int, is_published: bool, material?: string|null}
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'order' => $this->order,
            'is_published' => $this->is_published,
        ];

        if (static::$withMaterial === true) {
            $data['material'] = $this->material;
        }

        return $data;
    }
}
