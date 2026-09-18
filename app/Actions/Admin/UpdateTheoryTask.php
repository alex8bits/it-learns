<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Models\TheoryTask;
use App\Models\TheoryTaskOption;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Support\Facades\DB;

class UpdateTheoryTask
{
    public function __construct(private AdminAuditLogger $audit) {}

    /**
     * Update a theory task and replace its whole option set, audited
     * atomically as `TheoryTaskUpdated`.
     *
     * The option set is always replaced: every existing option row is
     * deleted and recreated from the payload (the edit form submits the
     * full set). The `option_id` FK of `user_theory_task_answers`
     * cascades, so users' answers to this task are reset — a deliberate
     * domain decision: a quiz with changed options must be re-answered.
     *
     * @param  array{question: string, order?: int, is_published?: bool, options: list<array{text: string, is_correct: bool, error_text?: string|null}>}  $attributes
     */
    public function execute(TheoryTask $task, array $attributes, User $actor): TheoryTask
    {
        return DB::transaction(function () use ($task, $attributes): TheoryTask {
            $payload = [
                'question' => (string) $attributes['question'],
            ];

            if (array_key_exists('order', $attributes)) {
                $payload['order'] = (int) $attributes['order'];
            }

            if (array_key_exists('is_published', $attributes)) {
                $payload['is_published'] = (bool) $attributes['is_published'];
            }

            $task->fill($payload)->save();

            $newOptions = [];
            $position = 0;

            foreach ($attributes['options'] as $option) {
                $position++;

                $newOptions[] = [
                    'text' => (string) $option['text'],
                    'is_correct' => (bool) $option['is_correct'],
                    'error_text' => array_key_exists('error_text', $option) ? $option['error_text'] : null,
                    'order' => $position,
                ];
            }

            $optionsChanged = $task->options
                ->map(static fn (TheoryTaskOption $option): array => [
                    'text' => $option->text,
                    'is_correct' => $option->is_correct,
                    'error_text' => $option->error_text,
                    'order' => $option->order,
                ])
                ->all() !== $newOptions;

            $task->options()->delete();

            foreach ($newOptions as $newOption) {
                TheoryTaskOption::create(['theory_task_id' => $task->id, ...$newOption]);
            }

            $this->audit->log(AdminAuditAction::TheoryTaskUpdated, $task, [
                'lesson_id' => $task->lesson_id,
                'course_id' => $task->lesson->level->course_id,
                'options_count' => count($newOptions),
                'options_changed' => $optionsChanged,
            ]);

            return $task;
        });
    }
}
