<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AiPromptVersion;
use App\Models\Setting;
use App\Services\Ai\PromptKeys;
use Illuminate\Database\Seeder;

class AiPromptSeeder extends Seeder
{
    /**
     * Default placeholder text of the global system prompt seeded on a
     * fresh install; admins replace it through the prompt editor.
     */
    private const string DEFAULT_GLOBAL_PROMPT = 'Ты — ИИ-ассистент образовательной платформы it-learns. Отвечай понятно, по делу, на языке пользователя.';

    /**
     * Seed the default global AI prompt: the fast-read `settings` cache row
     * plus the initial v1 record in `ai_prompt_versions`.
     *
     * Idempotent — existing rows are never overwritten on re-run, and
     * partial states are healed: `ai_prompt_versions` is the source of
     * truth, so when only the `settings` row exists v1 adopts the cached
     * text (the cache survives untouched), and when only versions exist
     * the missing cache row is backfilled with the active (MAX
     * version_number) body. A fresh install gets the default text in both.
     */
    public function run(): void
    {
        $setting = Setting::findByKey(PromptKeys::GLOBAL_SYSTEM_PROMPT);

        $latestVersion = AiPromptVersion::query()
            ->where('prompt_key', PromptKeys::GLOBAL_SYSTEM_PROMPT)
            ->orderByDesc('version_number')
            ->first();

        if ($setting === null && $latestVersion === null) {
            Setting::create([
                'key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
                'value' => self::DEFAULT_GLOBAL_PROMPT,
                'updated_by' => null,
            ]);

            AiPromptVersion::create([
                'prompt_key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
                'body' => self::DEFAULT_GLOBAL_PROMPT,
                'version_number' => 1,
                'comment' => 'Initial seed',
                'created_by' => null,
            ]);

            return;
        }

        if ($latestVersion === null) {
            AiPromptVersion::create([
                'prompt_key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
                'body' => (string) $setting->value,
                'version_number' => 1,
                'comment' => 'Initial seed',
                'created_by' => null,
            ]);

            return;
        }

        if ($setting === null) {
            Setting::create([
                'key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
                'value' => (string) $latestVersion->body,
                'updated_by' => null,
            ]);
        }
    }
}
