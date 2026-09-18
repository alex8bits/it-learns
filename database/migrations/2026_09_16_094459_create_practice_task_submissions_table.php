<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('practice_task_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Deleting a task removes its attempts — the same semantics as
            // theory answers (content FK cascade).
            $table->foreignId('practice_task_id')->constrained()->cascadeOnDelete();
            $table->text('code');
            // PracticeAttemptStatus values (passed/failed/error/busy; busy is
            // never persisted — Stage 8 writes no row for a lost lock race).
            $table->string('status', 32);
            // For Failed attempts: {expected: rows, actual: rows}.
            $table->json('result_diff')->nullable();
            $table->text('error_text')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            // Append-only attempt history: created_at only, no updated_at
            // (unlike the upserted theory answers). No unique constraint —
            // attempts are unlimited, only the lesson transition is gated.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'practice_task_id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('practice_task_submissions');
    }
};
