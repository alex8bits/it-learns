<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\UpdateLevel;
use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\Course;
use App\Models\Level;
use App\Models\User;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UpdateLevelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_updates_title_and_order_and_audits(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $course = Course::factory()->create();
        $level = Level::factory()->for($course)->create([
            'title' => 'Старое название',
            'order' => 2,
        ]);

        $level = app(UpdateLevel::class)->execute($level, [
            'title' => 'Новое название',
            'order' => 5,
        ], $admin);

        $this->assertSame('Новое название', $level->title);
        $this->assertSame(5, $level->order);
        $this->assertDatabaseHas('levels', [
            'id' => $level->id,
            'title' => 'Новое название',
            'order' => 5,
        ]);

        $log = AdminAuditLog::query()->where('subject_id', $level->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::LevelUpdated, $log->action);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame([
            'course_id' => $course->id,
            'title' => 'Новое название',
            'order' => 5,
        ], $log->meta);
    }

    public function test_order_only_update_skips_the_title_collision_check(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();
        $level = Level::factory()->for($course)->create(['title' => 'Азы', 'order' => 1]);

        $level = app(UpdateLevel::class)->execute($level, [
            'order' => 9,
        ], $admin);

        $this->assertSame('Азы', $level->refresh()->title);
        $this->assertSame(9, $level->order);
        $this->assertDatabaseCount('admin_audit_logs', 1);
    }

    public function test_rename_to_a_foreign_title_of_the_same_course_throws_and_writes_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create(['title' => 'Основы SQL']);
        $level = Level::factory()->for($course)->create(['title' => 'Азы', 'order' => 1]);
        Level::factory()->for($course)->create(['title' => 'Практика', 'order' => 2]);

        try {
            app(UpdateLevel::class)->execute($level, [
                'title' => 'Практика',
                'order' => 3,
            ], $admin);
            $this->fail('Expected RuntimeException for a title collision.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Уровень «Практика» уже есть в курсе «Основы SQL».',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('levels', [
            'id' => $level->id,
            'title' => 'Азы',
            'order' => 1,
        ]);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_rename_to_own_title_is_allowed(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();
        $level = Level::factory()->for($course)->create(['title' => 'Азы', 'order' => 1]);

        $level = app(UpdateLevel::class)->execute($level, [
            'title' => 'Азы',
            'order' => 2,
        ], $admin);

        $this->assertSame('Азы', $level->title);
        $this->assertSame(2, $level->order);
    }

    public function test_rename_to_a_title_of_another_course_level_is_allowed(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();
        $otherCourse = Course::factory()->create();
        Level::factory()->for($otherCourse)->create(['title' => 'Практика', 'order' => 1]);
        $level = Level::factory()->for($course)->create(['title' => 'Азы', 'order' => 1]);

        $level = app(UpdateLevel::class)->execute($level, [
            'title' => 'Практика',
        ], $admin);

        $this->assertSame('Практика', $level->refresh()->title);
    }

    public function test_empty_attributes_keep_the_level_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $level = Level::factory()->create(['title' => 'Не тронь', 'order' => 4]);

        app(UpdateLevel::class)->execute($level, [], $admin);

        $this->assertSame('Не тронь', $level->refresh()->title);
        $this->assertSame(4, $level->order);
        $this->assertDatabaseCount('admin_audit_logs', 1);
    }

    public function test_off_contract_empty_title_is_stored_verbatim(): void
    {
        // The empty-title guard lives in the validated layer only:
        // `AdminUpdateLevelRequest` requires `title`, and HTTP turns ''
        // into null (ConvertEmptyStringsToNull) which `required`
        // rejects — see AdminUpdateLevelRequestTest for that gate. The
        // Action itself does not re-guard, so an off-contract direct
        // call stores the value verbatim; pinned here to keep the
        // single-guard contract explicit.
        $admin = User::factory()->admin()->create();
        $level = Level::factory()->create(['title' => 'Азы', 'order' => 1]);

        $level = app(UpdateLevel::class)->execute($level, [
            'title' => '',
            'order' => 2,
        ], $admin);

        $this->assertSame('', $level->refresh()->title);
        $this->assertSame(2, $level->order);
    }
}
