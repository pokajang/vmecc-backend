<?php

use Database\Seeders\InspectionFireTruckCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_fire_trucks', function (Blueprint $table) {
            $table->id();
            $table->string('plate_no', 40);
            $table->string('normalized_plate_no', 40);
            $table->string('name', 190)->nullable();
            $table->date('road_tax_expiry')->nullable();
            $table->date('insurance_expiry')->nullable();
            $table->date('puspakom_expiry')->nullable();
            $table->string('source', 40)->default('custom');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('normalized_plate_no', 'inspection_fire_trucks_plate_unique');
            $table->index(['is_active', 'source'], 'inspection_fire_trucks_active_source_idx');
        });

        app(InspectionFireTruckCatalogSeeder::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_fire_trucks');
    }
};
