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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('tier', 32);
            $table->string('status', 32);
            $table->datetime('starts_at')->nullable();
            $table->datetime('ends_at')->nullable();
            $table->datetime('cancelled_at')->nullable();
            $table->string('external_id', 255)->nullable();
            $table->string('provider', 32)->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index('ends_at');
            $table->index(['external_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
