<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminAuditAction;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\User;
use App\Services\Ai\PromptKeys;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CoursePromptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_admin_opens_course_prompt_editor(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->withPrompt()->create();

        $response = $this->actingAs($admin)->get(route('admin.courses.prompt.edit', $course));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Courses/Prompt')
            ->where('course.id', $course->id)
            ->where('course.title', $course->title)
            ->where('prompt', $course->ai_course_prompt)
            ->where('promptKey', PromptKeys::forCourse($course->id)));
    }

    public function test_admin_updates_course_prompt(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();

        $response = $this->actingAs($admin)->patch(route('admin.courses.prompt.update', $course), [
            'body' => 'Отвечай в контексте курса «Основы SQL».',
            'comment' => 'Первая правка',
        ]);

        $response->assertRedirect(route('admin.courses.prompt.edit', $course));
        $this->assertDatabaseHas('ai_prompt_versions', [
            'prompt_key' => PromptKeys::forCourse($course->id),
            'body' => 'Отвечай в контексте курса «Основы SQL».',
            'comment' => 'Первая правка',
            'version_number' => 1,
            'created_by' => $admin->id,
        ]);
        $this->assertSame(
            'Отвечай в контексте курса «Основы SQL».',
            $course->refresh()->ai_course_prompt,
        );
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => AdminAuditAction::PromptVersionCreated->value,
            'admin_id' => $admin->id,
        ]);
    }
}
