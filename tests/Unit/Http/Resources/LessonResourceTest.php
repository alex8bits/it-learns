<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\LessonResource;
use App\Models\Lesson;
use Illuminate\Http\Request;
use Tests\TestCase;

class LessonResourceTest extends TestCase
{
    /**
     * $withMaterial is static process-wide state flipped by the detail
     * endpoint — reset it so it never leaks into other tests.
     */
    protected function tearDown(): void
    {
        LessonResource::$withMaterial = false;

        parent::tearDown();
    }

    public function test_list_shape_exposes_catalog_fields_without_material(): void
    {
        $lesson = Lesson::factory()->create([
            'material' => 'Материал урока: SELECT и WHERE.',
            'order' => 3,
        ]);

        $payload = (new LessonResource($lesson))->resolve(new Request);

        $this->assertSame(
            ['id', 'slug', 'title', 'order', 'is_published'],
            array_keys($payload),
        );
        $this->assertSame($lesson->id, $payload['id']);
        $this->assertSame($lesson->slug, $payload['slug']);
        $this->assertSame($lesson->title, $payload['title']);
        $this->assertSame(3, $payload['order']);
        $this->assertTrue($payload['is_published']);
    }

    public function test_material_is_hidden_by_default_and_exposed_when_the_detail_flag_is_on(): void
    {
        $lesson = Lesson::factory()->create(['material' => 'Материал урока.']);

        $hidden = (new LessonResource($lesson))->resolve(new Request);
        $this->assertArrayNotHasKey('material', $hidden);

        LessonResource::$withMaterial = true;

        $exposed = (new LessonResource($lesson))->resolve(new Request);
        $this->assertSame('Материал урока.', $exposed['material']);
        $this->assertSame(
            ['id', 'slug', 'title', 'order', 'is_published', 'material'],
            array_keys($exposed),
        );
    }

    public function test_material_is_null_when_the_lesson_has_none(): void
    {
        $lesson = Lesson::factory()->create(['material' => null]);

        LessonResource::$withMaterial = true;

        $payload = (new LessonResource($lesson))->resolve(new Request);

        $this->assertNull($payload['material']);
    }

    public function test_unpublished_lesson_keeps_its_raw_is_published_value(): void
    {
        $lesson = Lesson::factory()->unpublished()->create();

        $payload = (new LessonResource($lesson))->resolve(new Request);

        $this->assertFalse($payload['is_published']);
    }
}
