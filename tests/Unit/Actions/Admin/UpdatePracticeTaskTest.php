<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\UpdatePracticeTask;
use App\Enums\AdminAuditAction;
use App\Enums\PracticeRuntime;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\PracticeTask;
use App\Models\User;
use App\Services\Practice\CanonicalResultSerializer;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UpdatePracticeTaskTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_updates_fields_recomputes_hash_and_audits(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        // The factory deliberately leaves `runtime` unset (the column
        // default contract of Stage 10) — this test reads the audit meta,
        // so the task starts with an explicitly known runtime.
        $task = PracticeTask::factory()->create([
            'order' => 2,
            'is_published' => false,
            'runtime' => PracticeRuntime::Sqlite,
        ]);
        $oldHash = $task->expected_hash;
        $lessonId = $task->lesson_id;
        $courseId = $task->lesson->level->course_id;

        $rows = [['id' => 3, 'title' => 'New Book']];

        $task = app(UpdatePracticeTask::class)->execute($task, [
            'statement' => 'Новая формулировка',
            'expected_result_text' => 'Одна строка',
            'expected_rows' => '[{"id": 3, "title": "New Book"}]',
            'seed_sql' => 'CREATE TABLE books (id INTEGER PRIMARY KEY, title TEXT);',
            'order' => 9,
            'is_published' => true,
        ], $admin);

        $this->assertSame('Новая формулировка', $task->statement);
        $this->assertSame(9, $task->order);
        $this->assertTrue($task->is_published);

        $expectedHash = app(CanonicalResultSerializer::class)->hash($rows, array_keys($rows[0]));
        $this->assertNotSame($oldHash, $expectedHash);

        $stored = PracticeTask::query()->findOrFail($task->id);
        $this->assertSame($expectedHash, $stored->expected_hash);
        $this->assertSame($rows, $stored->expected_rows);
        $this->assertSame('CREATE TABLE books (id INTEGER PRIMARY KEY, title TEXT);', $stored->seed_sql);
        $this->assertDatabaseHas('practice_tasks', [
            'id' => $task->id,
            'statement' => 'Новая формулировка',
            'order' => 9,
            'is_published' => true,
            'expected_hash' => $expectedHash,
        ]);

        $log = AdminAuditLog::query()->where('subject_id', $task->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::PracticeTaskUpdated, $log->action);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame((new PracticeTask)->getMorphClass(), $log->subject_type);
        $this->assertSame([
            'lesson_id' => $lessonId,
            'course_id' => $courseId,
            'order' => 9,
            'is_published' => true,
            'runtime' => 'sqlite',
            'statement_preview' => 'Новая формулировка',
        ], $log->meta);
    }

    public function test_runtime_is_updated_and_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $task = PracticeTask::factory()->create();

        $task = app(UpdatePracticeTask::class)->execute($task, [
            'runtime' => 'postgres',
        ], $admin);

        $this->assertSame(PracticeRuntime::Postgres, $task->runtime);
        $this->assertDatabaseHas('practice_tasks', [
            'id' => $task->id,
            'runtime' => 'postgres',
        ]);

        // The audit meta mirrors the stored value — runtime switches are
        // observable in the admin audit log.
        $log = AdminAuditLog::query()->where('subject_id', $task->id)->firstOrFail();
        /** @var array<string, mixed> $meta */
        $meta = $log->meta;
        $this->assertSame('postgres', $meta['runtime']);
    }

    public function test_absent_expected_rows_keeps_the_current_hash(): void
    {
        $admin = User::factory()->admin()->create();
        $task = PracticeTask::factory()->create();
        $originalHash = $task->expected_hash;
        $originalRows = $task->expected_rows;

        $task = app(UpdatePracticeTask::class)->execute($task, [
            'statement' => 'Только условие изменено',
            'expected_result_text' => 'Ожидание не изменилось',
        ], $admin);

        $stored = PracticeTask::query()->findOrFail($task->id);
        $this->assertSame($originalHash, $stored->expected_hash);
        $this->assertSame($originalRows, $stored->expected_rows);
    }

    public function test_absent_optional_fields_keep_current_values(): void
    {
        $admin = User::factory()->admin()->create();
        $task = PracticeTask::factory()->withSeedScript()->create([
            'order' => 3,
            'is_published' => true,
        ]);

        $task = app(UpdatePracticeTask::class)->execute($task, [
            'statement' => 'Только условие изменено',
            'expected_result_text' => 'Только описание изменено',
            'expected_rows' => '[{"id": 1, "title": "SQL Basics"}, {"id": 2, "title": "Advanced SQL"}]',
        ], $admin);

        $this->assertDatabaseHas('practice_tasks', [
            'id' => $task->id,
            'statement' => 'Только условие изменено',
            'order' => 3,
            'is_published' => true,
        ]);
        $this->assertNotNull(PracticeTask::query()->findOrFail($task->id)->seed_sql);
    }

    public function test_explicit_null_seed_sql_clears_the_script(): void
    {
        $admin = User::factory()->admin()->create();
        $task = PracticeTask::factory()->withSeedScript()->create();

        $task = app(UpdatePracticeTask::class)->execute($task, [
            'seed_sql' => null,
        ], $admin);

        $this->assertNull(PracticeTask::query()->findOrFail($task->id)->seed_sql);
    }

    public function test_long_statement_is_previewed_to_eighty_chars_in_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $task = PracticeTask::factory()->create([
            'runtime' => PracticeRuntime::Sqlite,
        ]);
        $lessonId = $task->lesson_id;
        $courseId = $task->lesson->level->course_id;

        app(UpdatePracticeTask::class)->execute($task, [
            'statement' => str_repeat('у', 120),
        ], $admin);

        $log = AdminAuditLog::query()->where('subject_id', $task->id)->firstOrFail();
        $this->assertSame([
            'lesson_id' => $lessonId,
            'course_id' => $courseId,
            'order' => $task->order,
            'is_published' => $task->is_published,
            'runtime' => 'sqlite',
            'statement_preview' => str_repeat('у', 80).'...',
        ], $log->meta);
    }
}
