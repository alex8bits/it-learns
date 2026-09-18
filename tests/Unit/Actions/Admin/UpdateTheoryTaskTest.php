<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\UpdateTheoryTask;
use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\TheoryTask;
use App\Models\User;
use App\Models\UserTheoryTaskAnswer;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UpdateTheoryTaskTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_updates_fields_replaces_options_and_audits(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $task = TheoryTask::factory()->withOptions()->create([
            'question' => 'Старый вопрос',
            'order' => 2,
            'is_published' => false,
        ]);
        $oldOptionIds = $task->options->pluck('id')->all();
        $lessonId = $task->lesson_id;
        $courseId = $task->lesson->level->course_id;

        $task = app(UpdateTheoryTask::class)->execute($task, [
            'question' => 'Новый вопрос',
            'order' => 9,
            'is_published' => true,
            'options' => [
                ['text' => 'Верный ответ', 'is_correct' => true],
                ['text' => 'Неверный ответ', 'is_correct' => false, 'error_text' => 'Не тот синтаксис.'],
            ],
        ], $admin);

        $this->assertSame('Новый вопрос', $task->question);
        $this->assertSame(9, $task->order);
        $this->assertTrue($task->is_published);
        $this->assertDatabaseHas('theory_tasks', [
            'id' => $task->id,
            'question' => 'Новый вопрос',
            'order' => 9,
            'is_published' => true,
        ]);

        // The option set is replaced, not patched: old ids are gone.
        foreach ($oldOptionIds as $oldOptionId) {
            $this->assertDatabaseMissing('theory_task_options', ['id' => $oldOptionId]);
        }

        $this->assertSame(2, $task->options()->count());
        $this->assertDatabaseHas('theory_task_options', [
            'theory_task_id' => $task->id,
            'text' => 'Верный ответ',
            'is_correct' => true,
            'error_text' => null,
            'order' => 1,
        ]);
        $this->assertDatabaseHas('theory_task_options', [
            'theory_task_id' => $task->id,
            'text' => 'Неверный ответ',
            'is_correct' => false,
            'error_text' => 'Не тот синтаксис.',
            'order' => 2,
        ]);

        $log = AdminAuditLog::query()->where('subject_id', $task->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::TheoryTaskUpdated, $log->action);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame((new TheoryTask)->getMorphClass(), $log->subject_type);
        $this->assertSame([
            'lesson_id' => $lessonId,
            'course_id' => $courseId,
            'options_count' => 2,
            'options_changed' => true,
        ], $log->meta);
    }

    public function test_identical_option_set_reports_options_changed_false(): void
    {
        $admin = User::factory()->admin()->create();
        $task = TheoryTask::factory()->withOptions()->create();

        // Same texts, correctness flags, explanations and order as the
        // factory-created set — the audit meta must report no change.
        app(UpdateTheoryTask::class)->execute($task, [
            'question' => $task->question,
            'order' => $task->order,
            'is_published' => $task->is_published,
            'options' => [
                ['text' => 'SELECT * FROM users;', 'is_correct' => true],
                [
                    'text' => 'SELECT users;',
                    'is_correct' => false,
                    'error_text' => 'Этот вариант не соответствует синтаксису SELECT: после ключевого слова SELECT нужно перечислить столбцы или *, а затем FROM с именем таблицы.',
                ],
                [
                    'text' => 'GET ALL users;',
                    'is_correct' => false,
                    'error_text' => 'Этот вариант не соответствует SQL: GET ALL — не команда языка, данные извлекаются запросом SELECT.',
                ],
            ],
        ], $admin);

        $log = AdminAuditLog::query()->where('subject_id', $task->id)->firstOrFail();
        $this->assertSame([
            'lesson_id' => $task->lesson_id,
            'course_id' => $task->lesson->level->course_id,
            'options_count' => 3,
            'options_changed' => false,
        ], $log->meta);
    }

    public function test_replacing_options_resets_users_answers(): void
    {
        $admin = User::factory()->admin()->create();
        $task = TheoryTask::factory()->withOptions()->create();
        UserTheoryTaskAnswer::factory()->for($task)->create();

        $this->assertDatabaseCount('user_theory_task_answers', 1);

        app(UpdateTheoryTask::class)->execute($task, [
            'question' => $task->question,
            'options' => [
                ['text' => 'Новый верный', 'is_correct' => true],
                ['text' => 'Новый неверный', 'is_correct' => false, 'error_text' => 'Нет.'],
            ],
        ], $admin);

        // The option_id FK cascades: the answer to the replaced task is
        // gone (a changed quiz must be re-answered).
        $this->assertDatabaseCount('user_theory_task_answers', 0);
    }

    public function test_absent_optional_fields_keep_current_values(): void
    {
        $admin = User::factory()->admin()->create();
        $task = TheoryTask::factory()->withOptions()->create([
            'order' => 3,
            'is_published' => true,
        ]);

        app(UpdateTheoryTask::class)->execute($task, [
            'question' => 'Только вопрос изменён',
            'options' => [
                ['text' => 'Верно', 'is_correct' => true],
                ['text' => 'Неверно', 'is_correct' => false, 'error_text' => 'Мимо.'],
            ],
        ], $admin);

        $this->assertDatabaseHas('theory_tasks', [
            'id' => $task->id,
            'question' => 'Только вопрос изменён',
            'order' => 3,
            'is_published' => true,
        ]);
    }
}
