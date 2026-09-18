<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A level stops being a difficulty tier (2026-09-17): the `level`
     * enum column and its unique index go away, `title` becomes the
     * required per-course unique identity instead.
     */
    public function up(): void
    {
        Schema::table('levels', function (Blueprint $table) {
            // MariaDB refuses to drop the composite unique while the
            // `course_id` FK leans on it — carry the FK on a plain index
            // first; it is dropped at the end, once the new
            // (course_id, title) unique takes over.
            $table->index('course_id');
            $table->dropUnique('levels_course_id_level_unique');
        });

        // Backfill NULL titles before the NOT NULL tightening. The row id
        // guarantees the synthesized names never collide within a course.
        DB::table('levels')
            ->whereNull('title')
            ->update(['title' => DB::raw("CONCAT('Раздел ', id)")]);

        Schema::table('levels', function (Blueprint $table) {
            $table->string('title')->nullable(false)->change();
            $table->dropColumn('level');
            $table->unique(['course_id', 'title']);
            $table->dropIndex('levels_course_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Irreversible by design (forward-only migrations, rule #13): the
        // dropped `level` difficulty values cannot be reconstructed from
        // the free-form `title`, and restoring the (course_id, level)
        // unique index is impossible once a course has 3+ titled levels.
        throw new RuntimeException('The level difficulty drop is irreversible.');
    }
};
