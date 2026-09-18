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
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            // Cascade (an intentional deviation from the project-wide
            // restrictOnDelete convention): lessons are meaningless
            // without their parent level, so the whole course tree is
            // removed in one statement (Stage 6 design decision).
            $table->foreignId('level_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('title');
            $table->unsignedInteger('order');
            $table->longText('material')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->index('level_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
