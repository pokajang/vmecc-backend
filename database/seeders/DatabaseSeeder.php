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
            AiHelperSystemGuideSeeder::class,
            // Never restore the legacy AiHelperKnowledgeSeeder. Reference PDFs use
            // their dedicated corpus seeder and remain separate from system guides.
            InspectionLocationCatalogSeeder::class,
            InspectionEquipmentCatalogSeeder::class,
            InspectionFireExtinguisherCatalogSeeder::class,
            InspectionFireTruckCatalogSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
