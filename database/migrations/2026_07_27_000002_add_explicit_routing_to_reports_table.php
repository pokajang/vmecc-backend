<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('next_action_user_id')
                ->nullable()
                ->after('next_action_role')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('next_action_duty_coverage_assignment_id')
                ->nullable()
                ->after('next_action_user_id')
                ->constrained('duty_coverage_assignments')
                ->nullOnDelete();
            $table->string('routing_reason_code', 80)
                ->nullable()
                ->after('next_action_duty_coverage_assignment_id');

            $table->index(
                ['status', 'workflow_stage', 'next_action_user_id'],
                'reports_explicit_action_queue_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex('reports_explicit_action_queue_idx');
            $table->dropConstrainedForeignId('next_action_duty_coverage_assignment_id');
            $table->dropConstrainedForeignId('next_action_user_id');
            $table->dropColumn('routing_reason_code');
        });
    }
};
