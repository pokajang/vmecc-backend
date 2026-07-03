<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_scba_catalog_sections', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('title', 190);
            $table->string('short_label', 80)->nullable();
            $table->json('fields');
            $table->string('source', 40)->default('custom');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'inspection_scba_sections_active_sort_idx');
        });

        Schema::create('inspection_scba_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')
                ->constrained('inspection_scba_catalog_sections')
                ->cascadeOnDelete();
            $table->string('location', 190)->nullable();
            $table->string('main_location', 190)->nullable();
            $table->string('brand', 120)->nullable();
            $table->string('serial_no', 120)->nullable();
            $table->string('display_name', 190)->nullable();
            $table->text('details')->nullable();
            $table->string('source', 40)->default('custom');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['section_id', 'is_active', 'sort_order'], 'inspection_scba_items_section_active_sort_idx');
            $table->index(['main_location', 'is_active'], 'inspection_scba_items_location_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_scba_catalog_items');
        Schema::dropIfExists('inspection_scba_catalog_sections');
    }
};
