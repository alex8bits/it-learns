<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\Level;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CourseTest extends TestCase
{
    public function test_casts_map_status_to_enum(): void
    {
        $casts = (new Course)->getCasts();

        $this->assertSame(CourseStatus::class, $casts['status']);
        $this->assertSame('integer', $casts['sort_order']);
    }

    public function test_fillable_contains_exactly_the_declared_fields(): void
    {
        $this->assertSame(
            ['slug', 'title', 'description', 'preview_image_path', 'status', 'sort_order', 'ai_course_prompt', 'created_by'],
            (new Course)->getFillable(),
        );
    }

    public function test_factory_creates_draft_course_without_prompt_and_preview(): void
    {
        $course = Course::factory()->create();

        $this->assertSame(CourseStatus::Draft, $course->status);
        $this->assertNull($course->preview_image_path);
        $this->assertNull($course->ai_course_prompt);
        $this->assertNull($course->created_by);

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'status' => CourseStatus::Draft->value,
            'preview_image_path' => null,
            'ai_course_prompt' => null,
            'created_by' => null,
        ]);
    }

    public function test_published_state_sets_status_to_published(): void
    {
        $course = Course::factory()->published()->create();

        $this->assertSame(CourseStatus::Published, $course->status);
        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'status' => CourseStatus::Published->value,
        ]);
    }

    public function test_archived_state_sets_status_to_archived(): void
    {
        $course = Course::factory()->archived()->create();

        $this->assertSame(CourseStatus::Archived, $course->status);
        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'status' => CourseStatus::Archived->value,
        ]);
    }

    public function test_with_prompt_state_sets_course_prompt(): void
    {
        $course = Course::factory()->withPrompt()->create();

        $this->assertIsString($course->ai_course_prompt);
        $this->assertNotSame('', $course->ai_course_prompt);
    }

    public function test_status_round_trips_through_the_enum_cast(): void
    {
        $course = Course::factory()->create(['status' => CourseStatus::Published]);

        $fresh = Course::query()->findOrFail($course->id);

        $this->assertSame(CourseStatus::Published, $fresh->status);
        $this->assertSame(CourseStatus::Published->value, $fresh->getRawOriginal('status'));
    }

    public function test_creator_relation_resolves_the_user_that_created_the_course(): void
    {
        $creator = User::factory()->create();
        $course = Course::factory()->create(['created_by' => $creator->id]);

        $this->assertTrue($course->creator->is($creator));
    }

    public function test_creator_is_null_when_course_has_no_creator(): void
    {
        $course = Course::factory()->create();

        $this->assertNull($course->creator);
    }

    public function test_levels_relation_is_ordered_by_order_column(): void
    {
        $course = Course::factory()->create();

        // Insert out of order to prove the relation sorts by `order`
        // (titles are unique per course: unique (course_id, title)).
        Level::factory()->create(['course_id' => $course->id, 'title' => 'Третий', 'order' => 3]);
        Level::factory()->create(['course_id' => $course->id, 'title' => 'Первый', 'order' => 1]);
        Level::factory()->create(['course_id' => $course->id, 'title' => 'Второй', 'order' => 2]);

        $this->assertSame(
            ['Первый', 'Второй', 'Третий'],
            $course->levels->pluck('title')->all(),
        );
    }

    public function test_published_scope_returns_only_published_courses(): void
    {
        $published = Course::factory()->published()->create();
        Course::factory()->create();
        Course::factory()->archived()->create();

        $visible = Course::query()->published()->pluck('id');

        $this->assertSame([$published->id], $visible->all());
    }

    public function test_ordered_scope_sorts_by_sort_order_then_newest_first(): void
    {
        // created_at is not fillable, so the timestamps are shifted via
        // direct attribute writes + save() (the UserFactory::blocked()
        // pattern) to keep the order deterministic.
        $pinned = Course::factory()->create(['sort_order' => 5]);
        $pinned->created_at = now();
        $pinned->save();

        $older = Course::factory()->create(['sort_order' => 1]);
        $older->created_at = now()->subDays(2);
        $older->save();

        $newer = Course::factory()->create(['sort_order' => 1]);
        $newer->created_at = now()->subDays(1);
        $newer->save();

        $ordered = Course::query()->ordered()->pluck('id');

        // sort_order wins over created_at; the tie at sort_order = 1
        // falls back to newest first.
        $this->assertSame([$newer->id, $older->id, $pinned->id], $ordered->all());
    }

    public function test_preview_image_url_is_null_without_preview_image(): void
    {
        $course = Course::factory()->create();

        $this->assertNull($course->preview_image_url);

        $serialized = $course->toArray();

        $this->assertArrayHasKey('preview_image_url', $serialized);
        $this->assertNull($serialized['preview_image_url']);
    }

    public function test_preview_image_url_returns_public_disk_url_for_stored_path(): void
    {
        $course = Course::factory()->create(['preview_image_path' => 'courses/previews/probe.png']);

        $this->assertSame(
            Storage::disk('public')->url('courses/previews/probe.png'),
            $course->preview_image_url,
        );
        $this->assertStringEndsWith('/storage/courses/previews/probe.png', $course->preview_image_url);
    }
}
