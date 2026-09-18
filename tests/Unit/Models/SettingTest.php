<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Setting;
use App\Models\User;
use Tests\TestCase;

class SettingTest extends TestCase
{
    public function test_find_by_key_finds_existing_row(): void
    {
        $setting = Setting::factory()->create([
            'key' => 'ai.global_system_prompt',
            'value' => 'You are a helpful tutor.',
        ]);

        $found = Setting::findByKey('ai.global_system_prompt');

        $this->assertNotNull($found);
        $this->assertTrue($found->is($setting));
        $this->assertSame('You are a helpful tutor.', $found->value);
    }

    public function test_find_by_key_returns_null_for_missing_key(): void
    {
        Setting::factory()->create(['key' => 'ai.global_system_prompt']);

        $this->assertNull(Setting::findByKey('ai.course_prompt'));
    }

    public function test_factory_creates_row_with_system_author_by_default(): void
    {
        $setting = Setting::factory()->create();

        $this->assertDatabaseHas('settings', [
            'id' => $setting->id,
            'key' => $setting->key,
            'updated_by' => null,
        ]);
    }

    public function test_updater_relation_returns_last_editing_user(): void
    {
        $user = User::factory()->create();
        $setting = Setting::factory()->create(['updated_by' => $user->id]);

        $this->assertTrue($setting->updater->is($user));
    }

    public function test_updater_relation_is_null_for_system_write(): void
    {
        $setting = Setting::factory()->create();

        $this->assertNull($setting->updater);
    }
}
