<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->index(
                ['report_type', 'status', 'workflow_stage', 'next_action_role'],
                'reports_action_queue_idx',
            );
        });
        Schema::table('leaves', function (Blueprint $table) {
            $table->index(['status', 'workflow_stage', 'next_action_role'], 'leaves_action_queue_idx');
        });
        Schema::table('overtime_records', function (Blueprint $table) {
            $table->index(
                ['status', 'workflow_stage', 'next_action_role', 'user_id'],
                'overtime_action_queue_idx',
            );
        });
        Schema::table('payroll_claims', function (Blueprint $table) {
            $table->index(
                ['status', 'workflow_stage', 'next_action_role', 'claim_type'],
                'payroll_action_queue_idx',
            );
        });
        Schema::table('rosters', function (Blueprint $table) {
            $table->index(['status', 'date'], 'rosters_action_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::table('reports', fn (Blueprint $table) => $table->dropIndex('reports_action_queue_idx'));
        Schema::table('leaves', fn (Blueprint $table) => $table->dropIndex('leaves_action_queue_idx'));
        Schema::table('overtime_records', fn (Blueprint $table) => $table->dropIndex('overtime_action_queue_idx'));
        Schema::table('payroll_claims', fn (Blueprint $table) => $table->dropIndex('payroll_action_queue_idx'));
        Schema::table('rosters', fn (Blueprint $table) => $table->dropIndex('rosters_action_queue_idx'));
    }
};
