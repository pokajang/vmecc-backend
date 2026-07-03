<?php

namespace Database\Seeders;

use App\Models\InspectionFireTruck;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class InspectionFireTruckCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'plate_no' => 'AJG9555',
                'name' => 'Fire Truck',
                'road_tax_expiry' => '2026-02-13',
                'insurance_expiry' => '2026-02-13',
                'puspakom_expiry' => '2026-02-19',
                'sort_order' => 1,
            ],
        ];

        foreach ($rows as $row) {
            InspectionFireTruck::query()->updateOrCreate(
                ['normalized_plate_no' => $this->normalizePlate($row['plate_no'])],
                [
                    'plate_no' => $row['plate_no'],
                    'name' => $row['name'],
                    'road_tax_expiry' => $row['road_tax_expiry'],
                    'insurance_expiry' => $row['insurance_expiry'],
                    'puspakom_expiry' => $row['puspakom_expiry'],
                    'source' => 'seed',
                    'is_active' => true,
                    'sort_order' => $row['sort_order'],
                    'updated_at' => Carbon::now(),
                ]
            );
        }
    }

    private function normalizePlate(string $value): string
    {
        return Str::of($value)->squish()->upper()->replaceMatches('/[^A-Z0-9]+/', '')->toString();
    }
}
