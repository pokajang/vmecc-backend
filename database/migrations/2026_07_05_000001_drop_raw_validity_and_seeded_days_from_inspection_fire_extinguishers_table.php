<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_fire_extinguishers', function (Blueprint $table) {
            if (Schema::hasColumn('inspection_fire_extinguishers', 'certification_validity_raw')) {
                $table->dropColumn('certification_validity_raw');
            }
            if (Schema::hasColumn('inspection_fire_extinguishers', 'days_left_to_expire')) {
                $table->dropColumn('days_left_to_expire');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inspection_fire_extinguishers', function (Blueprint $table) {
            if (! Schema::hasColumn('inspection_fire_extinguishers', 'certification_validity_raw')) {
                $table->string('certification_validity_raw', 120)->nullable()->after('certification_validity');
            }
            if (! Schema::hasColumn('inspection_fire_extinguishers', 'days_left_to_expire')) {
                $table->string('days_left_to_expire', 60)->nullable()->after('certification_validity_raw');
            }
        });
    }
};
