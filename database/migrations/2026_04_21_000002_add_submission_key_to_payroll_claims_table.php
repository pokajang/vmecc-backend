<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_claims', function (Blueprint $table) {
            $table->string('submission_key', 190)->nullable()->after('display_id');
            $table->unique(['user_id', 'submission_key'], 'payroll_claims_user_submission_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_claims', function (Blueprint $table) {
            $table->dropUnique('payroll_claims_user_submission_unique');
            $table->dropColumn('submission_key');
        });
    }
};

