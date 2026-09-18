<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserLessonProgress;
use Database\Seeders\DemoCourseSeeder;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    public function test_home_shows_published_courses_catalog_for_guest(): void
    {
        $this->seed(DemoCourseSeeder::class);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Courses/Index')
            ->has('courses.data')
            ->where('courses.data.0.title', 'Основы SQL'));
    }

    public function test_courses_alias_shows_catalog(): void
    {
        $this->seed(DemoCourseSeeder::class);

        $response = $this->get('/courses');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Courses/Index')
            ->has('courses.data'));
    }

    public function test_catalog_lists_courses_in_manual_order(): void
    {
        $pinned = Course::factory()->published()->create([
            'sort_order' => 5,
            'created_at' => '2026-01-02 10:00:00',
        ]);
        $top = Course::factory()->published()->create([
            'sort_order' => 1,
            'created_at' => '2026-01-01 10:00:00',
        ]);

        $response = $this->get('/courses');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Courses/Index')
            ->where('courses.data.0.id', $top->id)
            ->where('courses.data.1.id', $pinned->id));
    }

    public function test_course_card_shows_levels_for_guest(): void
    {
        $this->seed(DemoCourseSeeder::class);

        $response = $this->get('/courses/sql-basics');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Courses/Show')
            ->where('course.slug', 'sql-basics')
            ->has('course.levels')
            // Гостевая карточка без изменений: прогресс не приезжает.
            ->missing('progress'));
    }

    public function test_course_card_shows_user_progress_when_authenticated(): void
    {
        $this->seed(DemoCourseSeeder::class);
        $user = User::factory()->create();
        $lesson = Lesson::query()->where('slug', 'select-basics')->firstOrFail();
        UserLessonProgress::factory()->completed()->create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
        ]);

        $response = $this->actingAs($user)->get('/courses/sql-basics');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Courses/Show')
            ->where('progress.percent', 33)
            ->where('progress.lessonStatuses.'.$lesson->id, 'Completed'));
    }

    public function test_unknown_course_slug_returns_404(): void
    {
        $response = $this->get('/courses/unknown-slug');

        $response->assertNotFound();
    }
}
