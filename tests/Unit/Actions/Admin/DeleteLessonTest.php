<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\DeleteLesson;
use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\Lesson;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeleteLessonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_deletes_lesson_and_audits(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $lesson = Lesson::factory()->create([
            'slug' => 'doomed-lesson',
            'title' => 'Doomed Lesson',
        ]);
        $levelId = $lesson->level_id;

        app(DeleteLesson::class)->execute($lesson, $admin);

        $this->assertDatabaseMissing('lessons', ['id' => $lesson->id]);

        $log = AdminAuditLog::query()->where('subject_id', $lesson->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::LessonDeleted, $log->action);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame((new Lesson)->getMorphClass(), $log->subject_type);
        $this->assertSame([
            'level_id' => $levelId,
            'slug' => 'doomed-lesson',
            'title' => 'Doomed Lesson',
        ], $log->meta);
    }
}
