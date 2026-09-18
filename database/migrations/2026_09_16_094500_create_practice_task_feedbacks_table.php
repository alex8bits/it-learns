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
        Schema::create('practice_task_feedbacks', function (Blueprint $table) {
            $table->id();
            // The unsuccessful attempt the AI feedback was generated for.
            $table->foreignId('practice_task_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            // Append-only AI feedback history: created_at only.
            $table->timestamp('created_at')->useCurrent();

            $table->index('practice_task_submission_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('practice_task_feedbacks');
    }
};
