<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inspection_fire_extinguishers')) {
            return;
        }

        $rows = DB::table('inspection_fire_extinguishers')
            ->where('source', 'seed')
            ->where('is_active', true)
            ->whereNotNull('barcode_no')
            ->orderBy('source_row_number')
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $groups = [];
        foreach ($rows as $row) {
            $identityKey = $this->identityKey((array) $row);
            if ($identityKey === '') {
                continue;
            }

            $groups[$identityKey][] = $row;
        }

        $deactivateIds = [];
        foreach ($groups as $groupRows) {
            if (count($groupRows) <= 1) {
                continue;
            }

            usort($groupRows, fn ($a, $b): int => $this->compareSeedRowPriority($a, $b));
            $keepRow = $groupRows[0] ?? null;
            if (! $keepRow) continue;
            foreach ($groupRows as $index => $groupRow) {
                if ($index === 0 || ! ($groupRow->id ?? null)) continue;
                $deactivateIds[] = $groupRow->id;
            }
        }

        if ($deactivateIds === []) return;

        DB::table('inspection_fire_extinguishers')
            ->whereIn('id', $deactivateIds)
            ->update([
                'is_active' => false,
                'active_identity_key' => null,
            ]);
    }

    public function down(): void
    {
        // One-way cleanup migration.
    }

    private function compareSeedRowPriority(object $left, object $right): int
    {
        $leftCert = $left->certification_validity ?? null;
        $rightCert = $right->certification_validity ?? null;

        if ($leftCert !== $rightCert) {
            if ($leftCert === null) return 1;
            if ($rightCert === null) return -1;

            return $rightCert <=> $leftCert;
        }

        $leftSourceRow = (int) ($left->source_row_number ?? 0);
        $rightSourceRow = (int) ($right->source_row_number ?? 0);
        if ($leftSourceRow !== $rightSourceRow) {
            return $rightSourceRow <=> $leftSourceRow;
        }

        return ($right->id ?? 0) <=> ($left->id ?? 0);
    }

    private function identityKey(array $row): string
    {
        return implode('|', [
            $this->identityPart($row['zone'] ?? ''),
            $this->identityPart($row['main_location_name'] ?? ''),
            $this->identityPart($row['sub_location_name'] ?? ''),
            $this->identityPart($row['id_loc_no'] ?? ''),
            $this->identityPart($row['barcode_no'] ?? ''),
            $this->identityPart($row['fe_type'] ?? ''),
        ]);
    }

    private function identityPart(mixed $value): string
    {
        return Str::of($this->normalizeFeType((string) $value))->squish()->lower()->toString();
    }

    private function normalizeFeType(mixed $value): string
    {
        return str_replace(["CO\u{00B2}", "CO\u{FFFD}"], 'CO2', trim((string) $value));
    }
};

