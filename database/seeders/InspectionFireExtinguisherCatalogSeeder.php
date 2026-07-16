<?php

namespace Database\Seeders;

use App\Models\InspectionFireExtinguisher;
use App\Models\InspectionFireExtinguisherIssue;
use App\Services\InspectionFireExtinguishers\FireExtinguisherIssueWorkflowService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class InspectionFireExtinguisherCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $rows = $this->loadSeedRows();
        $issueWorkflow = app(FireExtinguisherIssueWorkflowService::class);
        $hasActiveIdentityKey = Schema::hasColumn('inspection_fire_extinguishers', 'active_identity_key');
        $hasLifecycle = Schema::hasColumn('inspection_fire_extinguishers', 'lifecycle_status');
        $hasIssues = Schema::hasTable('inspection_fire_extinguisher_issues');

        DB::transaction(function () use ($rows, $hasActiveIdentityKey, $hasLifecycle, $hasIssues, $issueWorkflow): void {
            $seededSourceRows = [];

            foreach ($rows as $index => $row) {
                $sourceRowNumber = $row['sourceRowNumber'];
                $seededSourceRows[] = $sourceRowNumber;
                $attributes = $this->attributes($row, $index, $hasActiveIdentityKey);
                $existing = InspectionFireExtinguisher::query()
                    ->where('source_row_number', $sourceRowNumber)
                    ->lockForUpdate()
                    ->first();

                if ($existing && $existing->source !== 'seed') {
                    throw new RuntimeException(
                        "Fire extinguisher source row {$sourceRowNumber} belongs to a non-seed record."
                    );
                }

                if ($existing && ! $this->hasSameIdentity($existing, $row)) {
                    $this->archiveSeedRow($existing, $hasActiveIdentityKey, $hasLifecycle, $hasIssues, $issueWorkflow);
                    $existing = null;
                }

                if ($existing) {
                    // A catalogue refresh must not silently reactivate an asset
                    // that operations explicitly retired.
                    if ($hasLifecycle && (! $existing->is_active || $existing->lifecycle_status === 'retired')) {
                        unset($attributes['is_active']);
                        $attributes['lifecycle_status'] = 'retired';
                        $attributes['retired_at'] = $existing->retired_at ?: now();
                        $attributes['retirement_reason'] = $existing->retirement_reason ?: 'Archived before lifecycle tracking.';
                    }
                    $existing->fill($attributes)->save();

                    continue;
                }

                InspectionFireExtinguisher::query()->create([
                    'source_row_number' => $sourceRowNumber,
                    ...$attributes,
                ]);
            }

            InspectionFireExtinguisher::query()
                ->where('source', 'seed')
                ->whereNotNull('source_row_number')
                ->whereNotIn('source_row_number', $seededSourceRows)
                ->lockForUpdate()
                ->get()
                ->each(fn (InspectionFireExtinguisher $row) => $this->archiveSeedRow(
                    $row,
                    $hasActiveIdentityKey,
                    $hasLifecycle,
                    $hasIssues,
                    $issueWorkflow,
                ));
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadSeedRows(): array
    {
        $path = database_path('seeders/data/fire_extinguishers.json');
        if (! is_file($path)) {
            throw new RuntimeException("Fire extinguisher seed data not found at {$path}.");
        }

        try {
            $rows = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Fire extinguisher seed data is not valid JSON.', 0, $exception);
        }

        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            throw new RuntimeException('Fire extinguisher seed data must be a non-empty JSON list.');
        }

        $sourceRows = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException("Fire extinguisher seed entry {$index} must be an object.");
            }

            $sourceRowNumber = $row['sourceRowNumber'] ?? null;
            if (! is_int($sourceRowNumber) || $sourceRowNumber <= 0) {
                throw new RuntimeException("Fire extinguisher seed entry {$index} has an invalid source row.");
            }
            if (isset($sourceRows[$sourceRowNumber])) {
                throw new RuntimeException("Fire extinguisher source row {$sourceRowNumber} is duplicated.");
            }
            $sourceRows[$sourceRowNumber] = true;

            foreach (['zone', 'mainLocation', 'idLocNo', 'feType', 'barcodeNo', 'certificationValidity'] as $field) {
                if ($this->text($row[$field] ?? '') === '') {
                    throw new RuntimeException(
                        "Fire extinguisher source row {$sourceRowNumber} is missing {$field}."
                    );
                }
            }

            $this->date($row['certificationValidity'], $sourceRowNumber);

            if (isset($row['sortOrder']) && (! is_int($row['sortOrder']) || $row['sortOrder'] <= 0)) {
                throw new RuntimeException(
                    "Fire extinguisher source row {$sourceRowNumber} has an invalid sort order."
                );
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function attributes(array $row, int $index, bool $hasActiveIdentityKey): array
    {
        $attributes = [
            'zone' => $this->text($row['zone']),
            'main_location_name' => $this->text($row['mainLocation']),
            'sub_location_name' => $this->text($row['subLocation'] ?? '') ?: null,
            'id_loc_no' => $this->text($row['idLocNo']),
            'barcode_no' => $this->text($row['barcodeNo']),
            'fe_type' => $this->normalizeFeType($row['feType']),
            'certification_validity' => $this->date($row['certificationValidity'], $row['sourceRowNumber']),
            'source' => 'seed',
            'is_active' => true,
            'sort_order' => (int) ($row['sortOrder'] ?? ($index + 1)),
        ];
        if ($hasActiveIdentityKey) {
            $attributes['active_identity_key'] = null;
        }

        return $attributes;
    }

    private function archiveSeedRow(
        InspectionFireExtinguisher $row,
        bool $hasActiveIdentityKey,
        bool $hasLifecycle,
        bool $hasIssues,
        FireExtinguisherIssueWorkflowService $issueWorkflow,
    ): void {
        $reason = 'Removed from the managed seed catalogue.';
        $attributes = [
            'source_row_number' => null,
            'is_active' => false,
        ];
        if ($hasActiveIdentityKey) {
            $attributes['active_identity_key'] = null;
        }
        if ($hasLifecycle) {
            if ($hasIssues) {
                InspectionFireExtinguisherIssue::query()
                    ->where('fire_extinguisher_id', $row->id)
                    ->whereIn('status', InspectionFireExtinguisherIssue::ACTIVE_STATUSES)
                    ->lockForUpdate()
                    ->get()
                    ->each(fn (InspectionFireExtinguisherIssue $issue) => $issueWorkflow->closeForRetirement($issue, null, $reason));
            }
            $attributes += [
                'lifecycle_status' => 'retired',
                'retired_at' => now(),
                'retirement_reason' => $reason,
                'lock_version' => ((int) $row->lock_version) + 1,
            ];
        }

        $row->forceFill($attributes)->save();
    }

    private function text(mixed $value): string
    {
        return trim((string) $value);
    }

    private function normalizeFeType(mixed $value): string
    {
        $text = $this->text($value);

        return preg_replace('/CO(?:\x{00B2}|\x{FFFD})/u', 'CO2', $text) ?? $text;
    }

    private function date(mixed $value, int $sourceRowNumber): ?string
    {
        $text = $this->text($value);
        if ($text === '') {
            return null;
        }
        if (
            preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})$/', $text, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            return $text;
        }

        throw new RuntimeException(
            "Fire extinguisher source row {$sourceRowNumber} has an invalid certification date."
        );
    }

    /** @param array<string, mixed> $seedRow */
    private function hasSameIdentity(
        InspectionFireExtinguisher $existing,
        array $seedRow
    ): bool {
        return $this->identityKey($seedRow) === implode('|', [
            $this->identityPart($existing->zone),
            $this->identityPart($existing->main_location_name),
            $this->identityPart($existing->sub_location_name),
            $this->identityPart($existing->id_loc_no),
            $this->identityPart($existing->barcode_no),
            $this->identityPart($existing->fe_type),
        ]);
    }

    /** @param array<string, mixed> $row */
    private function identityKey(array $row): string
    {
        return implode('|', [
            $this->identityPart($row['zone'] ?? ''),
            $this->identityPart($row['mainLocation'] ?? ''),
            $this->identityPart($row['subLocation'] ?? ''),
            $this->identityPart($row['idLocNo'] ?? ''),
            $this->identityPart($row['barcodeNo'] ?? ''),
            $this->identityPart($this->normalizeFeType($row['feType'] ?? '')),
        ]);
    }

    private function identityPart(mixed $value): string
    {
        return Str::of($this->normalizeFeType($value))->squish()->lower()->toString();
    }
}
