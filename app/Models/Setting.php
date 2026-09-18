<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A keyed application setting. In Stage 4 the only consumer is the cached
 * active global AI prompt (`ai.global_system_prompt`), whose source of
 * truth lives in `ai_prompt_versions`; this table holds the fast-read copy.
 */
#[Fillable(['key', 'value', 'updated_by'])]
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    /**
     * Find a setting row by its unique key.
     */
    public static function findByKey(string $key): ?self
    {
        return static::query()->where('key', $key)->first();
    }

    /**
     * The user that last updated this setting (NULL for system writes,
     * e.g. the seeder).
     *
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
