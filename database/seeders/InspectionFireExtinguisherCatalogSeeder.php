<?php

namespace Database\Seeders;

use App\Models\InspectionFireExtinguisher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

        $deduplicatedRows = $this->deduplicateSeedRows($rows);
        $seededSourceRows = [];
        foreach ($deduplicatedRows as $index => $row) {
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

    /**
     * @param array<int, mixed> $rows
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateSeedRows(array $rows): array
    {
        $deduplicated = [];
        $selectedIndexesByIdentity = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $identityKey = $this->identityKey($row);
            if ($identityKey === '') {
                $deduplicated[] = $row;
                continue;
            }

            $sourceRowNumber = (int) ($row['sourceRowNumber'] ?? 0);
            if (! isset($selectedIndexesByIdentity[$identityKey])) {
                $selectedIndexesByIdentity[$identityKey] = count($deduplicated);
                $deduplicated[] = $row;
                continue;
            }

            $selectedIndex = $selectedIndexesByIdentity[$identityKey];
            $existingRow = $deduplicated[$selectedIndex];
            if ($this->isBetterSeedDuplicateRow($row, $existingRow)) {
                $deduplicated[$selectedIndex] = $row;
            } elseif (
                (int) $sourceRowNumber > 0 &&
                (int) ($existingRow['sourceRowNumber'] ?? 0) === 0
            ) {
                $deduplicated[$selectedIndex] = $row;
            }
        }

        return $deduplicated;
    }

    private function isBetterSeedDuplicateRow(array $candidate, array $existing): bool
    {
        $candidateSourceRow = (int) ($candidate['sourceRowNumber'] ?? 0);
        $existingSourceRow = (int) ($existing['sourceRowNumber'] ?? 0);

        if ($candidateSourceRow <= 0 && $existingSourceRow <= 0) {
            return false;
        }

        if ($candidateSourceRow <= 0) {
            return false;
        }

        if ($existingSourceRow <= 0) {
            return true;
        }

        $candidateDate = $this->date($candidate['certificationValidity'] ?? '');
        $existingDate = $this->date($existing['certificationValidity'] ?? '');

        if ($candidateDate !== $existingDate) {
            if ($candidateDate !== null && $existingDate === null) {
                return true;
            }
            if ($candidateDate === null && $existingDate !== null) {
                return false;
            }

            return $candidateDate > $existingDate;
        }

        return $candidateSourceRow > $existingSourceRow;
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

    private function identityKey(array $row): string
    {
        $idLocNo = $this->text($row['idLocNo'] ?? '');
        $barcodeNo = $this->text($row['barcodeNo'] ?? '');

        if ($idLocNo === '' && $barcodeNo === '') {
            return '';
        }

        return implode('|', [
            $this->identityPart($row['zone'] ?? ''),
            $this->identityPart($row['mainLocation'] ?? ''),
            $this->identityPart($row['subLocation'] ?? ''),
            $this->identityPart($idLocNo),
            $this->identityPart($barcodeNo),
            $this->identityPart($this->normalizeFeType($row['feType'] ?? '')),
        ]);
    }

    private function identityPart(mixed $value): string
    {
        return Str::of(
            str_replace(
                ['COÂ²', 'COï¿½', 'COÃ‚Â²', 'COÃ¯Â¿Â½'],
                'CO2',
                (string) $value,
            ),
        )->squish()->lower()->toString();
    }
}
