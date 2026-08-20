<?php

namespace Database\Seeders;

use App\Models\InspectionScbaCatalogItem;
use App\Models\InspectionScbaCatalogSection;
use Illuminate\Database\Seeder;

class InspectionScbaCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $fixture = require database_path('seeders/data/scba_catalog.php');
        foreach ($fixture['sections'] as $sectionIndex => $definition) {
            $section = InspectionScbaCatalogSection::query()->updateOrCreate(
                ['key' => $definition['key']],
                [
                    'title' => $definition['title'],
                    'short_label' => $definition['shortLabel'],
                    'fields' => $definition['fields'],
                    'source' => 'seed',
                    'is_active' => true,
                    'sort_order' => $sectionIndex + 1,
                ],
            );
            foreach (array_values($fixture['rows'][$definition['title']] ?? []) as $index => $row) {
                [$location, $brand, $serialNo, $size, $type] = array_pad($row, 5, '');
                $location = trim($location);
                $brand = trim($brand);
                $serialNo = trim($serialNo);
                InspectionScbaCatalogItem::query()->updateOrCreate(
                    ['section_id' => $section->id, 'main_location' => $location, 'brand' => $brand, 'serial_no' => $serialNo],
                    [
                        'location' => $location,
                        'display_name' => trim("{$brand} {$serialNo}"),
                        'details' => collect([trim($size) !== '' ? "Size: {$size} L" : '', trim($type) !== '' ? "Type: {$type}" : ''])->filter()->implode(' | ') ?: null,
                        'source' => 'seed', 'is_active' => true, 'sort_order' => $index + 1,
                    ],
                );
            }
        }
    }
}
