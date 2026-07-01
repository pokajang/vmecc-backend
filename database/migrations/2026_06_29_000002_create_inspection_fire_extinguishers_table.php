<?php

use Database\Seeders\InspectionFireExtinguisherCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_fire_extinguishers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('source_row_number')->nullable();
            $table->string('zone', 80)->nullable();
            $table->string('main_location_name', 190);
            $table->string('sub_location_name', 190)->nullable();
            $table->string('id_loc_no', 190)->nullable();
            $table->string('barcode_no', 190)->nullable();
            $table->string('fe_type', 120)->nullable();
            $table->date('certification_validity')->nullable();
            $table->string('certification_validity_raw', 120)->nullable();
            $table->string('days_left_to_expire', 60)->nullable();
            $table->string('source', 40)->default('custom');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('source_row_number', 'inspection_fire_extinguishers_source_row_unique');
            $table->index(['main_location_name', 'sub_location_name'], 'inspection_fire_extinguishers_location_idx');
            $table->index(['barcode_no'], 'inspection_fire_extinguishers_barcode_idx');
            $table->index(['id_loc_no'], 'inspection_fire_extinguishers_id_loc_idx');
            $table->index(['is_active', 'source'], 'inspection_fire_extinguishers_active_source_idx');
        });

        app(InspectionFireExtinguisherCatalogSeeder::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_fire_extinguishers');
    }
};
