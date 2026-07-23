<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fitness_test_participant_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fitness_test_shift_group_id')->constrained('fitness_test_shift_groups')->cascadeOnDelete();
            $table->string('source_participant_uid', 190)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('participant_name_snapshot', 190)->nullable();
            $table->string('role_snapshot', 190)->nullable();
            $table->unsignedSmallInteger('age_snapshot')->nullable();
            $table->string('source', 80)->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamp('fitness_tested_on')->nullable();
            $table->unsignedSmallInteger('sit_ups')->nullable();
            $table->unsignedSmallInteger('jumping_jacks')->nullable();
            $table->unsignedSmallInteger('push_ups')->nullable();
            $table->string('fitness_result', 24)->nullable();
            $table->timestamp('proficiency_tested_on')->nullable();
            $table->unsignedInteger('proficiency_duration_seconds')->nullable();
            $table->string('proficiency_result', 24)->nullable();
            $table->timestamps();

            $table->index('user_id', 'fitness_participant_results_user_id_idx');
            $table->index('fitness_tested_on', 'fitness_participant_results_fitness_tested_on_idx');
            $table->index('proficiency_tested_on', 'fitness_participant_results_proficiency_tested_on_idx');
            $table->index('fitness_result', 'fitness_participant_results_fitness_result_idx');
            $table->index('proficiency_result', 'fitness_participant_results_proficiency_result_idx');
            $table->index(['user_id', 'fitness_tested_on'], 'fitness_participant_results_user_fitness_tested_on_idx');
            $table->index(['fitness_test_shift_group_id', 'display_order'], 'fitness_participant_results_shift_display_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fitness_test_participant_results');
    }
};
