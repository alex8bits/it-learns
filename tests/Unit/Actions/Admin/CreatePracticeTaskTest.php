<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\CreatePracticeTask;
use App\Enums\AdminAuditAction;
use App\Enums\PracticeRuntime;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\Lesson;
use App\Models\PracticeTask;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use App\Services\Practice\CanonicalResultSerializer;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CreatePracticeTaskTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function expectedRows(): array
    {
        return [
            ['id' => 1, 'title' => 'SQL Basics'],
            ['id' => 2, 'title' => 'Advanced SQL'],
        ];
    }

    public function test_creates_task_with_server_side_hash_and_audits(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $lesson = Lesson::factory()->create();
        $rows = self::expectedRows();

        $task = app(CreatePracticeTask::class)->execute($lesson, [
            'statement' => 'Выберите название каждой книги',
            'expected_result_text' => 'Две строки',
            'expected_rows' => '[{"id": 1, "title": "SQL Basics"}, {"id": 2, "title": "Advanced SQL"}]',
            'seed_sql' => 'CREATE TABLE books (id INTEGER PRIMARY KEY, title TEXT);',
            'is_published' => true,
            'runtime' => 'sqlite',
        ], $admin);

        $this->assertSame('Выберите название каждой книги', $task->statement);
        $this->assertSame(1, $task->order);
        $this->assertTrue($task->is_published);
        $this->assertEquals($lesson->id, $task->lesson_id);

        // The hash is derived server-side from the decoded rows — the
        // same invariant the practice harness compares against.
        $expectedHash = app(CanonicalResultSerializer::class)->hash($rows, array_keys($rows[0]));
        $this->assertSame($expectedHash, $task->expected_hash);

        $stored = PracticeTask::query()->findOrFail($task->id);
        $this->assertSame($rows, $stored->expected_rows);
        $this->assertSame('CREATE TABLE books (id INTEGER PRIMARY KEY, title TEXT);', $stored->seed_sql);
        $this->assertSame($expectedHash, $stored->expected_hash);

        $log = AdminAuditLog::query()->where('subject_id', $task->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::PracticeTaskCreated, $log->action);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame((new PracticeTask)->getMorphClass(), $log->subject_type);
        $this->assertSame([
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->level->course_id,
            'order' => 1,
            'is_published' => true,
            'runtime' => 'sqlite',
            'statement_preview' => 'Выберите название каждой книги',
        ], $log->meta);
    }

    public function test_runtime_is_persisted_and_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $lesson = Lesson::factory()->create();

        $task = app(CreatePracticeTask::class)->execute($lesson, [
            'statement' => 'JOIN на MySQL',
            'expected_result_text' => 'Строки',
            'expected_rows' => '[{"id": 1}]',
            'runtime' => 'mysql',
        ], $admin);

        // The model cast accepts the string enum value from the
        // validated payload; the DB row stores it verbatim.
        $this->assertSame(PracticeRuntime::Mysql, $task->runtime);
        $this->assertDatabaseHas('practice_tasks', [
            'id' => $task->id,
            'runtime' => 'mysql',
        ]);

        $log = AdminAuditLog::query()->where('subject_id', $task->id)->firstOrFail();
        /** @var array<string, mixed> $meta */
        $meta = $log->meta;
        $this->assertSame('mysql', $meta['runtime']);
    }

    public function test_defaults_to_draft_without_optional_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $lesson = Lesson::factory()->create();

        $task = app(CreatePracticeTask::class)->execute($lesson, [
            'statement' => 'Черновик без сида',
            'expected_result_text' => 'Любые строки',
            'expected_rows' => '[{"id": 1}]',
            'runtime' => 'sqlite',
        ], $admin);

        $this->assertFalse($task->is_published);
        $this->assertNull(PracticeTask::query()->findOrFail($task->id)->seed_sql);
        $this->assertDatabaseHas('practice_tasks', [
            'id' => $task->id,
            'is_published' => false,
            'seed_sql' => null,
        ]);

        $log = AdminAuditLog::query()->where('subject_id', $task->id)->firstOrFail();
        $this->assertSame([
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->level->course_id,
            'order' => 1,
            'is_published' => false,
            'runtime' => 'sqlite',
            'statement_preview' => 'Черновик без сида',
        ], $log->meta);
    }

    public function test_default_order_appends_after_existing_tasks(): void
    {
        $admin = User::factory()->admin()->create();
        $lesson = Lesson::factory()->create();
        PracticeTask::factory()->for($lesson)->create(['order' => 2]);
        PracticeTask::factory()->for($lesson)->create(['order' => 5]);

        $task = app(CreatePracticeTask::class)->execute($lesson, [
            'statement' => 'Третья задача',
            'expected_result_text' => 'Строки',
            'expected_rows' => '[{"id": 1}]',
            'runtime' => 'sqlite',
        ], $admin);

        $this->assertSame(6, $task->order);
    }

    public function test_explicit_order_wins_over_the_default(): void
    {
        $admin = User::factory()->admin()->create();
        $lesson = Lesson::factory()->create();
        PracticeTask::factory()->for($lesson)->create(['order' => 4]);

        $task = app(CreatePracticeTask::class)->execute($lesson, [
            'statement' => 'Закреплённая задача',
            'expected_result_text' => 'Строки',
            'expected_rows' => '[{"id": 1}]',
            'order' => 1,
            'runtime' => 'sqlite',
        ], $admin);

        $this->assertSame(1, $task->order);
    }

    public function test_audit_failure_rolls_back_the_task(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $lesson = Lesson::factory()->create();

        // The audit write is the last step of the transaction — failing it
        // must roll back the task created before it.
        $this->mock(AdminAuditLogger::class)
            ->shouldReceive('log')
            ->once()
            ->andThrow(new RuntimeException('audit storage is down'));

        try {
            app(CreatePracticeTask::class)->execute($lesson, [
                'statement' => 'Откаченная задача',
                'expected_result_text' => 'Строки',
                'expected_rows' => '[{"id": 1}]',
                'runtime' => 'sqlite',
            ], $admin);

            $this->fail('RuntimeException was not thrown.');
        } catch (RuntimeException) {
            // Expected: the transaction must abort.
        }

        $this->assertDatabaseMissing('practice_tasks', ['lesson_id' => $lesson->id]);
    }
}
