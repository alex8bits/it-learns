<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\AiPromptVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiPromptVersionTest extends TestCase
{
    public function test_meta_is_cast_to_array(): void
    {
        $version = AiPromptVersion::factory()->create([
            'meta' => ['source' => 'editor'],
        ]);

        $this->assertSame(['source' => 'editor'], $version->meta);
        $this->assertIsArray($version->fresh()->meta);
    }

    public function test_version_number_is_cast_to_integer(): void
    {
        $version = AiPromptVersion::factory()->create(['version_number' => 7]);

        $this->assertSame(7, $version->fresh()->version_number);
    }

    public function test_created_at_is_cast_to_datetime(): void
    {
        $version = AiPromptVersion::factory()->create();

        $this->assertInstanceOf(Carbon::class, $version->fresh()->created_at);
    }

    public function test_author_relation_returns_creating_user(): void
    {
        $author = User::factory()->create();
        $version = AiPromptVersion::factory()->create(['created_by' => $author->id]);

        $this->assertTrue($version->author->is($author));
    }

    public function test_author_relation_is_null_for_system_version(): void
    {
        $version = AiPromptVersion::factory()->authoredBySystem()->create();

        $this->assertNull($version->author);
        $this->assertDatabaseHas('ai_prompt_versions', [
            'id' => $version->id,
            'created_by' => null,
        ]);
    }

    public function test_table_is_append_only_without_updated_at(): void
    {
        $this->assertFalse(Schema::hasColumn('ai_prompt_versions', 'updated_at'));
        $this->assertTrue(Schema::hasColumn('ai_prompt_versions', 'created_at'));
    }

    public function test_duplicate_prompt_key_and_version_number_is_rejected(): void
    {
        AiPromptVersion::factory()->create([
            'prompt_key' => 'ai.global_system_prompt',
            'version_number' => 2,
        ]);

        $this->expectException(QueryException::class);

        AiPromptVersion::factory()->create([
            'prompt_key' => 'ai.global_system_prompt',
            'version_number' => 2,
        ]);
    }

    public function test_same_version_number_with_different_prompt_key_is_allowed(): void
    {
        AiPromptVersion::factory()->create([
            'prompt_key' => 'ai.global_system_prompt',
            'version_number' => 1,
        ]);

        $version = AiPromptVersion::factory()->create([
            'prompt_key' => 'course.42.ai_course_prompt',
            'version_number' => 1,
        ]);

        $this->assertDatabaseHas('ai_prompt_versions', [
            'id' => $version->id,
            'prompt_key' => 'course.42.ai_course_prompt',
            'version_number' => 1,
        ]);
    }
}
