<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PracticeEnvironmentStatus;
use App\Enums\PracticeRuntime;
use Database\Factories\PracticeEnvironmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'practice_task_id', 'runtime', 'status', 'connection_meta', 'started_at', 'error'])]
class PracticeEnvironment extends Model
{
    /** @use HasFactory<PracticeEnvironmentFactory> */
    use HasFactory;

    /**
     * The user the environment was provisioned for. `destroyed_at` is not
     * fillable on purpose: it is set by the practice environment manager
     * when destroying the environment, never via mass assignment.
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
            'runtime' => PracticeRuntime::class,
            'status' => PracticeEnvironmentStatus::class,
            'connection_meta' => 'array',
            'started_at' => 'datetime',
            'destroyed_at' => 'datetime',
        ];
    }
}
