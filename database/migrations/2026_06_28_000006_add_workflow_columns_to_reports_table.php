<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('workflow_stage', 64)->nullable()->after('status')->index('reports_workflow_stage_idx');
            $table->json('workflow_snapshot')->nullable()->after('workflow_stage');
            $table->string('next_action_role', 190)->nullable()->after('workflow_snapshot')->index('reports_next_action_role_idx');
            $table->json('approval_history')->nullable()->after('next_action_role');
            $table->foreignId('scope_team_id')->nullable()->after('approval_history')->constrained('teams')->nullOnDelete();

            $table->index(['report_type', 'workflow_stage'], 'reports_type_workflow_stage_idx');
            $table->index(['scope_team_id', 'next_action_role'], 'reports_scope_next_role_idx');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex('reports_workflow_stage_idx');
            $table->dropIndex('reports_next_action_role_idx');
            $table->dropIndex('reports_type_workflow_stage_idx');
            $table->dropIndex('reports_scope_next_role_idx');
            $table->dropConstrainedForeignId('scope_team_id');
            $table->dropColumn([
                'workflow_stage',
                'workflow_snapshot',
                'next_action_role',
                'approval_history',
            ]);
        });
    }
};
