<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\PracticeTask;
use App\Models\PracticeTaskSubmission;
use App\Models\User;
use Database\Seeders\DemoCourseSeeder;
use Tests\TestCase;

class LessonShowTest extends TestCase
{
    public function test_authenticated_user_sees_lesson_with_material_and_quiz(): void
    {
        $this->seed(DemoCourseSeeder::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('lessons.show', 'select-basics'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Lessons/Show')
            ->where('lesson.slug', 'select-basics')
            ->where('lesson.material', Lesson::query()->where('slug', 'select-basics')->value('material'))
            ->has('lesson.theoryTasks')
            ->has('lesson.theoryTasks.0.options', 3)
            ->where('course.slug', 'sql-basics')
            // Эффективные пороги минимума: min(K, N) — у демо-урока
            // 2 теории (min(3,2)) и 1 практика (min(1,1)).
            ->where('requiredTheoryCount', 2)
            ->where('requiredPracticeCount', 1));
    }

    public function test_next_lesson_prop_points_to_the_following_lesson_of_the_course(): void
    {
        $this->seed(DemoCourseSeeder::class);
        $user = User::factory()->create();

        $next = Lesson::query()->where('slug', 'where-ordering')->firstOrFail();

        $response = $this->actingAs($user)->get(route('lessons.show', 'select-basics'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Lessons/Show')
            ->where('nextLesson.id', $next->id)
            ->where('nextLesson.slug', 'where-ordering')
            ->where('nextLesson.title', $next->title));

        // nextLesson несёт только навигацию — ровно {id, slug, title}.
        /** @var array<string, mixed> $props */
        $props = $response->viewData('page')['props'];
        /** @var array<string, int|string> $nextLesson */
        $nextLesson = $props['nextLesson'];
        $this->assertSame(['id', 'slug', 'title'], array_keys($nextLesson));
    }

    public function test_last_lesson_of_the_course_has_no_next_lesson(): void
    {
        $this->seed(DemoCourseSeeder::class);
        $user = User::factory()->create();

        // «joins-intro» — последний урок демо-курса (без задач).
        $response = $this->actingAs($user)->get(route('lessons.show', 'joins-intro'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Lessons/Show')
            ->where('nextLesson', null)
            ->where('requiredTheoryCount', 0)
            ->where('requiredPracticeCount', 0));
    }

    /**
     * Quiz-spoiler guard (design Risk «Утечка is_correct») plus the
     * practice-spoiler guard (design Risk «Spoiler-гвард»): the lesson
     * props must not contain the option correctness flags, their
     * explanation texts, or the practice reference fields (seed script,
     * canonical hash, reference rows) on any level.
     */
    public function test_lesson_props_do_not_leak_option_correctness(): void
    {
        $this->seed(DemoCourseSeeder::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('lessons.show', 'select-basics'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->missing('lesson.theoryTasks.0.options.0.is_correct')
            ->missing('lesson.theoryTasks.0.options.0.error_text')
            ->missing('practiceTasks.0.seed_sql')
            ->missing('practiceTasks.0.expected_hash')
            ->missing('practiceTasks.0.expected_rows'));

        /** @var array<string, mixed> $props */
        $props = $response->viewData('page')['props'];
        $encodedLesson = json_encode($props['lesson'], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('is_correct', $encodedLesson);
        $this->assertStringNotContainsString('error_text', $encodedLesson);

        // Practice guard covers the whole props bag: the reference rows,
        // their hash and the seed script must not slip into any prop.
        $encodedProps = json_encode($props, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('seed_sql', $encodedProps);
        $this->assertStringNotContainsString('expected_hash', $encodedProps);
        $this->assertStringNotContainsString('expected_rows', $encodedProps);
    }

    public function test_lesson_page_includes_practice_task_props(): void
    {
        $this->seed(DemoCourseSeeder::class);
        $user = User::factory()->create();

        $task = PracticeTask::query()
            ->whereRelation('lesson', 'slug', 'select-basics')
            ->firstOrFail();

        $response = $this->actingAs($user)->get(route('lessons.show', 'select-basics'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Lessons/Show')
            ->where('practiceTasks.0.id', $task->id)
            ->where('practiceTasks.0.statement', $task->statement)
            ->where('practiceTasks.0.expected_result_text', $task->expected_result_text)
            ->where('practiceTasks.0.order', $task->order)
            ->has('passedPracticeTaskIds', 0));
    }

    public function test_solved_practice_task_ids_are_passed_to_the_page(): void
    {
        $this->seed(DemoCourseSeeder::class);
        $user = User::factory()->create();

        $lesson = Lesson::query()->where('slug', 'select-basics')->firstOrFail();
        $task = $lesson->practiceTasks()->firstOrFail();

        PracticeTaskSubmission::factory()
            ->for($user)
            ->for($task)
            ->passed()
            ->create();

        $response = $this->actingAs($user)->get(route('lessons.show', 'select-basics'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('passedPracticeTaskIds', 1)
            ->where('passedPracticeTaskIds.0', $task->id));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->seed(DemoCourseSeeder::class);

        $this->get(route('lessons.show', 'select-basics'))->assertRedirect(route('login'));
    }
}
