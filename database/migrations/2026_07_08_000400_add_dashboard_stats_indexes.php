<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index('status', 'users_status_idx');
        });

        Schema::table('salary_assignments', function (Blueprint $table) {
            $table->index('status', 'salary_assignments_status_idx');
        });

        Schema::table('payroll_claims', function (Blueprint $table) {
            $table->index('submitted_at', 'payroll_claims_submitted_at_idx');
            $table->index(['status', 'paid_at'], 'payroll_claims_status_paid_at_idx');
            $table->index(['status', 'user_id'], 'payroll_claims_status_user_idx');
        });

        Schema::table('overtime_records', function (Blueprint $table) {
            $table->index('claim_date', 'overtime_records_claim_date_idx');
            $table->index(['status', 'claim_date'], 'overtime_records_status_claim_date_idx');
            $table->index(['status', 'user_id'], 'overtime_records_status_user_idx');
        });

        Schema::table('leaves', function (Blueprint $table) {
            $table->index('start_date', 'leaves_start_date_idx');
            $table->index(['status', 'start_date', 'end_date'], 'leaves_status_date_span_idx');
            $table->index(['status', 'user_id'], 'leaves_status_user_idx');
        });
    }

    public function down(): void
    {
        Schema::table('salary_assignments', function (Blueprint $table) {
            $table->dropIndex('salary_assignments_status_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_status_idx');
        });

        Schema::table('leaves', function (Blueprint $table) {
            $table->dropIndex('leaves_status_user_idx');
            $table->dropIndex('leaves_status_date_span_idx');
            $table->dropIndex('leaves_start_date_idx');
        });

        Schema::table('overtime_records', function (Blueprint $table) {
            $table->dropIndex('overtime_records_status_user_idx');
            $table->dropIndex('overtime_records_status_claim_date_idx');
            $table->dropIndex('overtime_records_claim_date_idx');
        });

        Schema::table('payroll_claims', function (Blueprint $table) {
            $table->dropIndex('payroll_claims_status_user_idx');
            $table->dropIndex('payroll_claims_status_paid_at_idx');
            $table->dropIndex('payroll_claims_submitted_at_idx');
        });
    }
};
