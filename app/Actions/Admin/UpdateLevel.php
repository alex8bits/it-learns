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

class UpdateLevel
{
    public function __construct(private AdminAuditLogger $audit) {}

    /**
     * Update a level's mutable fields — `title` and `order` — audited
     * atomically as `LevelUpdated`. A new title colliding with another
     * level of the same course is rejected by an explicit pre-check with
     * a readable message instead of a raw duplicate-index failure.
     *
     * @param  array{title?: string, order?: int}  $attributes
     *
     * @throws RuntimeException
     */
    public function execute(Level $level, array $attributes, User $actor): Level
    {
        return DB::transaction(function () use ($level, $attributes): Level {
            $payload = [];

            if (array_key_exists('title', $attributes)) {
                $title = (string) $attributes['title'];

                $collision = Level::query()
                    ->where('course_id', $level->course_id)
                    ->where('title', $title)
                    ->where('id', '!=', $level->id)
                    ->exists();

                if ($collision) {
                    $courseTitle = (string) Course::query()
                        ->whereKey($level->course_id)
                        ->value('title');

                    throw new RuntimeException(
                        "Уровень «{$title}» уже есть в курсе «{$courseTitle}».",
                    );
                }

                $payload['title'] = $title;
            }

            if (array_key_exists('order', $attributes)) {
                $payload['order'] = (int) $attributes['order'];
            }

            $level->fill($payload)->save();

            $this->audit->log(AdminAuditAction::LevelUpdated, $level, [
                'course_id' => $level->course_id,
                'title' => $level->title,
                'order' => $level->order,
            ]);

            return $level;
        });
    }
}
