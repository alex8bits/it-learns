<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiTokenUsageAction;
use Database\Factories\AiTokenUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single recorded LLM token spend by a user (append-only ledger consumed
 * by the daily limit aggregation).
 */
#[Fillable(['user_id', 'model', 'tokens', 'action'])]
class AiTokenUsage extends Model
{
    /** @use HasFactory<AiTokenUsageFactory> */
    use HasFactory;

    /**
     * Token usage is append-only: no updated_at, only created_at.
     */
    public $timestamps = false;

    /**
     * The user that spent the tokens.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AiTokenUsageAction::class,
            'created_at' => 'datetime',
            'tokens' => 'integer',
        ];
    }
}
