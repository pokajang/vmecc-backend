<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fitness_shadow_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->string('report_uid', 190)->index();
            $table->unsignedInteger('report_revision');
            $table->unsignedInteger('report_version');
            $table->char('payload_hash', 64);
            $table->char('projection_hash', 64);
            $table->string('status', 24)->default('matched');
            $table->json('mismatch_types')->nullable();
            $table->json('mismatch_details')->nullable();
            $table->timestamp('run_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('status', 'fitness_shadow_reconciliations_status_idx');
            $table->index('run_at', 'fitness_shadow_reconciliations_run_at_idx');
            $table->index(['report_id', 'run_at'], 'fitness_shadow_reconciliations_report_run_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fitness_shadow_reconciliations');
    }
};
