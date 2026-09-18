<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Models\PracticeTask;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use App\Services\Practice\CanonicalResultSerializer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdatePracticeTask
{
    public function __construct(
        private CanonicalResultSerializer $serializer,
        private AdminAuditLogger $audit,
    ) {}

    /**
     * Update a practice task, audited atomically as
     * `PracticeTaskUpdated`.
     *
     * Only the keys present in the payload are filled (the
     * `array_key_exists` pattern of the theory-task actions), so absent
     * optional fields keep their current values. When `expected_rows`
     * is present the `expected_hash` is recomputed server-side from
     * the rows — the client never supplies the hash. `runtime` (Stage
     * 10) follows the same optional-key pattern for direct Action
     * callers; the admin FormRequest always sends it.
     *
     * @param  array{statement?: string, expected_result_text?: string, expected_rows?: string, runtime?: string, seed_sql?: string|null, order?: int|null, is_published?: bool}  $attributes
     */
    public function execute(PracticeTask $task, array $attributes, User $actor): PracticeTask
    {
        return DB::transaction(function () use ($task, $attributes): PracticeTask {
            $payload = [];

            if (array_key_exists('statement', $attributes)) {
                $payload['statement'] = (string) $attributes['statement'];
            }

            if (array_key_exists('expected_result_text', $attributes)) {
                $payload['expected_result_text'] = (string) $attributes['expected_result_text'];
            }

            if (array_key_exists('seed_sql', $attributes)) {
                $payload['seed_sql'] = $attributes['seed_sql'];
            }

            if (array_key_exists('order', $attributes)) {
                $payload['order'] = (int) $attributes['order'];
            }

            if (array_key_exists('is_published', $attributes)) {
                $payload['is_published'] = (bool) $attributes['is_published'];
            }

            if (array_key_exists('runtime', $attributes)) {
                $payload['runtime'] = $attributes['runtime'];
            }

            if (array_key_exists('expected_rows', $attributes)) {
                /** @var list<array<string, mixed>> $rows */
                $rows = json_decode((string) $attributes['expected_rows'], true);
                $columns = array_keys($rows[0]);

                $payload['expected_rows'] = $rows;
                $payload['expected_hash'] = $this->serializer->hash($rows, $columns);
            }

            $task->fill($payload)->save();

            $this->audit->log(AdminAuditAction::PracticeTaskUpdated, $task, [
                'lesson_id' => $task->lesson_id,
                'course_id' => $task->lesson->level->course_id,
                'order' => $task->order,
                'is_published' => $task->is_published,
                'runtime' => $task->runtime?->value,
                'statement_preview' => Str::limit($task->statement, 80),
            ]);

            return $task;
        });
    }
}
