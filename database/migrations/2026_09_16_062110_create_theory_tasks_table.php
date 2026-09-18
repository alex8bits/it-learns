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
        Schema::create('theory_tasks', function (Blueprint $table) {
            $table->id();
            // Content FK — cascade (project convention for the course tree,
            // see create_lessons_table): tasks are meaningless without the
            // parent lesson.
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->text('question');
            $table->unsignedInteger('order');
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->index(['lesson_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('theory_tasks');
    }
};
