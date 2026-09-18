<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\LessonResource;
use App\Http\Resources\LevelResource;
use App\Models\Lesson;
use App\Models\Level;
use Illuminate\Http\Request;
use Tests\TestCase;

class LevelResourceTest extends TestCase
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

    public function test_level_shape_exposes_catalog_fields(): void
    {
        $level = Level::factory()->create([
            'title' => 'Средний уровень',
            'order' => 2,
        ]);

        $payload = (new LevelResource($level))->resolve(new Request);

        $this->assertSame(
            ['id', 'title', 'order'],
            array_keys($payload),
        );
        $this->assertSame($level->id, $payload['id']);
        $this->assertSame('Средний уровень', $payload['title']);
        $this->assertSame(2, $payload['order']);
    }

    public function test_lessons_are_absent_when_the_relation_is_not_loaded(): void
    {
        $level = Level::factory()->create();
        Lesson::factory()->create(['level_id' => $level->id]);

        $payload = (new LevelResource($level->refresh()))->resolve(new Request);

        $this->assertArrayNotHasKey('lessons', $payload);
    }

    public function test_lessons_are_present_when_the_relation_is_loaded(): void
    {
        $level = Level::factory()->create();
        Lesson::factory()->create(['level_id' => $level->id, 'title' => 'Урок о JOIN', 'order' => 2]);
        Lesson::factory()->create(['level_id' => $level->id, 'title' => 'Первый урок', 'order' => 1]);

        $payload = (new LevelResource($level->load('lessons')))->resolve(new Request);

        $this->assertArrayHasKey('lessons', $payload);

        $lessons = $payload['lessons']->resolve(new Request);
        $this->assertSame(['Первый урок', 'Урок о JOIN'], array_column($lessons, 'title'));
        $this->assertNotContains('material', array_keys($lessons[0]));
    }

    public function test_loaded_lessons_expose_material_when_the_detail_flag_is_on(): void
    {
        $level = Level::factory()->create();
        Lesson::factory()->create(['level_id' => $level->id, 'material' => 'Детальный материал.']);

        LessonResource::$withMaterial = true;

        $payload = (new LevelResource($level->load('lessons')))->resolve(new Request);
        $lessons = $payload['lessons']->resolve(new Request);

        $this->assertSame('Детальный материал.', $lessons[0]['material']);
    }
}
