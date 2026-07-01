<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_onboarding_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->string('version', 40);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key', 'version'], 'user_onboarding_state_unique');
            $table->index(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_onboarding_states');
    }
};
