<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\CourseStatus;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CourseCrudTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_admin_sees_courses_index(): void
    {
        $admin = User::factory()->admin()->create();
        Course::factory()->count(2)->create();

        $response = $this->actingAs($admin)->get(route('admin.courses.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Courses/Index')
            ->has('courses.data', 2)
            ->has('statuses'));
    }

    public function test_admin_filters_courses_index_by_status(): void
    {
        $admin = User::factory()->admin()->create();
        Course::factory()->count(2)->published()->create();
        Course::factory()->create();

        $response = $this->actingAs($admin)
            ->get(route('admin.courses.index', ['status' => CourseStatus::Published->value]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Courses/Index')
            ->has('courses.data', 2)
            ->where('filters.status', CourseStatus::Published->value));
    }

    public function test_admin_creates_course_with_preview_and_opens_it(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.courses.store'), [
            'title' => 'Основы SQL',
            'description' => 'Базовый курс по базам данных',
            'status' => CourseStatus::Draft->value,
            'preview_image' => UploadedFile::fake()->image('preview.jpg', 300, 300),
        ]);

        $course = Course::query()->where('title', 'Основы SQL')->firstOrFail();
        $response->assertRedirect(route('admin.courses.show', $course));
        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'title' => 'Основы SQL',
            'status' => CourseStatus::Draft->value,
            'created_by' => $admin->id,
        ]);
        // QUEUE_CONNECTION=sync executes the queued WebP conversion
        // inline during the request, so the stored preview is already
        // converted by the time the redirect comes back.
        $this->assertNotNull($course->preview_image_path);
        $this->assertStringEndsWith('.webp', $course->preview_image_path);
        Storage::disk('public')->assertExists($course->preview_image_path);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::CourseCreated->value,
            'admin_id' => $admin->id,
        ]);

        $show = $this->actingAs($admin)->get(route('admin.courses.show', $course));

        $show->assertOk();
        $show->assertInertia(fn ($page) => $page
            ->component('Admin/Courses/Show')
            ->where('course.id', $course->id)
            ->has('statuses')
            ->where('creator.id', $admin->id)
            ->missing('course.creator'));
    }

    public function test_admin_updates_course(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();

        $response = $this->actingAs($admin)->patch(route('admin.courses.update', $course), [
            'title' => $course->title,
            'description' => 'Обновлённое описание курса',
            'status' => CourseStatus::Published->value,
        ]);

        $response->assertRedirect(route('admin.courses.show', $course));
        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'description' => 'Обновлённое описание курса',
            'status' => CourseStatus::Published->value,
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::CoursePublished->value,
            'admin_id' => $admin->id,
        ]);
    }

    public function test_admin_deletes_course(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();

        $response = $this->actingAs($admin)->delete(route('admin.courses.destroy', $course));

        $response->assertRedirect(route('admin.courses.index'));
        $this->assertDatabaseMissing('courses', ['id' => $course->id]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::CourseDeleted->value,
            'admin_id' => $admin->id,
        ]);
    }

    public function test_admin_opens_course_form_pages(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();

        $this->actingAs($admin)->get(route('admin.courses.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Courses/Create')
                ->has('statuses'));

        $this->actingAs($admin)->get(route('admin.courses.edit', $course))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Courses/Edit')
                ->where('course.id', $course->id)
                ->has('statuses'));
    }

    public function test_admin_opens_lesson_form_pages(): void
    {
        $admin = User::factory()->admin()->create();
        $level = Level::factory()->create([
            'title' => 'Азы',
            'order' => 1,
        ]);
        $lesson = Lesson::factory()->for($level)->create();

        // `level.course` / `lesson.level` are loaded by the controllers for
        // the authorize check and serialized with the props — the lesson
        // pages rely on them for the "back to course" link and the level
        // title.
        $this->actingAs($admin)->get(route('admin.levels.lessons.create', $level))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Lessons/Create')
                ->where('level.course.id', $level->course_id)
                ->where('level.title', 'Азы'));

        $this->actingAs($admin)->get(route('admin.lessons.edit', $lesson))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Lessons/Edit')
                ->where('lesson.level.course_id', $level->course_id)
                ->where('lesson.level.title', 'Азы'));
    }

    public function test_admin_creates_level_with_title_and_default_order(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();

        $response = $this->actingAs($admin)->post(route('admin.courses.levels.store', $course), [
            'title' => 'Азы',
        ]);

        $level = Level::query()->where('course_id', $course->id)->firstOrFail();

        $response->assertRedirect(route('admin.courses.show', $course));
        $this->assertDatabaseHas('levels', [
            'id' => $level->id,
            'course_id' => $course->id,
            'title' => 'Азы',
            'order' => 1,
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::LevelCreated->value,
            'admin_id' => $admin->id,
            'subject_id' => $level->id,
        ]);
    }

    public function test_admin_updates_level_title_and_order(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();
        $level = Level::factory()->for($course)->create(['title' => 'Азы', 'order' => 1]);

        $response = $this->actingAs($admin)->patch(route('admin.levels.update', $level), [
            'title' => 'Основы',
            'order' => 2,
        ]);

        $response->assertRedirect(route('admin.courses.show', $course));
        $this->assertDatabaseHas('levels', [
            'id' => $level->id,
            'title' => 'Основы',
            'order' => 2,
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::LevelUpdated->value,
            'admin_id' => $admin->id,
            'subject_id' => $level->id,
        ]);
    }
}
