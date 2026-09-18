<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\DeleteTheoryTask;
use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\TheoryTask;
use App\Models\User;
use App\Models\UserTheoryTaskAnswer;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeleteTheoryTaskTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_deletes_task_options_and_answers_with_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $task = TheoryTask::factory()->withOptions()->create();
        UserTheoryTaskAnswer::factory()->for($task)->create();
        $lessonId = $task->lesson_id;

        app(DeleteTheoryTask::class)->execute($task, $admin);

        $this->assertDatabaseMissing('theory_tasks', ['id' => $task->id]);
        $this->assertDatabaseMissing('theory_task_options', ['theory_task_id' => $task->id]);
        $this->assertDatabaseCount('user_theory_task_answers', 0);

        $log = AdminAuditLog::query()->where('subject_id', $task->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::TheoryTaskDeleted, $log->action);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame((new TheoryTask)->getMorphClass(), $log->subject_type);
        $this->assertSame([
            'lesson_id' => $lessonId,
            'question_preview' => Str::limit($task->question, 80),
            'options_count' => 3,
        ], $log->meta);
    }

    public function test_long_question_is_previewed_to_eighty_chars(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $task = TheoryTask::factory()->withOptions()->create([
            'question' => str_repeat('в', 120),
        ]);

        app(DeleteTheoryTask::class)->execute($task, $admin);

        $log = AdminAuditLog::query()->where('subject_id', $task->id)->firstOrFail();
        $this->assertSame([
            'lesson_id' => $task->lesson_id,
            'question_preview' => str_repeat('в', 80).'...',
            'options_count' => 3,
        ], $log->meta);
    }

    public function test_audit_is_written_before_the_delete(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $task = TheoryTask::factory()->withOptions()->create();

        // The probe runs inside the delete transaction at the moment the
        // audit entry is written: the task must still exist in the
        // database at that point.
        $this->mock(AdminAuditLogger::class)
            ->shouldReceive('log')
            ->once()
            ->andReturnUsing(function (AdminAuditAction $action, ?Model $subject, array $meta) use ($task): AdminAuditLog {
                $this->assertSame(AdminAuditAction::TheoryTaskDeleted, $action);
                $this->assertNotNull(TheoryTask::query()->find($task->id));

                return new AdminAuditLog;
            });

        app(DeleteTheoryTask::class)->execute($task, $admin);

        $this->assertDatabaseMissing('theory_tasks', ['id' => $task->id]);
    }
}
