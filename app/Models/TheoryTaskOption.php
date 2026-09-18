<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TheoryTaskOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['theory_task_id', 'text', 'is_correct', 'error_text', 'order'])]
class TheoryTaskOption extends Model
{
    /** @use HasFactory<TheoryTaskOptionFactory> */
    use HasFactory;

    /**
     * The theory task the option belongs to.
     *
     * @return BelongsTo<TheoryTask, $this>
     */
    public function theoryTask(): BelongsTo
    {
        return $this->belongsTo(TheoryTask::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_correct' => 'bool',
        ];
    }
}
