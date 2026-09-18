<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\UpdateCourse;
use App\Enums\AdminAuditAction;
use App\Enums\CourseStatus;
use App\Enums\UserRole;
use App\Jobs\ConvertCoursePreviewToWebp;
use App\Models\AdminAuditLog;
use App\Models\Course;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UpdateCourseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_updates_scalar_fields_and_audits_course_updated(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $course = Course::factory()->create(['title' => 'Old Title', 'description' => 'Old']);

        $course = app(UpdateCourse::class)->execute($course, [
            'title' => 'New Title',
            'description' => 'New description',
            'status' => 'Draft',
        ], null, $admin);

        $this->assertSame('New Title', $course->title);
        $this->assertSame('New description', $course->description);
        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'title' => 'New Title',
            'description' => 'New description',
            'status' => 'Draft',
        ]);

        $log = AdminAuditLog::query()->where('subject_id', $course->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::CourseUpdated, $log->action);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame([
            'slug' => $course->slug,
            'status_old' => 'Draft',
            'status_new' => 'Draft',
            'preview_changed' => false,
            'sort_order' => 0,
        ], $log->meta);
    }

    public function test_publishing_writes_course_published(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create(); // Draft

        app(UpdateCourse::class)->execute($course, [
            'status' => 'Published',
        ], null, $admin);

        $this->assertSame(CourseStatus::Published, $course->refresh()->status);

        $log = AdminAuditLog::query()->where('subject_id', $course->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::CoursePublished, $log->action);
        $this->assertNotSame(AdminAuditAction::CourseUpdated, $log->action);
        $this->assertSame([
            'slug' => $course->slug,
            'status_old' => 'Draft',
            'status_new' => 'Published',
            'preview_changed' => false,
            'sort_order' => 0,
        ], $log->meta);
    }

    public function test_archiving_writes_course_archived(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->published()->create();

        app(UpdateCourse::class)->execute($course, [
            'status' => 'Archived',
        ], null, $admin);

        $this->assertSame(CourseStatus::Archived, $course->refresh()->status);

        $log = AdminAuditLog::query()->where('subject_id', $course->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::CourseArchived, $log->action);
        $this->assertSame([
            'slug' => $course->slug,
            'status_old' => 'Published',
            'status_new' => 'Archived',
            'preview_changed' => false,
            'sort_order' => 0,
        ], $log->meta);
    }

    public function test_staying_in_the_same_status_writes_course_updated(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->published()->create();

        app(UpdateCourse::class)->execute($course, [
            'title' => 'Retitled',
            'status' => 'Published',
        ], null, $admin);

        $log = AdminAuditLog::query()->where('subject_id', $course->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::CourseUpdated, $log->action);
        $this->assertSame([
            'slug' => $course->slug,
            'status_old' => 'Published',
            'status_new' => 'Published',
            'preview_changed' => false,
            'sort_order' => 0,
        ], $log->meta);
    }

    public function test_updates_sort_order_and_records_new_value_in_audit_meta(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create(['sort_order' => 3]);

        $course = app(UpdateCourse::class)->execute($course, [
            'sort_order' => 2,
        ], null, $admin);

        $this->assertSame(2, $course->sort_order);
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'sort_order' => 2]);

        $log = AdminAuditLog::query()->where('subject_id', $course->id)->firstOrFail();
        /** @var array<string, mixed> $meta */
        $meta = $log->meta;
        $this->assertSame(2, $meta['sort_order']);
    }

    public function test_missing_sort_order_key_keeps_the_field_untouched(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create(['sort_order' => 7]);

        app(UpdateCourse::class)->execute($course, [
            'title' => 'Renamed Only',
        ], null, $admin);

        $this->assertSame(7, $course->refresh()->sort_order);
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'sort_order' => 7]);
    }

    public function test_slug_is_immutable(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create(['slug' => 'original-slug']);

        app(UpdateCourse::class)->execute($course, [
            'title' => 'New Title',
            'slug' => 'hacked-slug',
        ], null, $admin);

        $this->assertSame('original-slug', $course->refresh()->slug);
    }

    public function test_replaces_preview_and_deletes_old_file_after_commit(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        Storage::disk('public')->put('courses/previews/old.jpg', 'old-bytes');
        $course = Course::factory()->create(['preview_image_path' => 'courses/previews/old.jpg']);

        $course = app(UpdateCourse::class)->execute($course, [
            'title' => 'With New Preview',
        ], UploadedFile::fake()->image('new.jpg', 300, 300), $admin);

        $this->assertNotSame('courses/previews/old.jpg', $course->preview_image_path);
        Storage::disk('public')->assertExists($course->preview_image_path);
        Storage::disk('public')->assertMissing('courses/previews/old.jpg');
        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'preview_image_path' => $course->preview_image_path,
        ]);

        $log = AdminAuditLog::query()->where('subject_id', $course->id)->firstOrFail();
        $this->assertSame([
            'slug' => $course->slug,
            'status_old' => 'Draft',
            'status_new' => 'Draft',
            'preview_changed' => true,
            'sort_order' => 0,
        ], $log->meta);
    }

    public function test_without_new_file_the_old_preview_is_kept(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        Storage::disk('public')->put('courses/previews/kept.jpg', 'bytes');
        $course = Course::factory()->create(['preview_image_path' => 'courses/previews/kept.jpg']);

        app(UpdateCourse::class)->execute($course, [
            'title' => 'Renamed',
        ], null, $admin);

        $this->assertSame('courses/previews/kept.jpg', $course->refresh()->preview_image_path);
        Storage::disk('public')->assertExists('courses/previews/kept.jpg');
    }

    public function test_dispatches_webp_conversion_when_the_new_preview_is_not_webp(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        Storage::disk('public')->put('courses/previews/old.jpg', 'old-bytes');
        $course = Course::factory()->create(['preview_image_path' => 'courses/previews/old.jpg']);

        app(UpdateCourse::class)->execute($course, [
            'title' => 'Queued Conversion',
        ], UploadedFile::fake()->image('new.jpg', 300, 300), $admin);

        Queue::assertPushed(ConvertCoursePreviewToWebp::class, function (ConvertCoursePreviewToWebp $job) use ($course): bool {
            return $job->courseId === $course->id;
        });
    }

    public function test_does_not_dispatch_webp_conversion_for_a_webp_replacement(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        Storage::disk('public')->put('courses/previews/old.jpg', 'old-bytes');
        $course = Course::factory()->create(['preview_image_path' => 'courses/previews/old.jpg']);

        app(UpdateCourse::class)->execute($course, [
            'title' => 'Already WebP',
        ], UploadedFile::fake()->image('new.webp', 300, 300), $admin);

        Queue::assertNotPushed(ConvertCoursePreviewToWebp::class);
    }

    public function test_does_not_dispatch_webp_conversion_without_a_new_file(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        Storage::disk('public')->put('courses/previews/still-there.jpg', 'bytes');
        $course = Course::factory()->create(['preview_image_path' => 'courses/previews/still-there.jpg']);

        app(UpdateCourse::class)->execute($course, [
            'title' => 'Renamed Only',
        ], null, $admin);

        Queue::assertNotPushed(ConvertCoursePreviewToWebp::class);
    }

    public function test_transaction_rollback_on_audit_failure_keeps_course_and_old_preview(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        Storage::disk('public')->put('courses/previews/rollback.jpg', 'old-bytes');
        $course = Course::factory()->create([
            'title' => 'Original Title',
            'status' => 'Draft',
            'preview_image_path' => 'courses/previews/rollback.jpg',
        ]);

        $mock = Mockery::mock(AdminAuditLogger::class);
        $mock->shouldReceive('log')
            ->once()
            ->andThrow(new RuntimeException('boom'));
        $this->instance(AdminAuditLogger::class, $mock);

        try {
            app(UpdateCourse::class)->execute($course, [
                'title' => 'Should Not Persist',
                'status' => 'Published',
            ], UploadedFile::fake()->image('new.jpg', 300, 300), $admin);
            $this->fail('Expected RuntimeException to bubble out of the action.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $course = $course->refresh();
        $this->assertSame('Original Title', $course->title);
        $this->assertSame(CourseStatus::Draft, $course->status);
        $this->assertSame('courses/previews/rollback.jpg', $course->preview_image_path);

        // The post-commit old-file delete() must not have run.
        Storage::disk('public')->assertExists('courses/previews/rollback.jpg');

        $this->assertDatabaseMissing('admin_audit_logs', ['subject_id' => $course->id]);
    }
}
