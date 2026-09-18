<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use App\Services\Courses\UniqueSlugGenerator;
use Illuminate\Support\Facades\DB;

class CreateLesson
{
    public function __construct(
        private AdminAuditLogger $audit,
        private UniqueSlugGenerator $slugGenerator,
    ) {}

    /**
     * Create a lesson in the level with a globally unique slug and an
     * optional study material, audited atomically as `LessonCreated`.
     *
     * The `order` defaults to `(max order within the level) + 1`, so
     * lessons append to the end of the level unless positioned
     * explicitly.
     *
     * @param  array{title: string, material?: string|null, order?: int, is_published?: bool}  $attributes
     */
    public function execute(Level $level, array $attributes, User $actor): Lesson
    {
        return DB::transaction(function () use ($level, $attributes): Lesson {
            $order = array_key_exists('order', $attributes)
                ? (int) $attributes['order']
                : ((int) Lesson::query()->where('level_id', $level->id)->max('order')) + 1;

            $lesson = Lesson::create([
                'level_id' => $level->id,
                'slug' => $this->slugGenerator->generate((string) $attributes['title'], Lesson::class),
                'title' => $attributes['title'],
                'order' => $order,
                'material' => array_key_exists('material', $attributes) ? $attributes['material'] : null,
                'is_published' => array_key_exists('is_published', $attributes)
                    ? (bool) $attributes['is_published']
                    : false,
            ]);

            $this->audit->log(AdminAuditAction::LessonCreated, $lesson, [
                'level_id' => $level->id,
                'course_id' => $level->course_id,
                'slug' => $lesson->slug,
                'order' => $lesson->order,
                'is_published' => $lesson->is_published,
            ]);

            return $lesson;
        });
    }
}
