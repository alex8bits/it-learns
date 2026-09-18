<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\CreateCourse;
use App\Enums\AdminAuditAction;
use App\Enums\CourseStatus;
use App\Enums\UserRole;
use App\Jobs\ConvertCoursePreviewToWebp;
use App\Models\AdminAuditLog;
use App\Models\AiPromptVersion;
use App\Models\Course;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use App\Services\Ai\PromptKeys;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CreateCourseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_creates_a_draft_course_with_unique_slug_and_audits(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $course = app(CreateCourse::class)->execute([
            'title' => 'Laravel Basics',
            'description' => 'Фундамент фреймворка',
        ], null, $admin);

        $this->assertSame('laravel-basics', $course->slug);
        $this->assertSame(CourseStatus::Draft, $course->status);
        $this->assertNull($course->preview_image_path);
        $this->assertEquals($admin->id, $course->created_by);

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'slug' => 'laravel-basics',
            'title' => 'Laravel Basics',
            'description' => 'Фундамент фреймворка',
            'status' => 'Draft',
            'created_by' => $admin->id,
        ]);

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'CourseCreated',
            'subject_type' => (new Course)->getMorphClass(),
            'subject_id' => $course->id,
        ]);

        $log = AdminAuditLog::query()->where('subject_id', $course->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::CourseCreated, $log->action);
        $this->assertSame([
            'slug' => 'laravel-basics',
            'has_preview' => false,
            'with_prompt' => false,
            'sort_order' => 0,
        ], $log->meta);
    }

    public function test_slug_gets_suffixed_when_taken(): void
    {
        $admin = User::factory()->admin()->create();
        Course::factory()->create(['slug' => 'laravel-basics']);

        $course = app(CreateCourse::class)->execute([
            'title' => 'Laravel Basics',
            'description' => 'Другой курс с тем же названием',
        ], null, $admin);

        $this->assertSame('laravel-basics-2', $course->slug);
    }

    public function test_status_attribute_is_persisted(): void
    {
        $admin = User::factory()->admin()->create();

        $course = app(CreateCourse::class)->execute([
            'title' => 'Published From Birth',
            'description' => 'desc',
            'status' => 'Published',
        ], null, $admin);

        $this->assertSame(CourseStatus::Published, $course->status);
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'status' => 'Published']);
    }

    public function test_sort_order_attribute_is_persisted_and_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $course = app(CreateCourse::class)->execute([
            'title' => 'Pinned To Top',
            'description' => 'desc',
            'sort_order' => 5,
        ], null, $admin);

        $this->assertSame(5, $course->sort_order);
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'sort_order' => 5]);

        $log = AdminAuditLog::query()->where('subject_id', $course->id)->firstOrFail();
        /** @var array<string, mixed> $meta */
        $meta = $log->meta;
        $this->assertSame(5, $meta['sort_order']);
    }

    public function test_sort_order_defaults_to_zero_when_absent(): void
    {
        $admin = User::factory()->admin()->create();

        $course = app(CreateCourse::class)->execute([
            'title' => 'Default Order',
            'description' => 'desc',
        ], null, $admin);

        $this->assertSame(0, $course->sort_order);
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'sort_order' => 0]);
    }

    public function test_processes_preview_image_and_marks_meta(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        $course = app(CreateCourse::class)->execute([
            'title' => 'With Preview',
            'description' => 'desc',
        ], UploadedFile::fake()->image('preview.jpg', 400, 400), $admin);

        $this->assertNotNull($course->preview_image_path);
        Storage::disk('public')->assertExists($course->preview_image_path);

        $log = AdminAuditLog::query()->where('subject_id', $course->id)->firstOrFail();
        $this->assertSame([
            'slug' => 'with-preview',
            'has_preview' => true,
            'with_prompt' => false,
            'sort_order' => 0,
        ], $log->meta);
    }

    public function test_dispatches_webp_conversion_when_the_preview_is_not_webp(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        $course = app(CreateCourse::class)->execute([
            'title' => 'Queued Conversion',
            'description' => 'desc',
        ], UploadedFile::fake()->image('preview.jpg', 300, 300), $admin);

        Queue::assertPushed(ConvertCoursePreviewToWebp::class, function (ConvertCoursePreviewToWebp $job) use ($course): bool {
            return $job->courseId === $course->id;
        });
    }

    public function test_does_not_dispatch_webp_conversion_without_a_preview(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();

        app(CreateCourse::class)->execute([
            'title' => 'No Preview No Job',
            'description' => 'desc',
        ], null, $admin);

        Queue::assertNotPushed(ConvertCoursePreviewToWebp::class);
    }

    public function test_does_not_dispatch_webp_conversion_for_a_webp_preview(): void
    {
        Queue::fake();
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        app(CreateCourse::class)->execute([
            'title' => 'Already WebP',
            'description' => 'desc',
        ], UploadedFile::fake()->image('preview.webp', 300, 300), $admin);

        Queue::assertNotPushed(ConvertCoursePreviewToWebp::class);
    }

    public function test_nonempty_prompt_creates_first_version_and_fills_cache_column(): void
    {
        $admin = User::factory()->admin()->create();

        $course = app(CreateCourse::class)->execute([
            'title' => 'With Prompt',
            'description' => 'desc',
            'ai_course_prompt' => 'Помогай по материалам курса.',
        ], null, $admin);

        $promptKey = PromptKeys::forCourse($course->id);

        $this->assertDatabaseHas('ai_prompt_versions', [
            'prompt_key' => $promptKey,
            'version_number' => 1,
            'body' => 'Помогай по материалам курса.',
            'comment' => 'Initial version',
            'created_by' => $admin->id,
        ]);
        $this->assertSame('Помогай по материалам курса.', $course->refresh()->ai_course_prompt);

        // Two audit entries: the prompt version + the course creation.
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'PromptVersionCreated',
            'subject_type' => (new AiPromptVersion)->getMorphClass(),
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'CourseCreated',
            'subject_id' => $course->id,
        ]);

        $log = AdminAuditLog::query()
            ->where('action', 'CourseCreated')
            ->where('subject_id', $course->id)
            ->firstOrFail();
        $this->assertSame([
            'slug' => 'with-prompt',
            'has_preview' => false,
            'with_prompt' => true,
            'sort_order' => 0,
        ], $log->meta);
    }

    public function test_blank_prompt_skips_version_creation(): void
    {
        $admin = User::factory()->admin()->create();

        $course = app(CreateCourse::class)->execute([
            'title' => 'No Prompt',
            'description' => 'desc',
            'ai_course_prompt' => '   ',
        ], null, $admin);

        $this->assertDatabaseCount('ai_prompt_versions', 0);
        $this->assertNull($course->refresh()->ai_course_prompt);
    }

    public function test_transaction_rollback_on_audit_failure(): void
    {
        $admin = User::factory()->admin()->create();

        $mock = Mockery::mock(AdminAuditLogger::class);
        $mock->shouldReceive('log')
            ->once()
            ->andThrow(new RuntimeException('boom'));
        $this->instance(AdminAuditLogger::class, $mock);

        try {
            app(CreateCourse::class)->execute([
                'title' => 'Doomed Course',
                'description' => 'desc',
            ], null, $admin);
            $this->fail('Expected RuntimeException to bubble out of the action.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertDatabaseMissing('courses', ['title' => 'Doomed Course']);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_prompt_rollback_keeps_history_empty_too(): void
    {
        // Audit throws while writing the CourseCreated entry — after the
        // prompt version was created in the same transaction; both must
        // disappear together.
        $admin = User::factory()->admin()->create();

        $calls = 0;
        $mock = Mockery::mock(AdminAuditLogger::class);
        $mock->shouldReceive('log')
            ->andReturnUsing(function () use (&$calls): never {
                $calls++;
                throw new RuntimeException('boom');
            });
        $this->instance(AdminAuditLogger::class, $mock);

        try {
            app(CreateCourse::class)->execute([
                'title' => 'Doomed With Prompt',
                'description' => 'desc',
                'ai_course_prompt' => 'Промпт, который не должен остаться.',
            ], null, $admin);
            $this->fail('Expected RuntimeException to bubble out of the action.');
        } catch (RuntimeException) {
            // expected
        }

        // The mock threw on the prompt version's own audit entry (the
        // first log call in the nested transaction), so CreateCourse's
        // CourseCreated audit was never reached.
        $this->assertSame(1, $calls);
        $this->assertDatabaseMissing('courses', ['title' => 'Doomed With Prompt']);
        $this->assertDatabaseCount('ai_prompt_versions', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }
}
