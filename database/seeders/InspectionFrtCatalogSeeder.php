<?php

namespace Database\Seeders;

use App\Models\InspectionFireTruck;
use App\Models\InspectionFrtCatalogItem;
use App\Support\Inspection\FrtDailyReference;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class InspectionFrtCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $truck = InspectionFireTruck::query()->where('normalized_plate_no', 'AJG9555')->first();
        if (! $truck) return;
        $rows = [...FrtDailyReference::dailyRows(), ...FrtDailyReference::oneOffRows()];
        foreach ($rows as $index => $row) {
            $kind = str_starts_with((string) $row['id'], 'daily:') ? 'daily' : 'one-off';
            $compartment = trim((string) $row['location']);
            $name = trim((string) $row['equipment']);
            InspectionFrtCatalogItem::query()->updateOrCreate([
                'fire_truck_id' => $truck->id, 'checklist_kind' => $kind, 'compartment' => $compartment, 'normalized_name' => $this->normalized($name),
            ], [
                'name' => $name, 'quantity' => trim((string) ($row['quantity'] ?? '')) ?: null,
                'metadata' => ['workbookRowNumber' => (string) $row['rowNumber'], 'rowKind' => (string) ($row['rowKind'] ?? 'status')],
                'source' => 'seed', 'is_active' => true, 'sort_order' => $index + 1,
            ]);
        }
    }

    private function normalized(string $value): string { return Str::of($value)->squish()->lower()->toString(); }
}
