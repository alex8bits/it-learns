<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\UpdateLesson;
use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\Lesson;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UpdateLessonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_updates_fields_and_audits_publish_transition(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $lesson = Lesson::factory()->unpublished()->create([
            'slug' => 'immutable-slug',
            'title' => 'Old Title',
            'material' => 'Старый материал',
            'order' => 2,
        ]);

        $lesson = app(UpdateLesson::class)->execute($lesson, [
            'title' => 'New Title',
            'material' => 'Новый материал',
            'order' => 7,
            'is_published' => true,
        ], $admin);

        $this->assertSame('New Title', $lesson->title);
        $this->assertSame('Новый материал', $lesson->material);
        $this->assertSame(7, $lesson->order);
        $this->assertTrue($lesson->is_published);
        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'title' => 'New Title',
            'material' => 'Новый материал',
            'order' => 7,
            'is_published' => true,
        ]);

        $log = AdminAuditLog::query()->where('subject_id', $lesson->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::LessonUpdated, $log->action);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame([
            'slug' => 'immutable-slug',
            'is_published_old' => false,
            'is_published_new' => true,
        ], $log->meta);
    }

    public function test_slug_is_immutable(): void
    {
        $admin = User::factory()->admin()->create();
        $lesson = Lesson::factory()->create(['slug' => 'immutable-slug']);

        app(UpdateLesson::class)->execute($lesson, [
            'slug' => 'hacked-slug',
            'title' => 'Renamed',
        ], $admin);

        $this->assertSame('immutable-slug', $lesson->refresh()->slug);
        $this->assertSame('Renamed', $lesson->title);
    }

    public function test_explicit_null_material_clears_the_material(): void
    {
        $admin = User::factory()->admin()->create();
        $lesson = Lesson::factory()->create(['material' => 'Была теория']);

        app(UpdateLesson::class)->execute($lesson, [
            'material' => null,
        ], $admin);

        $this->assertNull($lesson->refresh()->material);
    }

    public function test_unpublishing_records_the_transition(): void
    {
        $admin = User::factory()->admin()->create();
        $lesson = Lesson::factory()->create(['is_published' => true]);

        app(UpdateLesson::class)->execute($lesson, [
            'is_published' => false,
        ], $admin);

        $log = AdminAuditLog::query()->where('subject_id', $lesson->id)->firstOrFail();
        $this->assertSame([
            'slug' => $lesson->slug,
            'is_published_old' => true,
            'is_published_new' => false,
        ], $log->meta);
    }
}
