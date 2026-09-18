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
        Schema::create('levels', function (Blueprint $table) {
            $table->id();
            // Cascade (an intentional deviation from the project-wide
            // restrictOnDelete convention): levels are meaningless
            // without their parent course, so deleting a course removes
            // its level tree in one statement (Stage 6 design decision).
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('level', 32);
            $table->unsignedInteger('order');
            $table->string('title')->nullable();

            $table->unique(['course_id', 'level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('levels');
    }
};
