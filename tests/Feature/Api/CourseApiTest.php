<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use Tests\TestCase;

/**
 * Feature-smoke of the public catalog API v1 (Stage 6): the HTTP
 * boundary only — the payload shapes are covered by the resource unit
 * tests (tests/Unit/Http/Resources).
 *
 * No tearDown reset of LessonResource::$withMaterial on purpose: the
 * entry points pin the mode themselves, and the show-then-index test
 * below would be masked by a reset between requests.
 */
class CourseApiTest extends TestCase
{
    public function test_index_returns_only_published_courses(): void
    {
        $publishedFirst = Course::factory()->published()->create();
        $publishedSecond = Course::factory()->published()->create();
        Course::factory()->create();

        $response = $this->getJson('/api/v1/courses');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        $expected = [$publishedFirst->slug, $publishedSecond->slug];
        sort($expected);
        $slugs = array_column($response->json('data'), 'slug');
        sort($slugs);

        $this->assertSame($expected, $slugs);

        $item = $response->json('data.0');
        $this->assertArrayNotHasKey('levels', $item);
        $this->assertArrayNotHasKey('material', $item);
    }

    public function test_show_returns_course_structure_with_lesson_material(): void
    {
        $course = Course::factory()->published()->create();
        $level = Level::factory()->create([
            'course_id' => $course->id,
            'title' => 'Азы',
            'order' => 1,
        ]);
        Lesson::factory()->create([
            'level_id' => $level->id,
            'material' => 'Материал первого урока.',
        ]);

        $response = $this->getJson("/api/v1/courses/{$course->slug}");

        $response->assertOk();
        $response->assertJsonPath('slug', $course->slug);
        $response->assertJsonPath('levels.0.lessons.0.material', 'Материал первого урока.');
    }

    public function test_show_returns_404_for_unknown_slug(): void
    {
        $response = $this->getJson('/api/v1/courses/unknown');

        $response->assertNotFound();
    }

    public function test_index_after_show_in_same_process_does_not_leak_material(): void
    {
        $course = Course::factory()->published()->create();
        $level = Level::factory()->create([
            'course_id' => $course->id,
            'title' => 'Азы',
            'order' => 1,
        ]);
        Lesson::factory()->create([
            'level_id' => $level->id,
            'material' => 'Материал первого урока.',
        ]);

        $show = $this->getJson("/api/v1/courses/{$course->slug}");
        $show->assertOk();
        $show->assertJsonPath('levels.0.lessons.0.material', 'Материал первого урока.');

        $index = $this->getJson('/api/v1/courses');
        $index->assertOk();
        $index->assertJsonCount(1, 'data');

        $item = $index->json('data.0');
        $this->assertArrayNotHasKey('levels', $item);
        $this->assertArrayNotHasKey('material', $item);
    }
}
