<?php

namespace Database\Seeders;

use App\Models\InspectionFireExtinguisher;
use Illuminate\Database\Seeder;

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
            $rawValidity = $this->text($row['certificationValidityRaw'] ?? '');
            InspectionFireExtinguisher::query()->updateOrCreate(
                ['source_row_number' => $sourceRowNumber],
                [
                    'zone' => $this->text($row['zone'] ?? '') ?: null,
                    'main_location_name' => $this->text($row['mainLocation'] ?? ''),
                    'sub_location_name' => $this->text($row['subLocation'] ?? '') ?: null,
                    'id_loc_no' => $this->text($row['idLocNo'] ?? '') ?: null,
                    'barcode_no' => $this->text($row['barcodeNo'] ?? '') ?: null,
                    'fe_type' => $this->normalizeFeType($row['feType'] ?? '') ?: null,
                    'certification_validity' => $this->date($row['certificationValidity'] ?? ''),
                    'certification_validity_raw' => $rawValidity !== '' ? $rawValidity : null,
                    'days_left_to_expire' => $this->text($row['daysLeftToExpire'] ?? '') ?: null,
                    'source' => 'seed',
                    'is_active' => true,
                    'sort_order' => (int) ($row['sortOrder'] ?? ($index + 1)),
                ],
            );
        }

        if ($seededSourceRows !== []) {
            InspectionFireExtinguisher::query()
                ->where('source', 'seed')
                ->whereNotIn('source_row_number', $seededSourceRows)
                ->update(['is_active' => false]);
        }
    }

    private function text(mixed $value): string
    {
        return trim((string) $value);
    }

    private function normalizeFeType(mixed $value): string
    {
        return str_replace(['CO²', 'CO�'], 'CO2', $this->text($value));
    }

    private function date(mixed $value): ?string
    {
        $text = $this->text($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) ? $text : null;
    }
}
