<?php

use Database\Seeders\InspectionLocationCatalogSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(InspectionLocationCatalogSeeder::class)->run();
    }

    public function down(): void
    {
        // Keep catalog data on rollback to avoid removing live custom-linked locations.
    }
};
