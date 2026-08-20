<?php

namespace Database\Seeders;

use App\Models\InspectionEquipment;
use App\Models\InspectionLocation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class InspectionHighAngleCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (require database_path('seeders/data/high_angle_catalog.php') as $index => $row) {
            $mainLocation = trim((string) $row['mainLocation']);
            $location = InspectionLocation::query()->whereNull('parent_id')->where('is_active', true)->where('normalized_name', $this->normalized($mainLocation))->first();
            InspectionEquipment::query()->updateOrCreate([
                'inspection_type_key' => 'high-angle-rescue-equipment-inspection',
                'main_location_name' => $mainLocation,
                'normalized_name' => $this->normalized($row['name']),
            ], [
                'inspection_type_label' => 'High Angle Rescue Equipment Inspection',
                'main_location_id' => $location?->id,
                'name' => trim((string) $row['name']),
                'description' => null,
                'metadata' => ['storageLocation' => trim((string) $row['storageLocation']), 'compartment' => trim((string) $row['compartment']), 'quantity' => trim((string) $row['quantity']), 'workbookRowNumber' => trim((string) $row['rowNumber'])],
                'source' => 'seed', 'created_by' => null, 'updated_by' => null, 'is_active' => true, 'sort_order' => $index + 1,
            ]);
        }
    }

    private function normalized(string $value): string { return Str::of($value)->squish()->lower()->toString(); }
}
