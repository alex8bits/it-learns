<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Admin;

use App\Actions\Admin\DeleteCourse;
use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\AiPromptVersion;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use App\Services\Ai\PromptKeys;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeleteCourseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_deletes_course_with_cascaded_tree_and_audits(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $course = Course::factory()->create(['slug' => 'doomed-course', 'title' => 'Doomed Course']);
        $level = Level::factory()->for($course)->create(['order' => 1]);
        Lesson::factory()->for($level)->create();

        app(DeleteCourse::class)->execute($course, $admin);

        $this->assertDatabaseMissing('courses', ['id' => $course->id]);
        $this->assertDatabaseMissing('levels', ['id' => $level->id]);
        $this->assertDatabaseCount('lessons', 0);

        $log = AdminAuditLog::query()->where('subject_id', $course->id)->firstOrFail();
        $this->assertSame(AdminAuditAction::CourseDeleted, $log->action);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame((new Course)->getMorphClass(), $log->subject_type);
        $this->assertSame([
            'slug' => 'doomed-course',
            'title' => 'Doomed Course',
        ], $log->meta);
    }

    public function test_deletes_preview_file_after_commit(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        Storage::disk('public')->put('courses/previews/bye.jpg', 'bytes');
        $course = Course::factory()->create(['preview_image_path' => 'courses/previews/bye.jpg']);

        app(DeleteCourse::class)->execute($course, $admin);

        $this->assertDatabaseMissing('courses', ['id' => $course->id]);
        Storage::disk('public')->assertMissing('courses/previews/bye.jpg');
    }

    public function test_prompt_versions_survive_the_course(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();
        AiPromptVersion::factory()->create([
            'prompt_key' => PromptKeys::forCourse($course->id),
            'created_by' => $admin->id,
        ]);

        app(DeleteCourse::class)->execute($course, $admin);

        $this->assertDatabaseMissing('courses', ['id' => $course->id]);
        $this->assertDatabaseCount('ai_prompt_versions', 1);
    }

    public function test_transaction_rollback_on_audit_failure(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();
        $level = Level::factory()->for($course)->create(['order' => 1]);

        $mock = Mockery::mock(AdminAuditLogger::class);
        $mock->shouldReceive('log')
            ->once()
            ->andThrow(new RuntimeException('boom'));
        $this->instance(AdminAuditLogger::class, $mock);

        try {
            app(DeleteCourse::class)->execute($course, $admin);
            $this->fail('Expected RuntimeException to bubble out of the action.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertDatabaseHas('courses', ['id' => $course->id]);
        $this->assertDatabaseHas('levels', ['id' => $level->id]);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }
}
