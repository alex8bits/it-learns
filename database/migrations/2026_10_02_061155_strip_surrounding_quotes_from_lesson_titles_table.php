<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Data-only fix (2026-10-02): until now MysqlCourseSeeder kept
     * the surrounding YAML quotes of `title: "…"` in `lessons.title`,
     * so the seeded MySQL lessons show up wrapped in quotes. The
     * parser strips them since today; this migration cleans the rows
     * seeded by the older parser. The table holds a few dozen rows,
     * so a plain per-row walk without chunking is justified.
     */
    public function up(): void
    {
        foreach (DB::table('lessons')->select('id', 'title')->get() as $lesson) {
            $title = (string) $lesson->title;

            // The same single-layer rule MysqlCourseSeeder::splitFrontmatter()
            // applies when parsing: a paired `"`/`'` around the WHOLE value
            // is stripped; quotes inside the value and unpaired ones stay.
            if (strlen($title) >= 2
                && ($title[0] === '"' || $title[0] === "'")
                && $title[0] === substr($title, -1)
            ) {
                DB::table('lessons')
                    ->where('id', $lesson->id)
                    ->update(['title' => substr($title, 1, -1)]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: whether a stored title was originally YAML-quoted cannot
        // be reconstructed deterministically (a legitimately unquoted
        // title would gain a wrong quote layer), so re-quoting is not a
        // rollback.
    }
};
