<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CourseProgressStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Database\Seeders\DemoCourseSeeder;
use Tests\TestCase;

class CourseStartTest extends TestCase
{
    public function test_start_redirects_to_first_lesson_and_records_course_progress(): void
    {
        $this->seed(DemoCourseSeeder::class);
        $user = User::factory()->create();
        $course = Course::query()->where('slug', 'sql-basics')->firstOrFail();

        $response = $this->actingAs($user)->post(route('courses.start', $course));

        $response->assertRedirect(route('lessons.show', 'select-basics'));
        $this->assertDatabaseHas('user_course_progress', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => CourseProgressStatus::InProgress->value,
            'current_lesson_id' => Lesson::query()->where('slug', 'select-basics')->value('id'),
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->seed(DemoCourseSeeder::class);
        $course = Course::query()->where('slug', 'sql-basics')->firstOrFail();

        $this->post(route('courses.start', $course))->assertRedirect(route('login'));
    }
}
