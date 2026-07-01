<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_check_rows', function (Blueprint $table) {
            $table->unsignedBigInteger('equipment_catalog_id')->nullable()->after('equipment_key');
            $table->string('equipment_source', 40)->default('seed')->after('equipment_catalog_id');
            $table->index(['equipment_catalog_id', 'check_key'], 'inspection_check_rows_equipment_catalog_check_idx');
            $table->index(['equipment_source', 'submitted_at'], 'inspection_check_rows_equipment_source_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inspection_check_rows', function (Blueprint $table) {
            $table->dropIndex('inspection_check_rows_equipment_catalog_check_idx');
            $table->dropIndex('inspection_check_rows_equipment_source_time_idx');
            $table->dropColumn(['equipment_catalog_id', 'equipment_source']);
        });
    }
};
