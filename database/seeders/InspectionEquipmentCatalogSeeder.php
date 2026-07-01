<?php

namespace Database\Seeders;

use App\Models\InspectionEquipment;
use App\Models\InspectionLocation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class InspectionEquipmentCatalogSeeder extends Seeder
{
    private const HYDRAULIC_TYPE_KEY = 'hydraulic-rescue-tools-inspection';
    private const HYDRAULIC_TYPE_LABEL = 'Hydraulic Rescue Tools Inspection';

    private const HYDRAULIC_EQUIPMENT = [
        'FRT' => [
            'Hydraulic Pump Motor 1',
            'Hydraulic Hose 1',
            'Hydraulic Spreader 1',
            'Hydraulic Cutter 1',
            'Hydraulic Combi 1',
            'Hydraulic Cylinder Ramp 1',
        ],
        'Store' => [
            'Hydraulic Pump Motor 2',
            'Hydraulic Hose 2',
            'Hydraulic Spreader 2',
            'Hydraulic Cutter 2',
            'Hydraulic Combi 2',
            'Hydraulic Cylinder Ramp 2',
        ],
    ];

    public function run(): void
    {
        foreach (self::HYDRAULIC_EQUIPMENT as $mainLocation => $equipmentRows) {
            $location = InspectionLocation::query()
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->where('normalized_name', $this->normalizeName($mainLocation))
                ->first();

            foreach (array_values($equipmentRows) as $index => $equipmentName) {
                InspectionEquipment::query()->updateOrCreate(
                    [
                        'inspection_type_key' => self::HYDRAULIC_TYPE_KEY,
                        'main_location_name' => $mainLocation,
                        'normalized_name' => $this->normalizeName($equipmentName),
                    ],
                    [
                        'inspection_type_label' => self::HYDRAULIC_TYPE_LABEL,
                        'main_location_id' => $location?->id,
                        'name' => $equipmentName,
                        'description' => null,
                        'source' => 'seed',
                        'created_by' => null,
                        'is_active' => true,
                        'sort_order' => $index + 1,
                    ]
                );
            }
        }
    }

    private function normalizeName(string $value): string
    {
        return Str::of($value)->squish()->lower()->toString();
    }
}
