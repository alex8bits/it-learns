<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\CreateTheoryTask;
use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\Lesson;
use App\Models\TheoryTask;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CreateTheoryTaskTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    /**
     * @return list<array{text: string, is_correct: bool, error_text?: string|null}>
     */
    public static function payloadOptions(): array
    {
        return [
            ['text' => 'SELECT * FROM users;', 'is_correct' => true],
            ['text' => 'SELECT users;', 'is_correct' => false, 'error_text' => 'После SELECT нужно перечислить столбцы, а затем FROM с таблицей.'],
            ['text' => 'GET ALL users;', 'is_correct' => false, 'error_text' => 'GET ALL — не команда SQL.'],
        ];
    }

    public function test_creates_task_with_options_and_audits(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $lesson = Lesson::factory()->create();

        $task = app(CreateTheoryTask::class)->execute($lesson, [
            'question' => 'Какой запрос выбирает все столбцы из таблицы users?',
            'is_published' => true,
            'options' => self::payloadOptions(),
        ], $admin);

        $this->assertSame('Какой запрос выбирает все столбцы из таблицы users?', $task->question);
        $this->assertSame(1, $task->order);
        $this->assertTrue($task->is_published);
        $this->assertEquals($lesson->id, $task->lesson_id);

        $this->assertDatabaseHas('theory_tasks', [
            'id' => $task->id,
            'lesson_id' => $lesson->id,
            'question' => 'Какой запрос выбирает все столбцы из таблицы users?',
            'order' => 1,
            'is_published' => true,
        ]);

        $this->assertSame(3, TheoryTask::query()->findOrFail($task->id)->options()->count());
        $this->assertDatabaseHas('theory_task_options', [
            'theory_task_id' => $task->id,
            'text' => 'SELECT * FROM users;',
            'is_correct' => true,
            'error_text' => null,
            'order' => 1,
        ]);
        $this->assertDatabaseHas('theory_task_options', [
            'theory_task_id' => $task->id,
            'text' => 'SELECT users;',
            'is_correct' => false,
            'error_text' => 'После SELECT нужно перечислить столбцы, а затем FROM с таблицей.',
            'order' => 2,
        ]);
        $this->assertDatabaseHas('theory_task_options', [
            'theory_task_id' => $task->id,
            'text' => 'GET ALL users;',
            'is_correct' => false,
            'error_text' => 'GET ALL — не команда SQL.',
            'order' => 3,
        ]);

        $log = AdminAuditLog::query()->where('subject_id', $task->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::TheoryTaskCreated, $log->action);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame((new TheoryTask)->getMorphClass(), $log->subject_type);
        $this->assertSame([
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->level->course_id,
            'order' => 1,
            'options_count' => 3,
            'is_published' => true,
        ], $log->meta);
    }

    public function test_defaults_to_draft_without_options_counting_meta(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $lesson = Lesson::factory()->create();

        $task = app(CreateTheoryTask::class)->execute($lesson, [
            'question' => 'Вопрос без publish-флага',
            'options' => [
                ['text' => 'Верно', 'is_correct' => true],
                ['text' => 'Неверно', 'is_correct' => false, 'error_text' => 'Мимо.'],
            ],
        ], $admin);

        $this->assertFalse($task->is_published);
        $this->assertDatabaseHas('theory_tasks', [
            'id' => $task->id,
            'is_published' => false,
        ]);

        $log = AdminAuditLog::query()->where('subject_id', $task->id)->firstOrFail();
        $this->assertSame([
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->level->course_id,
            'order' => 1,
            'options_count' => 2,
            'is_published' => false,
        ], $log->meta);
    }

    public function test_default_order_appends_after_existing_tasks(): void
    {
        $admin = User::factory()->admin()->create();
        $lesson = Lesson::factory()->create();
        TheoryTask::factory()->for($lesson)->create(['order' => 2]);
        TheoryTask::factory()->for($lesson)->create(['order' => 5]);

        $task = app(CreateTheoryTask::class)->execute($lesson, [
            'question' => 'Третья задача',
            'options' => [
                ['text' => 'Верно', 'is_correct' => true],
                ['text' => 'Неверно', 'is_correct' => false, 'error_text' => 'Мимо.'],
            ],
        ], $admin);

        $this->assertSame(6, $task->order);
    }

    public function test_explicit_order_wins_over_the_default(): void
    {
        $admin = User::factory()->admin()->create();
        $lesson = Lesson::factory()->create();
        TheoryTask::factory()->for($lesson)->create(['order' => 4]);

        $task = app(CreateTheoryTask::class)->execute($lesson, [
            'question' => 'Закреплённая задача',
            'order' => 1,
            'options' => [
                ['text' => 'Верно', 'is_correct' => true],
                ['text' => 'Неверно', 'is_correct' => false, 'error_text' => 'Мимо.'],
            ],
        ], $admin);

        $this->assertSame(1, $task->order);
    }

    public function test_audit_failure_rolls_back_the_task_and_options(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $lesson = Lesson::factory()->create();

        // The audit write is the last step of the transaction — failing it
        // must roll back the task and every option created before it.
        $this->mock(AdminAuditLogger::class)
            ->shouldReceive('log')
            ->once()
            ->andThrow(new RuntimeException('audit storage is down'));

        try {
            app(CreateTheoryTask::class)->execute($lesson, [
                'question' => 'Откаченная задача',
                'options' => [
                    ['text' => 'Верно', 'is_correct' => true],
                    ['text' => 'Неверно', 'is_correct' => false, 'error_text' => 'Мимо.'],
                ],
            ], $admin);

            $this->fail('RuntimeException was not thrown.');
        } catch (RuntimeException) {
            // Expected: the transaction must abort.
        }

        $this->assertDatabaseMissing('theory_tasks', ['lesson_id' => $lesson->id]);
        $this->assertDatabaseCount('theory_task_options', 0);
    }
}
