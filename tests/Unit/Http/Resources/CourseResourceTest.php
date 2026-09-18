<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Resources;

use App\Enums\CourseStatus;
use App\Http\Resources\CourseResource;
use App\Http\Resources\LessonResource;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Level;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CourseResourceTest extends TestCase
{
    /**
     * LessonResource::$withMaterial is static process-wide state flipped
     * by the detail endpoint — reset it so nested lesson shapes in these
     * tests start from the default (list) mode.
     */
    protected function tearDown(): void
    {
        LessonResource::$withMaterial = false;

        parent::tearDown();
    }

    public function test_list_shape_exposes_catalog_fields_with_enum_as_string(): void
    {
        $course = Course::factory()->published()->create([
            'title' => 'SQL для аналитиков',
            'description' => 'Курс по базовым запросам.',
        ]);

        $payload = (new CourseResource($course))->resolve(new Request);

        $this->assertSame(
            ['id', 'slug', 'title', 'description', 'status', 'preview_image_url', 'created_at'],
            array_keys($payload),
        );
        $this->assertSame($course->id, $payload['id']);
        $this->assertSame($course->slug, $payload['slug']);
        $this->assertSame('SQL для аналитиков', $payload['title']);
        $this->assertSame('Курс по базовым запросам.', $payload['description']);
        $this->assertSame('Published', $payload['status']);
        $this->assertNotSame(CourseStatus::Published, $payload['status']);
        $this->assertNull($payload['preview_image_url']);
    }

    public function test_created_at_is_formatted_as_iso_8601(): void
    {
        $course = Course::factory()->create();
        $course->created_at = now()->subDay();
        $course->save();

        $payload = (new CourseResource($course->refresh()))->resolve(new Request);

        $this->assertSame($course->created_at->toIso8601String(), $payload['created_at']);
    }

    public function test_preview_image_url_is_null_without_preview_and_a_url_with_one(): void
    {
        $withoutPreview = Course::factory()->create(['preview_image_path' => null]);
        $withPreview = Course::factory()->create(['preview_image_path' => 'courses/previews/card.png']);

        $payload = (new CourseResource($withoutPreview))->resolve(new Request);
        $this->assertNull($payload['preview_image_url']);

        $payload = (new CourseResource($withPreview))->resolve(new Request);
        $this->assertSame(Storage::disk('public')->url('courses/previews/card.png'), $payload['preview_image_url']);
    }

    public function test_levels_and_levels_count_are_absent_without_relation_and_count(): void
    {
        $course = Course::factory()->create();
        Level::factory()->create(['course_id' => $course->id]);

        $payload = (new CourseResource($course->refresh()))->resolve(new Request);

        $this->assertArrayNotHasKey('levels', $payload);
        $this->assertArrayNotHasKey('levels_count', $payload);
    }

    public function test_levels_are_present_when_the_relation_is_loaded(): void
    {
        $course = Course::factory()->create();
        $level = Level::factory()->create(['course_id' => $course->id, 'title' => 'Азы', 'order' => 1]);
        Lesson::factory()->create(['level_id' => $level->id, 'material' => 'Материал урока.']);

        $payload = (new CourseResource($course->load('levels.lessons')))->resolve(new Request);

        $this->assertArrayHasKey('levels', $payload);

        $levels = $payload['levels']->resolve(new Request);
        $this->assertCount(1, $levels);
        $this->assertSame('Азы', $levels[0]['title']);

        $lessons = $levels[0]['lessons']->resolve(new Request);
        $this->assertNotContains('material', array_keys($lessons[0]));
    }

    public function test_levels_count_is_present_when_the_relation_is_counted(): void
    {
        $course = Course::factory()->create();
        Level::factory()->create(['course_id' => $course->id, 'title' => 'Азы', 'order' => 1]);
        Level::factory()->create(['course_id' => $course->id, 'title' => 'Практика', 'order' => 2]);

        $counted = Course::query()->withCount('levels')->findOrFail($course->id);

        $payload = (new CourseResource($counted))->resolve(new Request);

        $this->assertSame(2, $payload['levels_count']);
        $this->assertArrayNotHasKey('levels', $payload);
    }

    public function test_detail_shape_loads_levels_with_lesson_material(): void
    {
        $course = Course::factory()->published()->create();
        $level = Level::factory()->create(['course_id' => $course->id, 'title' => 'Азы', 'order' => 1]);
        Lesson::factory()->create(['level_id' => $level->id, 'material' => 'Детальный материал урока.']);

        LessonResource::$withMaterial = true;

        $course = Course::query()
            ->with(['levels' => fn ($levels) => $levels->ordered()->with([
                'lessons' => fn ($lessons) => $lessons->published()->ordered(),
            ])])
            ->findOrFail($course->id);

        $payload = (new CourseResource($course))->resolve(new Request);
        $levels = $payload['levels']->resolve(new Request);
        $lessons = $levels[0]['lessons']->resolve(new Request);

        $this->assertSame('Детальный материал урока.', $lessons[0]['material']);
    }
}
