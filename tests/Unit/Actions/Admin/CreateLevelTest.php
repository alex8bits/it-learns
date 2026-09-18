<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\CreateLevel;
use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\Course;
use App\Models\Level;
use App\Models\User;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CreateLevelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_creates_first_level_with_order_one_and_audits(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $course = Course::factory()->create(['title' => 'Основы SQL']);

        $level = app(CreateLevel::class)->execute($course, [
            'title' => 'Азы',
        ], $admin);

        $this->assertSame('Азы', $level->title);
        $this->assertSame(1, $level->order);
        $this->assertEquals($course->id, $level->course_id);

        $this->assertDatabaseHas('levels', [
            'id' => $level->id,
            'course_id' => $course->id,
            'title' => 'Азы',
            'order' => 1,
        ]);

        $log = AdminAuditLog::query()->where('subject_id', $level->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::LevelCreated, $log->action);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame((new Level)->getMorphClass(), $log->subject_type);
        $this->assertSame([
            'course_id' => $course->id,
            'title' => 'Азы',
            'order' => 1,
        ], $log->meta);
    }

    public function test_default_order_appends_after_the_highest_existing_order(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();
        Level::factory()->for($course)->create(['title' => 'Азы', 'order' => 2]);
        Level::factory()->for($course)->create(['title' => 'Практика', 'order' => 5]);

        $level = app(CreateLevel::class)->execute($course, [
            'title' => 'Продвинутые темы',
        ], $admin);

        $this->assertSame(6, $level->order);
    }

    public function test_explicit_order_overrides_the_default(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();
        Level::factory()->for($course)->create(['title' => 'Азы', 'order' => 3]);

        $level = app(CreateLevel::class)->execute($course, [
            'title' => 'Практика',
            'order' => 7,
        ], $admin);

        $this->assertSame(7, $level->order);
    }

    public function test_duplicate_title_throws_and_writes_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create(['title' => 'Основы SQL']);
        Level::factory()->for($course)->create(['title' => 'Азы', 'order' => 1]);

        try {
            app(CreateLevel::class)->execute($course, ['title' => 'Азы'], $admin);
            $this->fail('Expected RuntimeException for a duplicate level title.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Уровень «Азы» уже есть в курсе «Основы SQL».',
                $exception->getMessage(),
            );
        }

        $this->assertSame(1, Level::query()->where('course_id', $course->id)->count());
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_same_title_in_another_course_is_allowed(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();
        $otherCourse = Course::factory()->create();
        Level::factory()->for($otherCourse)->create(['title' => 'Азы', 'order' => 1]);

        $level = app(CreateLevel::class)->execute($course, ['title' => 'Азы'], $admin);

        $this->assertSame('Азы', $level->title);
        $this->assertEquals($course->id, $level->course_id);
    }
}
