<?php

declare(strict_types=1);

use App\Enums\PracticeRuntime;
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
        Schema::table('practice_tasks', function (Blueprint $table) {
            // Per-task runtime (Stage 10): existing tasks keep running on
            // the local SQLite driver — the default backfills them without
            // touching stored hashes or content.
            $table->string('runtime', 32)->default(PracticeRuntime::Sqlite->value)->after('is_published');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('practice_tasks', function (Blueprint $table) {
            $table->dropColumn('runtime');
        });
    }
};
