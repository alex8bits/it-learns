<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AdminAuditAction;
use App\Models\Lesson;
use App\Models\TheoryTask;
use App\Models\TheoryTaskOption;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Support\Facades\DB;

class CreateTheoryTask
{
    public function __construct(private AdminAuditLogger $audit) {}

    /**
     * Create a theory task with its answer options in the lesson, audited
     * atomically as `TheoryTaskCreated`.
     *
     * The `order` defaults to `(max order within the lesson) + 1`, so
     * tasks append to the end of the lesson unless positioned explicitly.
     * Option order mirrors the payload sequence (1-based position counter,
     * payload keys are not trusted to be sequential).
     *
     * @param  array{question: string, order?: int, is_published?: bool, options: list<array{text: string, is_correct: bool, error_text?: string|null}>}  $attributes
     */
    public function execute(Lesson $lesson, array $attributes, User $actor): TheoryTask
    {
        return DB::transaction(function () use ($lesson, $attributes): TheoryTask {
            $order = array_key_exists('order', $attributes)
                ? (int) $attributes['order']
                : ((int) TheoryTask::query()->where('lesson_id', $lesson->id)->max('order')) + 1;

            $task = TheoryTask::create([
                'lesson_id' => $lesson->id,
                'question' => $attributes['question'],
                'order' => $order,
                'is_published' => array_key_exists('is_published', $attributes)
                    ? (bool) $attributes['is_published']
                    : false,
            ]);

            $position = 0;

            foreach ($attributes['options'] as $option) {
                $position++;

                TheoryTaskOption::create([
                    'theory_task_id' => $task->id,
                    'text' => (string) $option['text'],
                    'is_correct' => (bool) $option['is_correct'],
                    'error_text' => array_key_exists('error_text', $option) ? $option['error_text'] : null,
                    'order' => $position,
                ]);
            }

            $this->audit->log(AdminAuditAction::TheoryTaskCreated, $task, [
                'lesson_id' => $lesson->id,
                'course_id' => $lesson->level->course_id,
                'order' => $task->order,
                'options_count' => count($attributes['options']),
                'is_published' => $task->is_published,
            ]);

            return $task;
        });
    }
}
