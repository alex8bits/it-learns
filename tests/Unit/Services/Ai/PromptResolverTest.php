<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\PromptKeys;
use App\Services\Ai\PromptResolver;
use App\Services\Ai\PromptVersionService;
use Tests\TestCase;

class PromptResolverTest extends TestCase
{
    public function test_resolves_global_only_when_no_course_prompt_given(): void
    {
        $this->createGlobalSetting('Global prompt text');

        $resolved = app(PromptResolver::class)->resolve();

        $this->assertSame('Global prompt text', $resolved);
    }

    public function test_resolves_course_only_when_settings_row_is_missing(): void
    {
        $resolved = app(PromptResolver::class)->resolve('Course prompt text');

        $this->assertSame('Course prompt text', $resolved);
    }

    public function test_joins_global_and_course_with_double_newline(): void
    {
        $this->createGlobalSetting('Global prompt text');

        $resolved = app(PromptResolver::class)->resolve('Course prompt text');

        $this->assertSame("Global prompt text\n\nCourse prompt text", $resolved);
    }

    public function test_empty_global_value_falls_back_to_course_prompt(): void
    {
        $this->createGlobalSetting('');

        $resolved = app(PromptResolver::class)->resolve('Course prompt text');

        $this->assertSame('Course prompt text', $resolved);
    }

    public function test_returns_empty_string_when_both_parts_are_empty(): void
    {
        $resolved = app(PromptResolver::class)->resolve();

        $this->assertSame('', $resolved);
    }

    public function test_returns_empty_string_when_global_row_is_missing_and_course_is_empty(): void
    {
        $resolved = app(PromptResolver::class)->resolve('');

        $this->assertSame('', $resolved);
    }

    public function test_ignores_empty_course_prompt_when_global_is_present(): void
    {
        $this->createGlobalSetting('Global prompt text');

        $resolved = app(PromptResolver::class)->resolve('');

        $this->assertSame('Global prompt text', $resolved);
    }

    public function test_resolver_reads_the_cache_row_written_by_the_version_service(): void
    {
        $author = User::factory()->create();
        app(PromptVersionService::class)->createNewVersion(
            PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'Service-written prompt',
            'test',
            $author,
        );

        $resolved = app(PromptResolver::class)->resolve();

        $this->assertSame('Service-written prompt', $resolved);
    }

    private function createGlobalSetting(string $value): void
    {
        Setting::factory()->create([
            'key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'value' => $value,
        ]);
    }
}
