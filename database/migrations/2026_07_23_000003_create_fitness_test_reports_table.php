<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fitness_test_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->string('reporting_month', 7)->nullable()->index();
            $table->string('document_reference', 190)->nullable();
            $table->string('protocol_revision', 64)->nullable();
            $table->unsignedInteger('participant_count')->default(0);
            $table->unsignedInteger('passed_assessment_count')->default(0);
            $table->unsignedInteger('failed_assessment_count')->default(0);
            $table->unsignedInteger('incomplete_assessment_count')->default(0);
            $table->timestamps();

            $table->unique('report_id', 'fitness_test_reports_report_id_unique');
            $table->index('reporting_month', 'fitness_test_reports_reporting_month_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fitness_test_reports');
    }
};
