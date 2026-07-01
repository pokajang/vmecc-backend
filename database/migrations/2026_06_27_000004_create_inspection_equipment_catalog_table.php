<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_equipment', function (Blueprint $table) {
            $table->id();
            $table->string('inspection_type_key', 120);
            $table->string('inspection_type_label', 190);
            $table->foreignId('main_location_id')->nullable()->constrained('inspection_locations')->nullOnDelete();
            $table->string('main_location_name', 190);
            $table->string('name', 190);
            $table->string('normalized_name', 190);
            $table->string('description', 500)->nullable();
            $table->string('source', 40)->default('custom');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['inspection_type_key', 'main_location_name', 'sort_order'], 'inspection_equipment_type_location_sort_idx');
            $table->index(['inspection_type_key', 'main_location_name', 'normalized_name'], 'inspection_equipment_type_location_name_idx');
            $table->index(['is_active', 'source'], 'inspection_equipment_active_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_equipment');
    }
};
