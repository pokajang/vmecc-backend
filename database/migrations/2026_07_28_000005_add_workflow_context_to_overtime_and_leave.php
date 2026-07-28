<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_records', function (Blueprint $table): void {
            $table->foreignId('workflow_team_id')->nullable()->after('workflow_snapshot')->constrained('teams')->nullOnDelete();
            $table->string('workflow_team_name')->nullable()->after('workflow_team_id');
            $table->string('workflow_applicant_role')->nullable()->after('workflow_team_name');
            $table->string('workflow_routing_source', 64)->nullable()->after('workflow_applicant_role');
            $table->foreignId('duty_coverage_assignment_id')->nullable()->after('workflow_routing_source')->constrained('duty_coverage_assignments')->nullOnDelete();
            $table->index(['status', 'workflow_stage', 'workflow_team_id'], 'overtime_workflow_team_idx');
        });

        Schema::table('leaves', function (Blueprint $table): void {
            $table->foreignId('workflow_team_id')->nullable()->after('workflow_snapshot')->constrained('teams')->nullOnDelete();
            $table->string('workflow_team_name')->nullable()->after('workflow_team_id');
            $table->string('workflow_applicant_role')->nullable()->after('workflow_team_name');
            $table->string('workflow_routing_source', 64)->nullable()->after('workflow_applicant_role');
            $table->foreignId('duty_coverage_assignment_id')->nullable()->after('workflow_routing_source')->constrained('duty_coverage_assignments')->nullOnDelete();
            $table->index(['status', 'workflow_stage', 'workflow_team_id'], 'leaves_workflow_team_idx');
        });
    }

    public function down(): void
    {
        Schema::table('overtime_records', function (Blueprint $table): void {
            $table->dropIndex('overtime_workflow_team_idx');
            $table->dropConstrainedForeignId('duty_coverage_assignment_id');
            $table->dropColumn(['workflow_routing_source', 'workflow_applicant_role', 'workflow_team_name']);
            $table->dropConstrainedForeignId('workflow_team_id');
        });

        Schema::table('leaves', function (Blueprint $table): void {
            $table->dropIndex('leaves_workflow_team_idx');
            $table->dropConstrainedForeignId('duty_coverage_assignment_id');
            $table->dropColumn(['workflow_routing_source', 'workflow_applicant_role', 'workflow_team_name']);
            $table->dropConstrainedForeignId('workflow_team_id');
        });
    }
};
