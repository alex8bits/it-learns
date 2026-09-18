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
        Schema::create('theory_task_options', function (Blueprint $table) {
            $table->id();
            // Content FK — cascade (project convention for the course tree):
            // options are meaningless without the parent task.
            $table->foreignId('theory_task_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->boolean('is_correct');
            $table->text('error_text')->nullable();
            $table->unsignedInteger('order');
            $table->timestamps();

            $table->index(['theory_task_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('theory_task_options');
    }
};
