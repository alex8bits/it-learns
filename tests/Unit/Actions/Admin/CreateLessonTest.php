<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\CreateLesson;
use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CreateLessonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_creates_first_lesson_with_slug_and_default_order(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $level = Level::factory()->create(['order' => 1]);

        $lesson = app(CreateLesson::class)->execute($level, [
            'title' => 'Intro to PHP',
            'material' => 'Первый урок',
        ], $admin);

        $this->assertSame('intro-to-php', $lesson->slug);
        $this->assertSame(1, $lesson->order);
        $this->assertSame('Первый урок', $lesson->material);
        $this->assertFalse($lesson->is_published);
        $this->assertEquals($level->id, $lesson->level_id);

        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'level_id' => $level->id,
            'slug' => 'intro-to-php',
            'order' => 1,
            'is_published' => false,
        ]);

        $log = AdminAuditLog::query()->where('subject_id', $lesson->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::LessonCreated, $log->action);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame((new Lesson)->getMorphClass(), $log->subject_type);
        $this->assertSame([
            'level_id' => $level->id,
            'course_id' => $level->course_id,
            'slug' => 'intro-to-php',
            'order' => 1,
            'is_published' => false,
        ], $log->meta);
    }

    public function test_default_order_appends_after_existing_lessons(): void
    {
        $admin = User::factory()->admin()->create();
        $level = Level::factory()->create(['order' => 1]);
        Lesson::factory()->for($level)->create(['order' => 2]);
        Lesson::factory()->for($level)->create(['order' => 5]);

        $lesson = app(CreateLesson::class)->execute($level, [
            'title' => 'Third Lesson',
        ], $admin);

        $this->assertSame(6, $lesson->order);
    }

    public function test_explicit_order_wins_over_the_default(): void
    {
        $admin = User::factory()->admin()->create();
        $level = Level::factory()->create(['order' => 1]);
        Lesson::factory()->for($level)->create(['order' => 4]);

        $lesson = app(CreateLesson::class)->execute($level, [
            'title' => 'Pinned First',
            'order' => 1,
            'is_published' => true,
        ], $admin);

        $this->assertSame(1, $lesson->order);
        $this->assertTrue($lesson->is_published);
    }

    public function test_slug_collision_gets_a_suffix(): void
    {
        $admin = User::factory()->admin()->create();
        $level = Level::factory()->create(['order' => 1]);
        Lesson::factory()->create(['slug' => 'intro-to-php']);

        $lesson = app(CreateLesson::class)->execute($level, [
            'title' => 'Intro to PHP',
        ], $admin);

        $this->assertSame('intro-to-php-2', $lesson->slug);
    }
}
