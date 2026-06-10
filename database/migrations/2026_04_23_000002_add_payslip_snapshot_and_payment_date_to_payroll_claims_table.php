<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_claims', function (Blueprint $table) {
            $table->json('payslip_snapshot')->nullable()->after('overtime_rate_snapshot');
            $table->date('payment_date')->nullable()->after('payslip_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_claims', function (Blueprint $table) {
            $table->dropColumn(['payslip_snapshot', 'payment_date']);
        });
    }
};

