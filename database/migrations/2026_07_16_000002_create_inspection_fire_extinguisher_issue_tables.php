<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_fire_extinguisher_issues', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('fire_extinguisher_id')->constrained('inspection_fire_extinguishers')->restrictOnDelete();
            $table->string('check_key', 120);
            $table->string('check_name', 190);
            $table->string('status', 32)->default('open');
            $table->string('severity', 24)->default('medium');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->text('corrective_action')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('active_key', 255)->nullable()->unique();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['fire_extinguisher_id', 'status'], 'inspection_fe_issues_asset_status_idx');
            $table->index(['assigned_to_user_id', 'status'], 'inspection_fe_issues_assignee_status_idx');
            $table->index(['status', 'due_at'], 'inspection_fe_issues_status_due_idx');
        });

        Schema::create('inspection_fire_extinguisher_issue_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('issue_id')->constrained('inspection_fire_extinguisher_issues')->cascadeOnDelete();
            $table->foreignId('inspection_check_row_id');
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->string('check_value', 120);
            $table->text('remarks')->nullable();
            $table->unsignedInteger('evidence_count')->default(0);
            $table->timestamp('detected_at');
            $table->timestamps();
            $table->unique('inspection_check_row_id', 'inspection_fe_issue_occurrences_check_unique');
            $table->foreign('inspection_check_row_id', 'inspection_fe_issue_occurrences_check_fk')
                ->references('id')->on('inspection_check_rows')->cascadeOnDelete();
            $table->unique(['issue_id', 'report_id'], 'inspection_fe_issue_occurrences_report_unique');
            $table->index(['issue_id', 'detected_at'], 'inspection_fe_issue_occurrences_time_idx');
        });

        Schema::create('inspection_fire_extinguisher_issue_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('issue_id')->constrained('inspection_fire_extinguisher_issues')->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['issue_id', 'created_at'], 'inspection_fe_issue_events_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_fire_extinguisher_issue_events');
        Schema::dropIfExists('inspection_fire_extinguisher_issue_occurrences');
        Schema::dropIfExists('inspection_fire_extinguisher_issues');
    }
};
