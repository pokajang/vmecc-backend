<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fitness_test_checkpoint_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fitness_test_participant_result_id')->constrained('fitness_test_participant_results')->cascadeOnDelete();
            $table->string('checkpoint_code', 64);
            $table->boolean('completed')->default(false);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedSmallInteger('attempts')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['fitness_test_participant_result_id', 'checkpoint_code'],
                'fitness_checkpoint_results_participant_checkpoint_unique',
            );
            $table->index(['checkpoint_code', 'completed'], 'fitness_checkpoint_results_checkpoint_completed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fitness_test_checkpoint_results');
    }
};
