<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_check_rows', function (Blueprint $table) {
            $table->boolean('has_evidence')->default(false)->after('has_defect');
            $table->unsignedInteger('evidence_count')->default(0)->after('has_evidence');
            $table->index(['has_evidence', 'submitted_at'], 'inspection_check_rows_evidence_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inspection_check_rows', function (Blueprint $table) {
            $table->dropIndex('inspection_check_rows_evidence_time_idx');
            $table->dropColumn(['has_evidence', 'evidence_count']);
        });
    }
};
