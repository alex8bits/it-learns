<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\Lesson;
use App\Models\TheoryTask;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TheoryTaskCrudTest extends TestCase
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
    public static function storeOptions(): array
    {
        return [
            ['text' => 'SELECT * FROM users;', 'is_correct' => true],
            ['text' => 'SELECT users;', 'is_correct' => false, 'error_text' => 'Не тот синтаксис.'],
            ['text' => 'GET ALL users;', 'is_correct' => false, 'error_text' => 'Не команда SQL.'],
        ];
    }

    public function test_admin_creates_theory_task(): void
    {
        $admin = User::factory()->admin()->create();
        $lesson = Lesson::factory()->create();

        $response = $this->actingAs($admin)->post(route('admin.lessons.theory-tasks.store', $lesson), [
            'question' => 'Какой запрос выбирает все столбцы из таблицы users?',
            'is_published' => true,
            'options' => self::storeOptions(),
        ]);

        $task = TheoryTask::query()
            ->where('lesson_id', $lesson->id)
            ->where('question', 'Какой запрос выбирает все столбцы из таблицы users?')
            ->firstOrFail();

        $response->assertRedirect(route('admin.lessons.edit', $lesson));
        $this->assertSame(3, $task->options()->count());
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::TheoryTaskCreated->value,
            'admin_id' => $admin->id,
            'subject_id' => $task->id,
        ]);
    }

    public function test_admin_updates_theory_task(): void
    {
        $admin = User::factory()->admin()->create();
        $task = TheoryTask::factory()->withOptions()->create();

        $response = $this->actingAs($admin)->patch(route('admin.theory-tasks.update', $task), [
            'question' => 'Обновлённый вопрос',
            'options' => [
                ['text' => 'Верно', 'is_correct' => true],
                ['text' => 'Неверно', 'is_correct' => false, 'error_text' => 'Мимо.'],
            ],
        ]);

        $response->assertRedirect(route('admin.lessons.edit', $task->lesson));
        $this->assertDatabaseHas('theory_tasks', [
            'id' => $task->id,
            'question' => 'Обновлённый вопрос',
        ]);
        $this->assertSame(2, $task->options()->count());
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::TheoryTaskUpdated->value,
            'admin_id' => $admin->id,
            'subject_id' => $task->id,
        ]);
    }

    public function test_admin_deletes_theory_task(): void
    {
        $admin = User::factory()->admin()->create();
        $task = TheoryTask::factory()->withOptions()->create();

        $response = $this->actingAs($admin)->delete(route('admin.theory-tasks.destroy', $task));

        $response->assertRedirect(route('admin.lessons.edit', $task->lesson));
        $this->assertDatabaseMissing('theory_tasks', ['id' => $task->id]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::TheoryTaskDeleted->value,
            'admin_id' => $admin->id,
            'subject_id' => $task->id,
        ]);
    }

    public function test_admin_opens_theory_task_form_pages(): void
    {
        $admin = User::factory()->admin()->create();
        $lesson = Lesson::factory()->create();
        $task = TheoryTask::factory()->withOptions()->for($lesson)->create();

        // `lesson.level` / `task.options` + `task.lesson.level` are loaded
        // by the controllers and serialized with the props — the pages
        // rely on them for the back link and the options prefill.
        $this->actingAs($admin)->get(route('admin.lessons.theory-tasks.create', $lesson))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/TheoryTasks/Create')
                ->where('lesson.id', $lesson->id)
                ->where('lesson.level.course_id', $lesson->level->course_id));

        $this->actingAs($admin)->get(route('admin.theory-tasks.edit', $task))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/TheoryTasks/Edit')
                ->where('task.id', $task->id)
                ->has('task.options', 3)
                ->where('task.lesson.id', $lesson->id)
                ->where('task.lesson.level.course_id', $lesson->level->course_id));
    }

    public function test_regular_user_is_forbidden_on_every_route(): void
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::User->value);
        $lesson = Lesson::factory()->create();
        $task = TheoryTask::factory()->withOptions()->create();

        $this->actingAs($user)->get(route('admin.lessons.theory-tasks.create', $lesson))->assertForbidden();
        $this->actingAs($user)->post(route('admin.lessons.theory-tasks.store', $lesson), [
            'question' => 'Вопрос',
            'options' => self::storeOptions(),
        ])->assertForbidden();
        $this->actingAs($user)->get(route('admin.theory-tasks.edit', $task))->assertForbidden();
        $this->actingAs($user)->patch(route('admin.theory-tasks.update', $task), [
            'question' => 'Вопрос',
            'options' => self::storeOptions(),
        ])->assertForbidden();
        $this->actingAs($user)->delete(route('admin.theory-tasks.destroy', $task))->assertForbidden();
    }
}
