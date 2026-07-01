<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('inspection_locations')->cascadeOnDelete();
            $table->string('name', 190);
            $table->string('normalized_name', 190);
            $table->string('description', 500)->nullable();
            $table->string('icon_key', 80)->nullable();
            $table->string('source', 40)->default('custom');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['parent_id', 'normalized_name'], 'inspection_locations_parent_name_idx');
            $table->index(['is_active', 'source'], 'inspection_locations_active_source_idx');
        });

        Schema::create('inspection_location_type_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_location_id')->constrained('inspection_locations')->cascadeOnDelete();
            $table->string('inspection_type_key', 120);
            $table->string('inspection_type_label', 190);
            $table->boolean('is_default')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['inspection_location_id', 'inspection_type_key'],
                'inspection_location_type_unique'
            );
            $table->index(['inspection_type_key', 'sort_order'], 'inspection_location_type_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_location_type_links');
        Schema::dropIfExists('inspection_locations');
    }
};
