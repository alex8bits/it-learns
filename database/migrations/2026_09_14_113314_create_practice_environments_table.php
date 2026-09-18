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
        Schema::create('practice_environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // No FK: the practice_tasks table does not exist until Stages 7-8
            // (nullable by design).
            $table->unsignedBigInteger('practice_task_id')->nullable();
            $table->string('runtime', 32);
            $table->string('status', 32);
            $table->json('connection_meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('destroyed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('practice_environments');
    }
};
