<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_frt_catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fire_truck_id')->constrained('inspection_fire_trucks')->cascadeOnDelete();
            $table->string('checklist_kind', 20);
            $table->string('compartment', 190);
            $table->string('name', 190);
            $table->string('normalized_name', 190);
            $table->string('quantity', 40)->nullable();
            $table->string('asset_tag', 120)->nullable();
            $table->string('manufacturer', 120)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('serial_no', 120)->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('source', 40)->default('custom');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['fire_truck_id', 'checklist_kind', 'compartment', 'normalized_name'], 'inspection_frt_catalog_item_identity_unique');
            $table->index(['fire_truck_id', 'is_active', 'sort_order'], 'inspection_frt_catalog_item_active_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_frt_catalog_items');
    }
};
