<?php

declare(strict_types=1);

namespace Tests\Unit\Seeders;

use App\Models\AiPromptVersion;
use App\Models\Setting;
use App\Services\Ai\PromptKeys;
use Database\Seeders\AiPromptSeeder;
use Tests\TestCase;

class AiPromptSeederTest extends TestCase
{
    public function test_first_run_creates_settings_row_and_initial_version(): void
    {
        $this->seed(AiPromptSeeder::class);

        $setting = Setting::findByKey(PromptKeys::GLOBAL_SYSTEM_PROMPT);

        $this->assertNotNull($setting);
        $this->assertNotSame('', $setting->value);
        $this->assertNull($setting->updated_by);

        $version = AiPromptVersion::query()
            ->where('prompt_key', PromptKeys::GLOBAL_SYSTEM_PROMPT)
            ->firstOrFail();

        $this->assertSame(1, $version->version_number);
        $this->assertSame('Initial seed', $version->comment);
        $this->assertNull($version->created_by);
        // The cache row and the v1 record must advertise the same text.
        $this->assertSame($setting->value, $version->body);
    }

    public function test_repeated_run_does_not_duplicate_rows(): void
    {
        $this->seed(AiPromptSeeder::class);
        $this->seed(AiPromptSeeder::class);

        $this->assertDatabaseCount('settings', 1);
        $this->assertSame(1, AiPromptVersion::query()
            ->where('prompt_key', PromptKeys::GLOBAL_SYSTEM_PROMPT)
            ->count());
    }

    public function test_partial_state_with_settings_but_no_version_backfills_the_version(): void
    {
        Setting::factory()->create([
            'key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'value' => 'Custom already-seeded text',
            'updated_by' => null,
        ]);

        $this->seed(AiPromptSeeder::class);

        $this->assertDatabaseCount('settings', 1);
        // The existing custom value must survive the re-run untouched.
        $this->assertSame('Custom already-seeded text', Setting::findByKey(PromptKeys::GLOBAL_SYSTEM_PROMPT)?->value);

        $version = AiPromptVersion::query()
            ->where('prompt_key', PromptKeys::GLOBAL_SYSTEM_PROMPT)
            ->firstOrFail();

        // v1 adopts the actual cached text so MAX(version_number) and the
        // `settings` row never diverge after the heal.
        $this->assertSame(1, $version->version_number);
        $this->assertSame('Custom already-seeded text', $version->body);
        $this->assertSame('Initial seed', $version->comment);
        $this->assertNull($version->created_by);
    }

    public function test_partial_state_with_version_but_no_settings_backfills_the_settings_row(): void
    {
        AiPromptVersion::factory()->authoredBySystem()->create([
            'prompt_key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'body' => 'Тело v1',
            'version_number' => 1,
            'comment' => 'Initial seed',
        ]);
        AiPromptVersion::factory()->authoredBySystem()->create([
            'prompt_key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'body' => 'Тело v2 — активная версия',
            'version_number' => 2,
            'comment' => 'Вторая правка',
        ]);

        $this->seed(AiPromptSeeder::class);

        $setting = Setting::findByKey(PromptKeys::GLOBAL_SYSTEM_PROMPT);

        $this->assertNotNull($setting);
        // Versions are the source of truth: the cache mirrors the active
        // (MAX version_number) version body.
        $this->assertSame('Тело v2 — активная версия', $setting->value);
        $this->assertNull($setting->updated_by);
        $this->assertSame(2, AiPromptVersion::query()
            ->where('prompt_key', PromptKeys::GLOBAL_SYSTEM_PROMPT)
            ->count());
    }
}
