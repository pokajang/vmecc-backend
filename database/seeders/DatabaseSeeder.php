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
            AiHelperKnowledgeSeeder::class,
            InspectionLocationCatalogSeeder::class,
            InspectionEquipmentCatalogSeeder::class,
            InspectionFireExtinguisherCatalogSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
