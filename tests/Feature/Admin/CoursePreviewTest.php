<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\PracticeTask;
use App\Models\TheoryTask;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature smoke of the admin course preview (Stage 11): the admin opens
 * the user-facing pages read-only for a DRAFT course — everything that
 * is draft-gated for students must still reach the props, and no
 * progress row may appear afterwards (the preview is GET-only).
 */
class CoursePreviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');
    }

    public function test_admin_previews_draft_course_with_draft_lesson(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();
        $level = Level::factory()->create([
            'course_id' => $course->id,
            'title' => 'Азы',
            'order' => 1,
        ]);
        $draftLesson = Lesson::factory()
            ->unpublished()
            ->for($level)
            ->create(['title' => 'Черновик урока', 'order' => 1]);

        $response = $this->actingAs($admin)->get(route('admin.courses.preview.show', $course));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Courses/Show')
            ->where('previewMode', true)
            ->where('course.id', $course->id)
            ->where('course.levels.0.lessons.0.title', $draftLesson->title)
            ->where('course.levels.0.lessons.0.is_published', false)
            ->where('progress', null)
            ->missing('course.ai_course_prompt'));

        // Read-only by construction: the preview never writes progress.
        $this->assertDatabaseMissing('user_course_progress', [
            'user_id' => $admin->id,
            'course_id' => $course->id,
        ]);
        $this->assertDatabaseMissing('user_lesson_progress', [
            'user_id' => $admin->id,
            'lesson_id' => $draftLesson->id,
        ]);
    }

    public function test_admin_previews_draft_lesson_with_theory_and_practice(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();
        $level = Level::factory()->create([
            'course_id' => $course->id,
            'title' => 'Азы',
            'order' => 1,
        ]);
        $lesson = Lesson::factory()->unpublished()->for($level)->create();
        $theoryTask = TheoryTask::factory()->unpublished()->withOptions()->for($lesson)->create();
        $practiceTask = PracticeTask::factory()->unpublished()->for($lesson)->create();

        $response = $this->actingAs($admin)->get(route('admin.courses.preview.lesson', [$course, $lesson]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Lessons/Show')
            ->where('previewMode', true)
            ->where('lesson.id', $lesson->id)
            ->where('lesson.material', $lesson->material)
            ->where('lesson.theoryTasks.0.question', $theoryTask->question)
            ->has('lesson.theoryTasks.0.options', 3)
            // Spoiler guards survive the preview: the admin sees exactly
            // what a student sees.
            ->missing('lesson.theoryTasks.0.options.0.is_correct')
            // Preview never answers questions, so correct_option is always
            // null — mirrors LessonController::show shape (where the field
            // is null unless the user solved the task).
            ->where('lesson.theoryTasks.0.correct_option', null)
            ->where('practiceTasks.0.statement', $practiceTask->statement)
            ->missing('practiceTasks.0.seed_sql')
            ->where('course.id', $course->id)
            ->where('answers', [])
            ->where('passedPracticeTaskIds', [])
            // Зеркальные пороги: min(K, N) по ВСЕМ задачам preview —
            // у единственного урока 1 черновая теория и 1 черновая
            // практика; он же последний — следующего нет.
            ->where('requiredTheoryCount', 1)
            ->where('requiredPracticeCount', 1)
            ->where('nextLesson', null));

        $this->assertDatabaseMissing('user_lesson_progress', [
            'user_id' => $admin->id,
            'lesson_id' => $lesson->id,
        ]);
    }

    public function test_preview_lesson_thresholds_count_draft_tasks_and_next_lesson_includes_drafts(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();
        $level = Level::factory()->create([
            'course_id' => $course->id,
            'title' => 'Азы',
            'order' => 1,
        ]);
        $lesson = Lesson::factory()->unpublished()->for($level)->create(['order' => 1]);
        TheoryTask::factory()
            ->unpublished()
            ->withOptions()
            ->for($lesson)
            ->sequence(['order' => 1], ['order' => 2])
            ->count(2)
            ->create();
        PracticeTask::factory()->unpublished()->for($lesson)->create();
        $nextLesson = Lesson::factory()->unpublished()->for($level)->create(['order' => 2]);

        $response = $this->actingAs($admin)->get(route('admin.courses.preview.lesson', [$course, $lesson]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Lessons/Show')
            // Пороги считают и черновики: 2 теории -> min(3,2), практика
            // -> min(1,1) — цифры совпадают со списком, который видит админ.
            ->where('requiredTheoryCount', 2)
            ->where('requiredPracticeCount', 1)
            // Навигация preview включает черновики и строится по id.
            ->where('nextLesson.id', $nextLesson->id)
            ->where('nextLesson.slug', $nextLesson->slug)
            ->where('nextLesson.title', $nextLesson->title));
    }

    public function test_lesson_of_another_course_returns_404(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->create();
        $otherCourse = Course::factory()->create();
        $level = Level::factory()->create([
            'course_id' => $otherCourse->id,
            'title' => 'Азы',
            'order' => 1,
        ]);
        $lesson = Lesson::factory()->for($level)->create();

        $this->actingAs($admin)
            ->get(route('admin.courses.preview.lesson', [$course, $lesson]))
            ->assertNotFound();
    }

    public function test_non_admin_is_denied(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.courses.preview.show', $course))
            ->assertForbidden();
    }
}
