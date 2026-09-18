<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserLlmLimitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user adjustment on top of the global daily LLM token limit:
 * `extra_tokens` are tokens manually granted (or retracted) by an admin.
 */
#[Fillable(['user_id', 'extra_tokens'])]
class UserLlmLimit extends Model
{
    /** @use HasFactory<UserLlmLimitFactory> */
    use HasFactory;

    /**
     * The key is an existing user id, not an auto-generated sequence value.
     */
    public $incrementing = false;

    /**
     * One row per user: the PK is the `user_id` FK itself, not a surrogate
     * auto-increment id.
     */
    protected $primaryKey = 'user_id';

    /**
     * @var 'int'
     */
    protected $keyType = 'int';

    /**
     * The user this limit adjustment belongs to.
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
            'extra_tokens' => 'integer',
        ];
    }
}
