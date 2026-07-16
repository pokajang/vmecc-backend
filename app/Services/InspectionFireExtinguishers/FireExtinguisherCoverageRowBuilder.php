<?php

namespace App\Services\InspectionFireExtinguishers;

use App\Models\InspectionCheckRow;
use App\Models\InspectionFireExtinguisher;
use App\Models\Report;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FireExtinguisherCoverageRowBuilder
{
    private const CHECK_FIELDS = [
        'physical' => ['checkKey' => 'physical-condition', 'payloadKey' => 'physicalCondition', 'label' => 'FE Physical Condition', 'remarksKey' => 'physicalConditionRemarks', 'photosKey' => 'physicalConditionPhotos'],
        'signage' => ['checkKey' => 'signage-condition', 'payloadKey' => 'signageCondition', 'label' => 'FE Signage Condition', 'remarksKey' => 'signageConditionRemarks', 'photosKey' => 'signageConditionPhotos'],
        'boxKey' => ['checkKey' => 'box-key-availability', 'payloadKey' => 'boxKeyAvailability', 'label' => 'FE Box Key Availability', 'remarksKey' => 'boxKeyAvailabilityRemarks', 'photosKey' => 'boxKeyAvailabilityPhotos'],
        'boxGlass' => ['checkKey' => 'box-glass-availability', 'payloadKey' => 'boxGlassAvailability', 'label' => 'FE Box Glass Availability', 'remarksKey' => 'boxGlassAvailabilityRemarks', 'photosKey' => 'boxGlassAvailabilityPhotos'],
        'operational' => ['checkKey' => 'operational-condition', 'payloadKey' => 'operationalCondition', 'label' => 'Operational Condition', 'remarksKey' => 'operationalConditionRemarks', 'photosKey' => 'operationalConditionPhotos'],
    ];

    /**
     * @param  Collection<int, InspectionFireExtinguisher>  $catalogRows
     * @return array<int, array<string, mixed>>
     */
    public function coverageRowsForCatalog(
        Collection $catalogRows,
        ?Carbon $periodStart = null,
        ?Carbon $periodEnd = null,
    ): array {
        $catalogIds = $catalogRows->pluck('id')->filter()->map(fn ($id): int => (int) $id)->values();
        if ($catalogIds->isEmpty()) {
            return [];
        }

        $query = InspectionCheckRow::query()
            ->with(['submittedBy:id,name', 'report:id,report_uid,display_id,payload'])
            ->where('inspection_type_key', 'fire-extinguisher-inspection')
            ->where('source_payload_key', 'fireExtinguisherChecks')
            ->whereIn('equipment_catalog_id', $catalogIds)
            ->whereNotNull('submitted_at');
        if ($periodStart) {
            $query->where('submitted_at', '>=', $periodStart);
        }
        if ($periodEnd) {
            $query->where('submitted_at', '<=', $periodEnd);
        }

        $rowsByCatalogId = $query->orderByDesc('submitted_at')->orderByDesc('id')->get()
            ->groupBy(fn (InspectionCheckRow $row): int => (int) $row->equipment_catalog_id);

        return $catalogRows->mapWithKeys(function (InspectionFireExtinguisher $catalogRow) use ($rowsByCatalogId): array {
            $checkRows = $rowsByCatalogId->get((int) $catalogRow->id, collect());

            return [(int) $catalogRow->id => $checkRows->isEmpty() ? null : $this->buildCoverageData($catalogRow, $checkRows)];
        })->filter()->all();
    }

    public function formatCoverageRow(
        InspectionFireExtinguisher $row,
        ?array $coverage,
        int $locatorDuplicateCount,
        bool $includeChecks,
    ): array {
        $validity = $row->certification_validity;
        $latestRow = $coverage['latestRow'] ?? null;
        $checks = collect($coverage['checks'] ?? []);
        $reportCount = (int) ($coverage['duplicateCount'] ?? 0);
        $checkValue = fn (string $key): string => (string) ($checks->firstWhere('key', $key)['value'] ?? '');
        $formatted = [
            'id' => 'fe-coverage-'.$row->id,
            'catalogId' => $row->id,
            'canonicalAssetKey' => $this->canonicalAssetKey($row),
            'zone' => (string) ($row->zone ?? ''),
            'location' => (string) ($row->main_location_name ?? ''),
            'mainLocation' => (string) ($row->main_location_name ?? ''),
            'subLocation' => (string) ($row->sub_location_name ?? ''),
            'idLocNo' => (string) ($row->id_loc_no ?? ''),
            'feType' => $this->normalizeFeType($row->fe_type ?? ''),
            'barcodeNo' => (string) ($row->barcode_no ?? ''),
            'certificationValidity' => $validity ? $validity->format('Y-m-d') : '',
            'daysLeft' => $this->daysLeftToExpire($validity),
            'daysLeftToExpire' => $this->daysLeftToExpire($validity),
            'physical' => $checkValue('physical'),
            'signage' => $checkValue('signage'),
            'boxKey' => $checkValue('boxKey'),
            'boxGlass' => $checkValue('boxGlass'),
            'operational' => $checkValue('operational'),
            'inspectedBy' => $latestRow instanceof InspectionCheckRow ? (string) ($latestRow->submittedBy?->name ?? '') : '',
            'inspectionDate' => $latestRow instanceof InspectionCheckRow ? $latestRow->submitted_at?->toIso8601String() : null,
            'latestInspectionAt' => $latestRow instanceof InspectionCheckRow ? $latestRow->submitted_at?->toIso8601String() : null,
            'remarks' => (string) ($coverage['remarks'] ?? ''),
            'issueCount' => (int) ($coverage['issueCount'] ?? 0),
            'evidenceCount' => (int) ($coverage['evidenceCount'] ?? 0),
            'reportCount' => $reportCount,
            'repeatCount' => max(0, $reportCount - 1),
            'duplicateCount' => $reportCount,
            'locatorDuplicateCount' => max(1, $locatorDuplicateCount),
            'latestReportId' => $latestRow instanceof InspectionCheckRow ? (string) $latestRow->display_id : '',
            'latestReportUid' => $latestRow instanceof InspectionCheckRow ? (string) $latestRow->report_uid : '',
        ];
        if ($includeChecks) {
            $formatted['checks'] = $coverage['checks'] ?? $this->emptyCoverageChecks();
            $formatted['duplicateReports'] = $coverage['duplicateReports'] ?? [];
        }

        return $formatted;
    }

    /** @param Collection<int, InspectionCheckRow> $checkRows */
    private function buildCoverageData(InspectionFireExtinguisher $catalogRow, Collection $checkRows): array
    {
        /** @var InspectionCheckRow $latestRow */
        $latestRow = $checkRows->first();
        $sourceRowId = $this->text($latestRow->source_row_id ?? '');
        $latestGroup = $checkRows->filter(function (InspectionCheckRow $row) use ($latestRow, $sourceRowId): bool {
            return (int) $row->report_id === (int) $latestRow->report_id
                && ($sourceRowId === '' || $this->text($row->source_row_id ?? '') === $sourceRowId);
        })->values();
        $payloadItem = $this->findPayloadItem($latestRow->report, $latestRow, $catalogRow);
        $duplicateReports = $checkRows->unique('report_id')->map(fn (InspectionCheckRow $row): array => [
            'reportId' => (int) $row->report_id,
            'displayId' => (string) $row->display_id,
            'submittedAt' => $row->submitted_at?->toIso8601String(),
            'submittedBy' => (string) ($row->submittedBy?->name ?? ''),
        ])->values()->all();

        return [
            'latestRow' => $latestRow,
            'checks' => $this->formatChecks($latestGroup, $payloadItem),
            'remarks' => $this->coverageRemarks($latestGroup, $payloadItem),
            'issueCount' => $latestGroup->where('has_defect', true)->count(),
            'evidenceCount' => $latestGroup->sum(fn (InspectionCheckRow $row): int => (int) $row->evidence_count),
            'duplicateCount' => count($duplicateReports),
            'duplicateReports' => $duplicateReports,
        ];
    }

    /** @param Collection<int, InspectionCheckRow> $latestGroup */
    private function formatChecks(Collection $latestGroup, array $payloadItem): array
    {
        $rowsByCheckKey = $latestGroup->keyBy('check_key');

        return collect(self::CHECK_FIELDS)->map(function (array $field, string $columnKey) use ($rowsByCheckKey, $payloadItem): array {
            /** @var InspectionCheckRow|null $row */
            $row = $rowsByCheckKey->get($field['checkKey']);
            $photos = $this->photos($payloadItem[$field['photosKey']] ?? []);

            return [
                'key' => $columnKey,
                'checkKey' => $field['checkKey'],
                'label' => $field['label'],
                'value' => (string) ($row?->check_value ?? ($payloadItem[$field['payloadKey']] ?? '')),
                'hasDefect' => (bool) ($row?->has_defect ?? false),
                'remarks' => (string) ($row?->remarks ?? ($payloadItem[$field['remarksKey']] ?? '')),
                'evidenceCount' => (int) ($row?->evidence_count ?? count($photos)),
                'photos' => $photos,
                'reportId' => $row ? (int) $row->report_id : null,
                'reportUid' => $row ? (string) $row->report_uid : '',
                'displayId' => $row ? (string) $row->display_id : '',
                'submittedAt' => $row?->submitted_at?->toIso8601String(),
                'submittedBy' => (string) ($row?->submittedBy?->name ?? ''),
            ];
        })->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function emptyCoverageChecks(): array
    {
        return collect(self::CHECK_FIELDS)->map(fn (array $field, string $columnKey): array => [
            'key' => $columnKey, 'checkKey' => $field['checkKey'], 'label' => $field['label'],
            'value' => '', 'hasDefect' => false, 'remarks' => '', 'evidenceCount' => 0, 'photos' => [],
            'reportId' => null, 'reportUid' => '', 'displayId' => '', 'submittedAt' => null, 'submittedBy' => '',
        ])->values()->all();
    }

    /** @param Collection<int, InspectionCheckRow> $latestGroup */
    private function coverageRemarks(Collection $latestGroup, array $payloadItem): string
    {
        $remarks = $latestGroup->pluck('remarks')->map(fn ($remark): string => $this->text($remark))
            ->filter()->unique()->values()->all();
        $general = $this->text($payloadItem['remarks'] ?? '');
        if ($general !== '') {
            $remarks[] = $general;
        }

        return collect($remarks)->unique()->implode('; ');
    }

    private function findPayloadItem(?Report $report, InspectionCheckRow $row, InspectionFireExtinguisher $catalogRow): array
    {
        $payload = is_array($report?->payload) ? $report->payload : [];
        $checks = $payload['fireExtinguisherChecks'] ?? $payload['fire_extinguisher_checks'] ?? [];
        if (! is_array($checks)) {
            return [];
        }
        $catalogId = (string) $catalogRow->id;
        $sourceRowId = $this->text($row->source_row_id ?? '');
        $idLocNo = $this->text($catalogRow->id_loc_no ?? '');
        $barcodeNo = $this->text($catalogRow->barcode_no ?? '');
        foreach ($checks as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (($this->text($item['catalogId'] ?? $item['catalog_id'] ?? '') === $catalogId)
                || ($sourceRowId !== '' && $this->text($item['id'] ?? '') === $sourceRowId)
                || ($idLocNo !== '' && $this->text($item['idLocNo'] ?? $item['id_loc_no'] ?? '') === $idLocNo)
                || ($barcodeNo !== '' && $this->text($item['barcodeNo'] ?? $item['barcode_no'] ?? '') === $barcodeNo)) {
                return $item;
            }
        }

        return [];
    }

    private function photos(mixed $photos): array
    {
        return is_array($photos)
            ? collect($photos)->filter(fn ($photo): bool => is_array($photo) || is_string($photo))->values()->all()
            : [];
    }

    private function canonicalAssetKey(InspectionFireExtinguisher $row): string
    {
        $identity = $this->text($row->active_identity_key ?? '');
        if ($identity !== '') {
            return 'identity:'.strtolower($identity);
        }

        return implode('|', array_map(fn ($value): string => strtolower($this->text($value)), [
            $row->zone ?? '', $row->main_location_name ?? '', $row->sub_location_name ?? '',
            $row->id_loc_no ?? '', $row->barcode_no ?? '',
        ]));
    }

    private function daysLeftToExpire(mixed $validity): string
    {
        if (! $validity) {
            return '';
        }
        try {
            $date = $validity instanceof Carbon ? $validity->copy() : Carbon::parse((string) $validity);

            return (string) now()->startOfDay()->diffInDays($date->startOfDay(), false);
        } catch (\Throwable) {
            return '';
        }
    }

    private function text(mixed $value): string
    {
        return Str::of((string) $value)->squish()->toString();
    }

    private function normalizeFeType(mixed $value): string
    {
        return str_replace(["CO\u{00B2}", "CO\u{FFFD}"], 'CO2', $this->text($value));
    }
}
