<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\PracticeRuntime;
use App\Enums\UserRole;
use App\Models\Lesson;
use App\Models\PracticeTask;
use App\Models\User;
use App\Services\Practice\CanonicalResultSerializer;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PracticeTaskCrudTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_admin_creates_practice_task(): void
    {
        $admin = User::factory()->admin()->create();
        $lesson = Lesson::factory()->create();
        $rows = [
            ['id' => 1, 'title' => 'SQL Basics'],
            ['id' => 2, 'title' => 'Advanced SQL'],
        ];

        $response = $this->actingAs($admin)->post(route('admin.lessons.practice-tasks.store', $lesson), [
            'statement' => 'Выберите название каждой книги',
            'expected_result_text' => 'Две строки',
            'expected_rows' => '[{"id": 1, "title": "SQL Basics"}, {"id": 2, "title": "Advanced SQL"}]',
            'seed_sql' => 'CREATE TABLE books (id INTEGER PRIMARY KEY, title TEXT);',
            'is_published' => true,
            'runtime' => 'mysql',
        ]);

        $task = PracticeTask::query()
            ->where('lesson_id', $lesson->id)
            ->where('statement', 'Выберите название каждой книги')
            ->firstOrFail();

        $response->assertRedirect(route('admin.lessons.edit', $lesson));
        $this->assertSame(
            app(CanonicalResultSerializer::class)->hash($rows, array_keys($rows[0])),
            $task->expected_hash,
        );
        $this->assertDatabaseHas('practice_tasks', [
            'id' => $task->id,
            'runtime' => 'mysql',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::PracticeTaskCreated->value,
            'admin_id' => $admin->id,
            'subject_id' => $task->id,
        ]);
    }

    public function test_admin_updates_practice_task(): void
    {
        $admin = User::factory()->admin()->create();
        $task = PracticeTask::factory()->create();
        $rows = [['id' => 3, 'title' => 'New Book']];

        $response = $this->actingAs($admin)->patch(route('admin.practice-tasks.update', $task), [
            'statement' => 'Обновлённая формулировка',
            'expected_result_text' => 'Одна строка',
            'expected_rows' => '[{"id": 3, "title": "New Book"}]',
            'runtime' => 'postgres',
        ]);

        $response->assertRedirect(route('admin.lessons.edit', $task->lesson));
        $this->assertSame(
            app(CanonicalResultSerializer::class)->hash($rows, array_keys($rows[0])),
            $task->refresh()->expected_hash,
        );
        $this->assertDatabaseHas('practice_tasks', [
            'id' => $task->id,
            'runtime' => 'postgres',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::PracticeTaskUpdated->value,
            'admin_id' => $admin->id,
            'subject_id' => $task->id,
        ]);
    }

    public function test_admin_deletes_practice_task(): void
    {
        $admin = User::factory()->admin()->create();
        $task = PracticeTask::factory()->create();

        $response = $this->actingAs($admin)->delete(route('admin.practice-tasks.destroy', $task));

        $response->assertRedirect(route('admin.lessons.edit', $task->lesson));
        $this->assertDatabaseMissing('practice_tasks', ['id' => $task->id]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::PracticeTaskDeleted->value,
            'admin_id' => $admin->id,
            'subject_id' => $task->id,
        ]);
    }

    public function test_admin_opens_practice_task_form_pages(): void
    {
        $admin = User::factory()->admin()->create();
        $lesson = Lesson::factory()->create();
        // The factory leaves `runtime` unset (the in-memory model would
        // hold null) — over the real HTTP flow the edit page receives the
        // task loaded from the database, so the row is created with an
        // explicit runtime here.
        $task = PracticeTask::factory()->for($lesson)->create([
            'runtime' => PracticeRuntime::Sqlite,
        ]);

        // `lesson.level` / `task.lesson.level` are loaded by the
        // controllers and serialized with the props — the pages rely on
        // them for the back link and the prefill. `runtimeOptions`
        // arrives from PracticeRuntime::options() on both form pages
        // (three enum cases after Stage 10).
        $this->actingAs($admin)->get(route('admin.lessons.practice-tasks.create', $lesson))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/PracticeTasks/Create')
                ->where('lesson.id', $lesson->id)
                ->where('lesson.level.course_id', $lesson->level->course_id)
                ->has('runtimeOptions', 3)
                ->where('runtimeOptions.0.value', 'sqlite'));

        $this->actingAs($admin)->get(route('admin.practice-tasks.edit', $task))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/PracticeTasks/Edit')
                ->where('task.id', $task->id)
                ->has('task.expected_rows', 2)
                ->where('task.runtime', 'sqlite')
                ->where('task.lesson.id', $lesson->id)
                ->where('task.lesson.level.course_id', $lesson->level->course_id)
                ->has('runtimeOptions', 3)
                ->where('runtimeOptions.0.value', 'sqlite'));
    }

    public function test_regular_user_is_forbidden_on_every_route(): void
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::User->value);
        $lesson = Lesson::factory()->create();
        $task = PracticeTask::factory()->create();
        $payload = [
            'statement' => 'Задача',
            'expected_result_text' => 'Строки',
            'expected_rows' => '[{"id": 1}]',
        ];

        $this->actingAs($user)->get(route('admin.lessons.practice-tasks.create', $lesson))->assertForbidden();
        $this->actingAs($user)->post(route('admin.lessons.practice-tasks.store', $lesson), $payload)->assertForbidden();
        $this->actingAs($user)->get(route('admin.practice-tasks.edit', $task))->assertForbidden();
        $this->actingAs($user)->patch(route('admin.practice-tasks.update', $task), $payload)->assertForbidden();
        $this->actingAs($user)->delete(route('admin.practice-tasks.destroy', $task))->assertForbidden();
    }
}
