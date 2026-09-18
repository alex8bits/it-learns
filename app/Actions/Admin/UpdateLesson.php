<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Support\Facades\DB;

class UpdateLesson
{
    public function __construct(private AdminAuditLogger $audit) {}

    /**
     * Update a lesson's mutable fields, audited atomically as
     * `LessonUpdated` with the publish-state transition in the meta.
     * The slug is immutable and ignored even if present in the
     * attributes.
     *
     * @param  array{title?: string, material?: string|null, order?: int, is_published?: bool}  $attributes
     */
    public function execute(Lesson $lesson, array $attributes, User $actor): Lesson
    {
        $wasPublished = $lesson->is_published;

        return DB::transaction(function () use ($lesson, $attributes, $wasPublished): Lesson {
            $payload = [];

            if (array_key_exists('title', $attributes)) {
                $payload['title'] = (string) $attributes['title'];
            }

            if (array_key_exists('material', $attributes)) {
                $payload['material'] = $attributes['material'] === null ? null : (string) $attributes['material'];
            }

            if (array_key_exists('order', $attributes)) {
                $payload['order'] = (int) $attributes['order'];
            }

            if (array_key_exists('is_published', $attributes)) {
                $payload['is_published'] = (bool) $attributes['is_published'];
            }

            $lesson->fill($payload)->save();

            $this->audit->log(AdminAuditAction::LessonUpdated, $lesson, [
                'slug' => $lesson->slug,
                'is_published_old' => $wasPublished,
                'is_published_new' => $lesson->is_published,
            ]);

            return $lesson;
        });
    }
}
