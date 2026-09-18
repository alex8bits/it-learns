<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\User;
use App\Models\UserLessonProgress;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_authenticated_user_can_view_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('user.name', $user->name));
    }

    public function test_dashboard_shows_published_courses_only(): void
    {
        $user = User::factory()->create();
        $published = Course::factory()->published()->create(['title' => 'Опубликованный курс']);
        Course::factory()->create(['title' => 'Черновик курса']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('courses.data', 1)
            ->where('courses.data.0.id', $published->id)
            ->where('courses.data.0.title', 'Опубликованный курс'));
    }

    public function test_dashboard_shows_per_course_progress_percents(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->published()->create();
        $level = Level::factory()->create(['course_id' => $course]);
        $completed = Lesson::factory()->create(['level_id' => $level->id, 'order' => 1]);
        Lesson::factory()->create(['level_id' => $level->id, 'order' => 2]);
        Lesson::factory()->create(['level_id' => $level->id, 'order' => 3]);
        UserLessonProgress::factory()->completed()->for($user)->create(['lesson_id' => $completed->id]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('progressPercents.'.$course->id, 33));
    }
}
