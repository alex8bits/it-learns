<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Courses;

use App\Models\Course;
use App\Models\Level;
use App\Services\Courses\CourseCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_published_courses_with_smallest_sort_order_first(): void
    {
        $pinned = Course::factory()->published()->create([
            'sort_order' => 5,
            'created_at' => '2026-01-02 10:00:00',
        ]);
        Course::factory()->create(); // draft — excluded
        Course::factory()->archived()->create(); // archived — excluded
        $top = Course::factory()->published()->create([
            'sort_order' => 1,
            // Older than $pinned, but the manual order wins over created_at.
            'created_at' => '2026-01-01 10:00:00',
        ]);

        $catalog = app(CourseCatalog::class)->paginate();

        $this->assertSame([$top->id, $pinned->id], $catalog->pluck('id')->all());
    }

    public function test_equal_sort_order_falls_back_to_newest_first(): void
    {
        $older = Course::factory()->published()->create([
            'sort_order' => 2,
            'created_at' => '2026-01-01 10:00:00',
        ]);
        $newer = Course::factory()->published()->create([
            'sort_order' => 2,
            'created_at' => '2026-01-02 10:00:00',
        ]);

        $catalog = app(CourseCatalog::class)->paginate();

        $this->assertSame([$newer->id, $older->id], $catalog->pluck('id')->all());
    }

    public function test_default_zero_sort_order_keeps_legacy_newest_first_behaviour(): void
    {
        // The factory defaults every course to sort_order = 0 — exactly
        // what the forward migration backfills for existing rows.
        $older = Course::factory()->published()->create(['created_at' => '2026-01-01 10:00:00']);
        $newer = Course::factory()->published()->create(['created_at' => '2026-01-02 10:00:00']);

        $catalog = app(CourseCatalog::class)->paginate();

        $this->assertSame([$newer->id, $older->id], $catalog->pluck('id')->all());
    }

    public function test_items_carry_levels_count_and_hide_internal_attributes(): void
    {
        $course = Course::factory()->published()->withPrompt()->create();
        Level::factory()->create(['course_id' => $course]);

        $item = app(CourseCatalog::class)->paginate()->first();

        $this->assertSame(1, $item->levels_count);

        $visible = $item->toArray();
        $this->assertArrayNotHasKey('ai_course_prompt', $visible);
        $this->assertArrayNotHasKey('preview_image_path', $visible);
        $this->assertArrayNotHasKey('created_by', $visible);
        $this->assertArrayHasKey('preview_image_url', $visible);
    }
}
