<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'inspection_fire_extinguishers_active_location_sort_idx';

    public function up(): void
    {
        Schema::table('inspection_fire_extinguishers', function (Blueprint $table) {
            $table->index(
                ['is_active', 'zone', 'main_location_name', 'sub_location_name', 'sort_order'],
                self::INDEX_NAME,
            );
        });
    }

    public function down(): void
    {
        Schema::table('inspection_fire_extinguishers', function (Blueprint $table) {
            $table->dropIndex(self::INDEX_NAME);
        });
    }
};
