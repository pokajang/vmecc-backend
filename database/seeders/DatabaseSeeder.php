<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            TeamsTableSeeder::class,
            // System guides are seeded explicitly after their catalog and role-aware
            // retrieval checks pass. Never restore the legacy AiHelperKnowledgeSeeder.
            InspectionLocationCatalogSeeder::class,
            InspectionEquipmentCatalogSeeder::class,
            InspectionFireExtinguisherCatalogSeeder::class,
            InspectionFireTruckCatalogSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
