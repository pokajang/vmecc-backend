<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_check_rows', function (Blueprint $table): void {
            $table->index(
                ['inspection_type_key', 'source_payload_key', 'equipment_catalog_id', 'submitted_at'],
                'inspection_check_rows_fe_coverage_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('inspection_check_rows', function (Blueprint $table): void {
            $table->dropIndex('inspection_check_rows_fe_coverage_idx');
        });
    }
};
