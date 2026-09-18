<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Courses;

use App\Models\Course;
use App\Models\Lesson;
use App\Services\Courses\UniqueSlugGenerator;
use Tests\TestCase;

class UniqueSlugGeneratorTest extends TestCase
{
    public function test_sluggifies_a_latin_title(): void
    {
        $this->assertSame(
            'laravel-basics',
            $this->generator()->generate('Laravel Basics', Course::class),
        );
    }

    public function test_falls_back_to_a_uuid_when_slug_is_empty(): void
    {
        // '!!!' produces an empty Str::slug — the same situation as a
        // Cyrillic title on a machine without transliteration.
        $slug = $this->generator()->generate('!!!', Course::class);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $slug,
        );
        $this->assertNotSame('', $slug);
    }

    public function test_first_collision_appends_suffix_two(): void
    {
        Course::factory()->create(['slug' => 'laravel-basics']);

        $slug = $this->generator()->generate('Laravel Basics', Course::class);

        $this->assertSame('laravel-basics-2', $slug);
    }

    public function test_repeated_collisions_keep_incrementing_the_suffix(): void
    {
        Course::factory()->create(['slug' => 'laravel-basics']);
        Course::factory()->create(['slug' => 'laravel-basics-2']);
        Course::factory()->create(['slug' => 'laravel-basics-3']);

        $slug = $this->generator()->generate('Laravel Basics', Course::class);

        $this->assertSame('laravel-basics-4', $slug);
    }

    public function test_uniqueness_is_checked_against_the_given_model_only(): void
    {
        // A lesson holding the slug does not block a course slug.
        Lesson::factory()->create(['slug' => 'shared-slug']);

        $slug = $this->generator()->generate('Shared Slug', Course::class);

        $this->assertSame('shared-slug', $slug);
    }

    public function test_lesson_slugs_get_suffixed_on_collision_too(): void
    {
        Lesson::factory()->create(['slug' => 'intro-to-php']);

        $slug = $this->generator()->generate('Intro to PHP', Lesson::class);

        $this->assertSame('intro-to-php-2', $slug);
    }

    private function generator(): UniqueSlugGenerator
    {
        return app(UniqueSlugGenerator::class);
    }
}
