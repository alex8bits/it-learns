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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('RUB');
            $table->string('status', 32);
            $table->string('external_id', 255);
            $table->string('provider', 32);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index('user_id');
            $table->index('status');
            $table->index('created_at');
            $table->index('subscription_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
