<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\DeleteLevel;
use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeleteLevelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_deletes_level_with_cascaded_lessons_and_audits(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $level = Level::factory()->create(['title' => 'Тяжёлый', 'order' => 3]);
        Lesson::factory()->for($level)->create();
        Lesson::factory()->for($level)->create();

        app(DeleteLevel::class)->execute($level, $admin);

        $this->assertDatabaseMissing('levels', ['id' => $level->id]);
        $this->assertDatabaseCount('lessons', 0);

        $log = AdminAuditLog::query()->where('subject_id', $level->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::LevelDeleted, $log->action);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame((new Level)->getMorphClass(), $log->subject_type);
        $this->assertSame([
            'course_id' => $level->course_id,
            'title' => 'Тяжёлый',
        ], $log->meta);
    }
}
