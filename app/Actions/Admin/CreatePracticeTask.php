<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Models\Lesson;
use App\Models\PracticeTask;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use App\Services\Practice\CanonicalResultSerializer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreatePracticeTask
{
    public function __construct(
        private CanonicalResultSerializer $serializer,
        private AdminAuditLogger $audit,
    ) {}

    /**
     * Create a practice task in the lesson, audited atomically as
     * `PracticeTaskCreated`.
     *
     * The `order` defaults to `(max order within the lesson) + 1`, so
     * tasks append to the end of the lesson unless positioned
     * explicitly. `expected_hash` is derived server-side from
     * `expected_rows` (canonical serializer, columns from the first
     * row) — the client never supplies it. `runtime` is the per-task
     * execution engine (Stage 10) — the model cast accepts the string
     * enum value straight from the validated payload.
     *
     * @param  array{statement: string, expected_result_text: string, expected_rows: string, runtime: string, seed_sql?: string|null, order?: int|null, is_published?: bool}  $attributes
     */
    public function execute(Lesson $lesson, array $attributes, User $actor): PracticeTask
    {
        return DB::transaction(function () use ($lesson, $attributes): PracticeTask {
            $order = $attributes['order']
                ?? ((int) PracticeTask::query()->where('lesson_id', $lesson->id)->max('order')) + 1;

            /** @var list<array<string, mixed>> $rows */
            $rows = json_decode((string) $attributes['expected_rows'], true);
            $columns = array_keys($rows[0]);

            $task = PracticeTask::create([
                'lesson_id' => $lesson->id,
                'statement' => $attributes['statement'],
                'expected_result_text' => $attributes['expected_result_text'],
                'expected_rows' => $rows,
                'seed_sql' => array_key_exists('seed_sql', $attributes) ? $attributes['seed_sql'] : null,
                'expected_hash' => $this->serializer->hash($rows, $columns),
                'order' => $order,
                'is_published' => array_key_exists('is_published', $attributes)
                    ? (bool) $attributes['is_published']
                    : false,
                'runtime' => $attributes['runtime'],
            ]);

            $this->audit->log(AdminAuditAction::PracticeTaskCreated, $task, [
                'lesson_id' => $lesson->id,
                'course_id' => $lesson->level->course_id,
                'order' => $task->order,
                'is_published' => $task->is_published,
                'runtime' => $attributes['runtime'],
                'statement_preview' => Str::limit($task->statement, 80),
            ]);

            return $task;
        });
    }
}
