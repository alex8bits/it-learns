<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Models\Course;
use App\Models\Level;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateLevel
{
    public function __construct(private AdminAuditLogger $audit) {}

    /**
     * Add a level to the course, audited atomically as `LevelCreated`.
     *
     * The (course_id, title) pair is unique in the schema; the explicit
     * pre-check turns the collision into a RuntimeException with a
     * readable message instead of a raw duplicate-index failure. The
     * `order` defaults to the next free position in the course
     * (`max(order) + 1`, 1 for an empty course).
     *
     * @param  array{title: string, order?: int}  $attributes
     *
     * @throws RuntimeException
     */
    public function execute(Course $course, array $attributes, User $actor): Level
    {
        return DB::transaction(function () use ($course, $attributes): Level {
            $title = (string) $attributes['title'];

            $exists = Level::query()
                ->where('course_id', $course->id)
                ->where('title', $title)
                ->exists();

            if ($exists) {
                throw new RuntimeException(
                    "Уровень «{$title}» уже есть в курсе «{$course->title}».",
                );
            }

            $level = Level::create([
                'course_id' => $course->id,
                'title' => $title,
                'order' => array_key_exists('order', $attributes)
                    ? (int) $attributes['order']
                    : ((int) Level::query()->where('course_id', $course->id)->max('order')) + 1,
            ]);

            $this->audit->log(AdminAuditAction::LevelCreated, $level, [
                'course_id' => $course->id,
                'title' => $level->title,
                'order' => $level->order,
            ]);

            return $level;
        });
    }
}
