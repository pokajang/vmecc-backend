<?php

namespace Database\Seeders;

use App\Models\InspectionFireExtinguisher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class InspectionFireExtinguisherCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/fire_extinguishers.json');
        if (! is_file($path)) {
            return;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows)) {
            return;
        }

        $seededSourceRows = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $sourceRowNumber = (int) ($row['sourceRowNumber'] ?? 0);
            if ($sourceRowNumber <= 0) {
                continue;
            }

            $seededSourceRows[] = $sourceRowNumber;
            $attributes = [
                'zone' => $this->text($row['zone'] ?? '') ?: null,
                'main_location_name' => $this->text($row['mainLocation'] ?? ''),
                'sub_location_name' => $this->text($row['subLocation'] ?? '') ?: null,
                'id_loc_no' => $this->text($row['idLocNo'] ?? '') ?: null,
                'barcode_no' => $this->text($row['barcodeNo'] ?? '') ?: null,
                'fe_type' => $this->normalizeFeType($row['feType'] ?? '') ?: null,
                'certification_validity' => $this->date($row['certificationValidity'] ?? ''),
                'source' => 'seed',
                'is_active' => true,
                'sort_order' => (int) ($row['sortOrder'] ?? ($index + 1)),
            ];
            if (Schema::hasColumn('inspection_fire_extinguishers', 'active_identity_key')) {
                $attributes['active_identity_key'] = null;
            }

            InspectionFireExtinguisher::query()->updateOrCreate(
                ['source_row_number' => $sourceRowNumber],
                $attributes,
            );
        }

        if ($seededSourceRows !== []) {
            $staleSeedQuery = InspectionFireExtinguisher::query()
                ->where('source', 'seed')
                ->whereNotIn('source_row_number', $seededSourceRows);

            $staleSeedQuery->update(
                Schema::hasColumn('inspection_fire_extinguishers', 'active_identity_key')
                    ? ['is_active' => false, 'active_identity_key' => null]
                    : ['is_active' => false],
            );
        }
    }

    private function text(mixed $value): string
    {
        return trim((string) $value);
    }

    private function normalizeFeType(mixed $value): string
    {
        return str_replace(['CO²', 'CO�', 'COÂ²', 'COï¿½'], 'CO2', $this->text($value));
    }

    private function date(mixed $value): ?string
    {
        $text = $this->text($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) ? $text : null;
    }
}
