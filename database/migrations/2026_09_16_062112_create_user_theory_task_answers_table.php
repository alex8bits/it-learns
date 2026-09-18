<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `option_id` cascade — an intentional deviation from the Stage 7 design
     * draft (which assumed `restrict`): the admin task update replaces the
     * whole option set (`UpdateTheoryTask`), and `restrict` would block
     * editing any task that already has answers. Domain semantics: changing
     * the options resets the answers to that task (the user re-answers),
     * which is correct for a quiz.
     */
    public function up(): void
    {
        Schema::create('user_theory_task_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('theory_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('theory_task_options')->cascadeOnDelete();
            $table->boolean('is_correct');
            // No standard timestamps: answered_at is the only meaningful
            // date (see AdminAuditLog for the same pattern).
            $table->timestamp('answered_at')->useCurrent();

            // One answer per (user, task): a re-answer overwrites via upsert.
            $table->unique(['user_id', 'theory_task_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_theory_task_answers');
    }
};
