<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\DeletePracticeTask;
use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\PracticeTask;
use App\Models\PracticeTaskFeedback;
use App\Models\PracticeTaskSubmission;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeletePracticeTaskTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_deletes_task_submissions_and_feedbacks_with_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $task = PracticeTask::factory()->create();
        $submission = PracticeTaskSubmission::factory()->for($task)->create();
        PracticeTaskFeedback::factory()->for($submission, 'submission')->create();
        $lessonId = $task->lesson_id;

        app(DeletePracticeTask::class)->execute($task, $admin);

        $this->assertDatabaseMissing('practice_tasks', ['id' => $task->id]);
        $this->assertDatabaseMissing('practice_task_submissions', ['practice_task_id' => $task->id]);
        $this->assertDatabaseMissing('practice_task_feedbacks', ['practice_task_submission_id' => $submission->id]);

        $log = AdminAuditLog::query()->where('subject_id', $task->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::PracticeTaskDeleted, $log->action);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame((new PracticeTask)->getMorphClass(), $log->subject_type);
        $this->assertSame([
            'lesson_id' => $lessonId,
            'statement_preview' => Str::limit($task->statement, 80),
        ], $log->meta);
    }

    public function test_long_statement_is_previewed_to_eighty_chars(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $task = PracticeTask::factory()->create([
            'statement' => str_repeat('у', 120),
        ]);

        app(DeletePracticeTask::class)->execute($task, $admin);

        $log = AdminAuditLog::query()->where('subject_id', $task->id)->firstOrFail();
        $this->assertSame([
            'lesson_id' => $task->lesson_id,
            'statement_preview' => str_repeat('у', 80).'...',
        ], $log->meta);
    }

    public function test_audit_is_written_before_the_delete(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $task = PracticeTask::factory()->create();

        // The probe runs inside the delete transaction at the moment the
        // audit entry is written: the task must still exist in the
        // database at that point (a valid audit subject).
        $this->mock(AdminAuditLogger::class)
            ->shouldReceive('log')
            ->once()
            ->andReturnUsing(function (AdminAuditAction $action, ?Model $subject, array $meta) use ($task): AdminAuditLog {
                $this->assertSame(AdminAuditAction::PracticeTaskDeleted, $action);
                $this->assertNotNull(PracticeTask::query()->find($task->id));

                return new AdminAuditLog;
            });

        app(DeletePracticeTask::class)->execute($task, $admin);

        $this->assertDatabaseMissing('practice_tasks', ['id' => $task->id]);
    }
}
