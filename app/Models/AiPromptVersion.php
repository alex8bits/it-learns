<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiPromptVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable version of a prompt (global or course-scoped), identified
 * by `prompt_key`. The active version for a key is `MAX(version_number)`;
 * a rollback is a new version carrying an older body, never a mutation.
 */
#[Fillable(['prompt_key', 'body', 'version_number', 'comment', 'created_by', 'meta'])]
class AiPromptVersion extends Model
{
    /** @use HasFactory<AiPromptVersionFactory> */
    use HasFactory;

    /**
     * Prompt versions are append-only: no updated_at, only created_at.
     */
    public $timestamps = false;

    /**
     * The author of this version (NULL for system writes, e.g. the seeder).
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
            'version_number' => 'integer',
        ];
    }
}
