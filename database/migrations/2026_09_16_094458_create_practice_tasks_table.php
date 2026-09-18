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
        Schema::create('practice_tasks', function (Blueprint $table) {
            $table->id();
            // Content FK — cascade (project convention for the course tree,
            // see create_theory_tasks_table): tasks are meaningless without
            // the parent lesson.
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->text('statement');
            // Human-readable expected outcome, shown to the student.
            $table->text('expected_result_text');
            // Author-provided expected rows (JSON); used for the Failed diff
            // and admin form repopulation. Never leaves the server in the
            // lesson props (spoiler guard).
            $table->json('expected_rows');
            // Trusted multi-statement DDL/DML executed on provision.
            $table->longText('seed_sql')->nullable();
            // sha256 of the canonical expected rows (CanonicalResultSerializer);
            // derived server-side, never accepted from the client.
            $table->char('expected_hash', 64);
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
        Schema::dropIfExists('practice_tasks');
    }
};
