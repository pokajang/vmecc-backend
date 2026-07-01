<?php

namespace App\Services;

use App\Models\InspectionCheckRow;
use App\Models\Report;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class InspectionCheckRowSyncService
{
    private const REPORT_TYPE_INSPECTION = 'inspection';
    private const STATUS_DRAFT = 'Draft';
    private const ER_AUX_INSPECTION_TYPE_KEY = 'er-aux-equipment-inspection';
    private const ER_AUX_SOURCE_PAYLOAD_KEY = 'erAuxChecks';
    private const FIRE_EXTINGUISHER_INSPECTION_TYPE_KEY = 'fire-extinguisher-inspection';
    private const FIRE_EXTINGUISHER_SOURCE_PAYLOAD_KEY = 'fireExtinguisherChecks';
    private const FRT_DAILY_INSPECTION_TYPE_KEY = 'frt-daily-inspection';
    private const FRT_DAILY_SOURCE_PAYLOAD_KEY = 'frtDailyChecks';
    private const FRT_ONE_OFF_SOURCE_PAYLOAD_KEY = 'frtOneOffChecks';
    private const HIGH_ANGLE_INSPECTION_TYPE_KEY = 'high-angle-rescue-equipment-inspection';
    private const HIGH_ANGLE_SOURCE_PAYLOAD_KEY = 'highAngleChecks';
    private const HSE_INSPECTION_TYPE_KEY = 'health-safety-environment-inspection';
    private const HSE_SOURCE_PAYLOAD_KEY = 'hseSelections';
    private const HYDRAULIC_INSPECTION_TYPE_KEY = 'hydraulic-rescue-tools-inspection';
    private const HYDRAULIC_SOURCE_PAYLOAD_KEY = 'hydraulicChecks';
    private const SCBA_INSPECTION_TYPE_KEY = 'scba-inspection';
    private const SCBA_SECTION_META = [
        'backPlate' => [
            'payloadKey' => 'scbaBackPlateChecks',
            'payloadKeySnake' => 'scba_back_plate_checks',
            'checkGroup' => 'SCBA Back Plate Checks',
            'fields' => [
                'backPlateHarnessCondition' => ['key' => 'back-plate-harness-condition', 'name' => 'Back Plate & Harness Condition', 'defectValue' => 'Not Good'],
                'highPressureHose' => ['key' => 'high-pressure-hose', 'name' => 'High Pressure Hose', 'defectValue' => 'Not Good'],
                'pressureGauge' => ['key' => 'pressure-gauge', 'name' => 'Pressure Gauge', 'defectValue' => 'Not Good'],
                'alarmDevice' => ['key' => 'alarm-device', 'name' => 'Alarm Device', 'defectValue' => 'Not Good'],
                'demandValve' => ['key' => 'demand-valve', 'name' => 'Demand Valve', 'defectValue' => 'Not Good'],
                'sealing' => ['key' => 'sealing', 'name' => 'Sealing', 'defectValue' => 'Not Good'],
                'cleanliness' => ['key' => 'cleanliness', 'name' => 'Cleanliness', 'defectValue' => 'Not Good'],
            ],
        ],
        'cylinder' => [
            'payloadKey' => 'scbaCylinderChecks',
            'payloadKeySnake' => 'scba_cylinder_checks',
            'checkGroup' => 'SCBA Cylinder Checks',
            'fields' => [
                'servicePressure' => ['key' => 'service-pressure', 'name' => 'Service Pressure (Bar)'],
                'containedPressure' => ['key' => 'contained-pressure', 'name' => 'Contained Pressure (Bar)'],
                'physicalCondition' => ['key' => 'physical-condition', 'name' => 'Physical Condition', 'defectValue' => 'Not Good'],
                'handwheelCondition' => ['key' => 'handwheel-condition', 'name' => 'Handwheel Condition', 'defectValue' => 'Not Good'],
                'valveBodyCondition' => ['key' => 'valve-body-condition', 'name' => 'Valve Body Condition', 'defectValue' => 'Not Good'],
                'screwPlugCondition' => ['key' => 'screw-plug-condition', 'name' => 'Screw Plug Condition', 'defectValue' => 'Not Good'],
                'cleanliness' => ['key' => 'cleanliness', 'name' => 'Cleanliness', 'defectValue' => 'Not Good'],
            ],
        ],
        'faceMask' => [
            'payloadKey' => 'scbaFaceMaskChecks',
            'payloadKeySnake' => 'scba_face_mask_checks',
            'checkGroup' => 'SCBA Face Mask Checks',
            'fields' => [
                'visorCondition' => ['key' => 'visor-condition', 'name' => 'Visor Condition', 'defectValue' => 'Not Good'],
                'ldvPort' => ['key' => 'ldv-port', 'name' => 'LDV Port', 'defectValue' => 'Not Good'],
                'ldvReleaseButton' => ['key' => 'ldv-release-button', 'name' => 'LDV Release Button', 'defectValue' => 'Not Good'],
                'leakTest' => ['key' => 'leak-test', 'name' => 'Leak Test', 'defectValue' => 'Not Good'],
                'speechDiaphragm' => ['key' => 'speech-diaphragm', 'name' => 'Speech Diaphragm', 'defectValue' => 'Not Good'],
                'harness' => ['key' => 'harness', 'name' => 'Harness', 'defectValue' => 'Not Good'],
                'neckStrap' => ['key' => 'neck-strap', 'name' => 'Neck Strap', 'defectValue' => 'Not Good'],
            ],
        ],
    ];
    private const HYDRAULIC_CHECK_FIELDS = [
        'physicalCondition' => [
            'key' => 'physical-condition',
            'name' => 'Physical Condition',
            'remarks' => 'physicalConditionRemarks',
            'photos' => 'physicalConditionPhotos',
        ],
        'mechanicalCondition' => [
            'key' => 'mechanical-condition',
            'name' => 'Mechanical Condition',
            'remarks' => 'mechanicalConditionRemarks',
            'photos' => 'mechanicalConditionPhotos',
        ],
        'noLeakage' => [
            'key' => 'no-leakage',
            'name' => 'No Leakage',
            'remarks' => 'noLeakageRemarks',
            'photos' => 'noLeakagePhotos',
        ],
        'functionTest' => [
            'key' => 'function-test',
            'name' => 'Function Test',
            'remarks' => 'functionTestRemarks',
            'photos' => 'functionTestPhotos',
        ],
    ];
    private const FIRE_EXTINGUISHER_CHECK_FIELDS = [
        'physicalCondition' => [
            'key' => 'physical-condition',
            'name' => 'FE Physical Condition',
            'remarks' => 'physicalConditionRemarks',
            'photos' => 'physicalConditionPhotos',
        ],
        'signageCondition' => [
            'key' => 'signage-condition',
            'name' => 'FE Signage Condition',
            'remarks' => 'signageConditionRemarks',
            'photos' => 'signageConditionPhotos',
        ],
        'boxKeyAvailability' => [
            'key' => 'box-key-availability',
            'name' => 'FE Box Key Availability',
            'remarks' => 'boxKeyAvailabilityRemarks',
            'photos' => 'boxKeyAvailabilityPhotos',
        ],
        'boxGlassAvailability' => [
            'key' => 'box-glass-availability',
            'name' => 'FE Box Glass Availability',
            'remarks' => 'boxGlassAvailabilityRemarks',
            'photos' => 'boxGlassAvailabilityPhotos',
        ],
        'operationalCondition' => [
            'key' => 'operational-condition',
            'name' => 'Operational Condition',
            'remarks' => 'operationalConditionRemarks',
            'photos' => 'operationalConditionPhotos',
        ],
    ];

    public function syncForReport(Report $report, ?int $actorUserId = null): int
    {
        if (! $this->isInspectionReport($report)) {
            return 0;
        }

        InspectionCheckRow::withTrashed()
            ->where('report_id', $report->id)
            ->forceDelete();

        if ($this->isDraft($report)) {
            return 0;
        }

        $rows = $this->extractRowsForReport($report, $actorUserId);
        if ($rows === []) {
            return 0;
        }

        InspectionCheckRow::query()->insert($rows);

        return count($rows);
    }

    public function syncStatusForReport(Report $report, ?int $actorUserId = null): void
    {
        if (! $this->isInspectionReport($report)) {
            return;
        }

        InspectionCheckRow::query()
            ->where('report_id', $report->id)
            ->update([
                'updated_by_user_id' => $actorUserId,
                'report_status' => (string) $report->status,
                'report_version' => (int) $report->version,
                'report_revision' => (int) $report->revision,
                'submitted_at' => $this->submittedAt($report),
                'updated_at' => now(),
            ]);
    }

    public function softDeleteForReport(Report $report): void
    {
        InspectionCheckRow::query()
            ->where('report_id', $report->id)
            ->delete();
    }

    public function extractRowsForReport(Report $report, ?int $actorUserId = null): array
    {
        if (! $this->isInspectionReport($report) || $this->isDraft($report)) {
            return [];
        }

        $payload = is_array($report->payload) ? $report->payload : [];
        $inspectionType = trim((string) ($payload['incidentType'] ?? $payload['inspectionType'] ?? ''));
        $inspectionTypeKey = $this->slug($inspectionType);

        return match ($inspectionTypeKey) {
            self::ER_AUX_INSPECTION_TYPE_KEY => $this->extractErAuxRows(
                report: $report,
                payload: $payload,
                inspectionType: $inspectionType,
                inspectionTypeKey: $inspectionTypeKey,
                actorUserId: $actorUserId,
            ),
            self::FIRE_EXTINGUISHER_INSPECTION_TYPE_KEY => $this->extractFireExtinguisherRows(
                report: $report,
                payload: $payload,
                inspectionType: $inspectionType,
                inspectionTypeKey: $inspectionTypeKey,
                actorUserId: $actorUserId,
            ),
            self::FRT_DAILY_INSPECTION_TYPE_KEY => $this->extractFrtRows(
                report: $report,
                payload: $payload,
                inspectionType: $inspectionType,
                inspectionTypeKey: $inspectionTypeKey,
                actorUserId: $actorUserId,
            ),
            self::HYDRAULIC_INSPECTION_TYPE_KEY => $this->extractHydraulicRows(
                report: $report,
                payload: $payload,
                inspectionType: $inspectionType,
                inspectionTypeKey: $inspectionTypeKey,
                actorUserId: $actorUserId,
            ),
            self::HIGH_ANGLE_INSPECTION_TYPE_KEY => $this->extractHighAngleRows(
                report: $report,
                payload: $payload,
                inspectionType: $inspectionType,
                inspectionTypeKey: $inspectionTypeKey,
                actorUserId: $actorUserId,
            ),
            self::SCBA_INSPECTION_TYPE_KEY => $this->extractScbaRows(
                report: $report,
                payload: $payload,
                inspectionType: $inspectionType,
                inspectionTypeKey: $inspectionTypeKey,
                actorUserId: $actorUserId,
            ),
            self::HSE_INSPECTION_TYPE_KEY => $this->extractHseRows(
                report: $report,
                payload: $payload,
                inspectionType: $inspectionType,
                inspectionTypeKey: $inspectionTypeKey,
                actorUserId: $actorUserId,
            ),
            default => [],
        };
    }

    private function extractHseRows(
        Report $report,
        array $payload,
        string $inspectionType,
        string $inspectionTypeKey,
        ?int $actorUserId,
    ): array {
        $selections = $payload[self::HSE_SOURCE_PAYLOAD_KEY] ?? $payload['hse_selections'] ?? [];
        if (! is_array($selections)) {
            return [];
        }

        $selectionMeta = [
            'areaSatisfactory' => [
                'key' => 'area-satisfactory',
                'name' => 'Area Satisfactory',
                'remarks' => 'hseAreaConditionRemarks',
                'defect' => false,
            ],
            'unsafeAct' => [
                'key' => 'unsafe-act',
                'name' => 'Unsafe Act',
                'remarks' => 'hseUnsafeActDetails',
                'defect' => true,
            ],
            'unsafeCondition' => [
                'key' => 'unsafe-condition',
                'name' => 'Unsafe Condition',
                'remarks' => 'hseUnsafeConditionDetails',
                'defect' => true,
            ],
            'environmental' => [
                'key' => 'environmental',
                'name' => 'Environmental',
                'remarks' => 'hseEnvironmentalDetails',
                'defect' => true,
            ],
        ];

        $rows = [];
        $sortOrder = 0;
        $locationParts = $this->resolveLocationParts($payload, []);
        $inspectedBy = trim((string) ($payload['hseInspectedBy'] ?? $payload['hse_inspected_by'] ?? ''));
        $inspectionDate = trim((string) ($payload['hseInspectionDate'] ?? $payload['hse_inspection_date'] ?? ''));
        $severity = trim((string) ($payload['hseSeverity'] ?? $payload['hse_severity'] ?? ''));
        $followUp = [
            'Immediate Action' => trim((string) ($payload['hseImmediateAction'] ?? $payload['hse_immediate_action'] ?? '')),
            'Corrective Action' => trim((string) ($payload['hseCorrectiveAction'] ?? $payload['hse_corrective_action'] ?? '')),
            'Responsible Person' => trim((string) ($payload['hseResponsiblePerson'] ?? $payload['hse_responsible_person'] ?? '')),
            'Target Date' => trim((string) ($payload['hseTargetDate'] ?? $payload['hse_target_date'] ?? '')),
            'General Remarks' => trim((string) ($payload['hseRemarks'] ?? $payload['hse_remarks'] ?? '')),
        ];

        foreach ($selections as $selection) {
            $selection = trim((string) $selection);
            $meta = $selectionMeta[$selection] ?? null;
            if (! $meta) {
                continue;
            }

            $parts = [];
            $detail = trim((string) ($payload[$meta['remarks']] ?? $payload[Str::snake($meta['remarks'])] ?? ''));
            if ($detail !== '') {
                $parts[] = 'Details: '.$detail;
            }
            if ($severity !== '' && $meta['defect']) {
                $parts[] = 'Severity: '.$severity;
            }
            if ($inspectedBy !== '') {
                $parts[] = 'Inspected By: '.$inspectedBy;
            }
            if ($inspectionDate !== '') {
                $parts[] = 'Inspection Date: '.$inspectionDate;
            }
            if ($meta['defect']) {
                foreach ($followUp as $label => $value) {
                    if ($value !== '') {
                        $parts[] = $label.': '.$value;
                    }
                }
            }

            $rows[] = $this->baseRow(
                report: $report,
                inspectionType: $inspectionType,
                inspectionTypeKey: $inspectionTypeKey,
                actorUserId: $actorUserId,
                locationParts: $locationParts,
                equipment: 'HSE Observation',
                equipmentCatalogId: null,
                equipmentSource: 'report',
                checkKey: $meta['key'],
                checkName: $meta['name'],
                checkValue: $meta['defect'] && $severity !== '' ? $severity : $meta['name'],
                remarks: implode('; ', $parts),
                evidenceCount: $this->countEvidencePhotos($payload['photos'] ?? []),
                sourceRowId: 'hse:'.$selection,
                sortOrder: $sortOrder++,
                checkGroup: 'HSE Observation',
                sourcePayloadKey: self::HSE_SOURCE_PAYLOAD_KEY,
                hasDefectOverride: $meta['defect'],
            );
        }

        return $rows;
    }

    private function extractErAuxRows(
        Report $report,
        array $payload,
        string $inspectionType,
        string $inspectionTypeKey,
        ?int $actorUserId,
    ): array {
        $checks = $payload[self::ER_AUX_SOURCE_PAYLOAD_KEY] ?? $payload['er_aux_checks'] ?? [];
        if (! is_array($checks)) {
            return [];
        }

        $rows = [];
        $sortOrder = 0;
        $inspectedBy = trim((string) ($payload['erAuxInspectedBy'] ?? $payload['er_aux_inspected_by'] ?? ''));
        $inspectionDate = trim((string) ($payload['erAuxInspectionDate'] ?? $payload['er_aux_inspection_date'] ?? ''));

        foreach ($checks as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $equipment = trim((string) ($item['equipment'] ?? $item['title'] ?? $item['name'] ?? ''));
            if ($equipment === '') {
                continue;
            }

            $locationParts = $this->resolveLocationParts($payload, $item);
            $equipmentCatalogId = $this->nullableInteger($item['equipmentId'] ?? $item['equipment_id'] ?? $item['equipmentCatalogId'] ?? $item['equipment_catalog_id'] ?? null);
            $equipmentSource = trim((string) ($item['equipmentSource'] ?? $item['equipment_source'] ?? '')) ?: 'seed';
            $sourceRowId = trim((string) ($item['id'] ?? ''));
            if ($sourceRowId === '') {
                $sourceRowId = $this->slug($locationParts['location'].' '.$equipment) ?: 'er-aux-check-'.$index;
            }

            $quantity = trim((string) ($item['quantity'] ?? $item['qty'] ?? $item['defaultQuantity'] ?? $item['default_quantity'] ?? ''));
            $condition = trim((string) ($item['condition'] ?? ''));
            $remarks = trim((string) ($item['remarks'] ?? $item['remark'] ?? ''));
            $parts = [];
            if ($quantity !== '') {
                $parts[] = 'Qty: '.$quantity;
            }
            if ($inspectedBy !== '') {
                $parts[] = 'Inspected By: '.$inspectedBy;
            }
            if ($inspectionDate !== '') {
                $parts[] = 'Inspection Date: '.$inspectionDate;
            }
            if ($remarks !== '') {
                $parts[] = 'Remarks: '.$remarks;
            }

            $rows[] = $this->baseRow(
                report: $report,
                inspectionType: $inspectionType,
                inspectionTypeKey: $inspectionTypeKey,
                actorUserId: $actorUserId,
                locationParts: $locationParts,
                equipment: $equipment,
                equipmentCatalogId: $equipmentCatalogId,
                equipmentSource: $equipmentSource,
                checkKey: 'condition',
                checkName: 'Condition',
                checkValue: $condition,
                remarks: implode('; ', $parts),
                evidenceCount: $this->countEvidencePhotos($item['photos'] ?? []),
                sourceRowId: $sourceRowId,
                sortOrder: $sortOrder++,
                checkGroup: 'ER Aux Equipment Checks',
                sourcePayloadKey: self::ER_AUX_SOURCE_PAYLOAD_KEY,
            );
        }

        return $rows;
    }

    private function extractHydraulicRows(
        Report $report,
        array $payload,
        string $inspectionType,
        string $inspectionTypeKey,
        ?int $actorUserId,
    ): array {
        $checks = $payload[self::HYDRAULIC_SOURCE_PAYLOAD_KEY] ?? $payload['hydraulic_checks'] ?? [];
        if (! is_array($checks)) {
            return [];
        }

        $rows = [];
        $sortOrder = 0;
        foreach ($checks as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $equipment = trim((string) ($item['equipment'] ?? $item['title'] ?? $item['name'] ?? ''));
            if ($equipment === '') {
                continue;
            }

            $locationParts = $this->resolveLocationParts($payload, $item);
            $equipmentRemarks = trim((string) ($item['remarks'] ?? $item['remark'] ?? $item['defects'] ?? ''));
            $equipmentEvidenceCount = $this->countEvidencePhotos($item['photos'] ?? []);
            $equipmentCatalogId = $this->nullableInteger($item['equipmentId'] ?? $item['equipment_id'] ?? $item['equipmentCatalogId'] ?? $item['equipment_catalog_id'] ?? null);
            $equipmentSource = trim((string) ($item['equipmentSource'] ?? $item['equipment_source'] ?? '')) ?: 'seed';
            $sourceRowId = trim((string) ($item['id'] ?? ''));
            if ($sourceRowId === '') {
                $sourceRowId = $this->slug($locationParts['location'].' '.$equipment) ?: 'hydraulic-check-'.$index;
            }

            foreach (self::HYDRAULIC_CHECK_FIELDS as $field => $checkMeta) {
                $snakeField = Str::snake($field);
                $value = trim((string) ($item[$field] ?? $item[$snakeField] ?? ''));
                $isDefect = strcasecmp($value, 'Defect') === 0;
                $isNotApplicable = strcasecmp($value, 'N/A') === 0;
                $fieldRemarksKey = $checkMeta['remarks'];
                $fieldPhotosKey = $checkMeta['photos'];
                $fieldRemarks = trim((string) ($item[$fieldRemarksKey] ?? $item[Str::snake($fieldRemarksKey)] ?? ''));
                $fieldEvidenceCount = $this->countEvidencePhotos($item[$fieldPhotosKey] ?? $item[Str::snake($fieldPhotosKey)] ?? []);
                $remarks = '';
                if ($isDefect || $isNotApplicable) {
                    $remarks = $fieldRemarks !== '' ? $fieldRemarks : ($isDefect ? $equipmentRemarks : '');
                }

                $rows[] = $this->baseRow(
                    report: $report,
                    inspectionType: $inspectionType,
                    inspectionTypeKey: $inspectionTypeKey,
                    actorUserId: $actorUserId,
                    locationParts: $locationParts,
                    equipment: $equipment,
                    equipmentCatalogId: $equipmentCatalogId,
                    equipmentSource: $equipmentSource,
                    checkKey: $checkMeta['key'],
                    checkName: $checkMeta['name'],
                    checkValue: $value,
                    remarks: $remarks,
                    evidenceCount: $isDefect ? ($fieldEvidenceCount > 0 ? $fieldEvidenceCount : $equipmentEvidenceCount) : 0,
                    sourceRowId: $sourceRowId,
                    sortOrder: $sortOrder++,
                );
            }
        }

        return $rows;
    }

    private function extractFrtRows(
        Report $report,
        array $payload,
        string $inspectionType,
        string $inspectionTypeKey,
        ?int $actorUserId,
    ): array {
        $dailyChecks = $payload[self::FRT_DAILY_SOURCE_PAYLOAD_KEY] ?? $payload['frt_daily_checks'] ?? [];
        $oneOffChecks = $payload[self::FRT_ONE_OFF_SOURCE_PAYLOAD_KEY] ?? $payload['frt_one_off_checks'] ?? [];

        $rows = [];
        $sortOrder = 0;
        $mainLocation = trim((string) ($payload['mainLocation'] ?? $payload['main_location'] ?? $payload['selectedLocation'] ?? $payload['location'] ?? 'FIRE TRUCK')) ?: 'FIRE TRUCK';

        if (is_array($dailyChecks)) {
            foreach ($dailyChecks as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $equipment = trim((string) ($item['equipment'] ?? $item['title'] ?? $item['name'] ?? ''));
                if ($equipment === '') {
                    continue;
                }

                $rowNumber = trim((string) ($item['rowNumber'] ?? $item['row_number'] ?? ''));
                $group = trim((string) ($item['location'] ?? ''));
                $rowKind = trim((string) ($item['rowKind'] ?? $item['row_kind'] ?? 'status')) ?: 'status';
                $checkValue = strcasecmp($rowKind, 'reading') === 0
                    ? trim((string) ($item['readingValue'] ?? $item['reading_value'] ?? ''))
                    : trim((string) ($item['status'] ?? ''));
                $remarks = trim((string) ($item['remarks'] ?? $item['remark'] ?? ''));
                $sourceRowId = trim((string) ($item['id'] ?? ''));
                if ($sourceRowId === '') {
                    $sourceRowId = $this->slug('frt-daily '.$rowNumber.' '.$equipment) ?: 'frt-daily-check-'.$index;
                }

                $rows[] = $this->baseRow(
                    report: $report,
                    inspectionType: $inspectionType,
                    inspectionTypeKey: $inspectionTypeKey,
                    actorUserId: $actorUserId,
                    locationParts: [
                        'location' => $group !== '' ? $group : $mainLocation,
                        'mainLocation' => $mainLocation,
                        'subLocation' => '',
                    ],
                    equipment: $equipment,
                    equipmentCatalogId: null,
                    equipmentSource: 'seed',
                    checkKey: strcasecmp($rowKind, 'reading') === 0 ? 'reading' : 'status',
                    checkName: strcasecmp($rowKind, 'reading') === 0 ? 'Reading' : 'Status',
                    checkValue: $checkValue,
                    remarks: $remarks,
                    evidenceCount: 0,
                    sourceRowId: $sourceRowId,
                    sortOrder: $sortOrder++,
                    checkGroup: 'FRT Daily Roster',
                    sourcePayloadKey: self::FRT_DAILY_SOURCE_PAYLOAD_KEY,
                    hasDefectOverride: strcasecmp($rowKind, 'reading') === 0 ? false : strcasecmp($checkValue, 'Issue') === 0,
                );
            }
        }

        if (is_array($oneOffChecks)) {
            foreach ($oneOffChecks as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $equipment = trim((string) ($item['equipment'] ?? $item['title'] ?? $item['name'] ?? ''));
                if ($equipment === '') {
                    continue;
                }

                $rowNumber = trim((string) ($item['rowNumber'] ?? $item['row_number'] ?? ''));
                $group = trim((string) ($item['location'] ?? ''));
                $condition = trim((string) ($item['condition'] ?? ''));
                $remarks = trim((string) ($item['remarks'] ?? $item['remark'] ?? ''));
                $sourceRowId = trim((string) ($item['id'] ?? ''));
                if ($sourceRowId === '') {
                    $sourceRowId = $this->slug('frt-one-off '.$rowNumber.' '.$equipment) ?: 'frt-one-off-check-'.$index;
                }

                $rows[] = $this->baseRow(
                    report: $report,
                    inspectionType: $inspectionType,
                    inspectionTypeKey: $inspectionTypeKey,
                    actorUserId: $actorUserId,
                    locationParts: [
                        'location' => $group !== '' ? $group : $mainLocation,
                        'mainLocation' => $mainLocation,
                        'subLocation' => '',
                    ],
                    equipment: $equipment,
                    equipmentCatalogId: null,
                    equipmentSource: 'seed',
                    checkKey: 'condition',
                    checkName: 'Condition',
                    checkValue: $condition,
                    remarks: $remarks,
                    evidenceCount: 0,
                    sourceRowId: $sourceRowId,
                    sortOrder: $sortOrder++,
                    checkGroup: 'FRT One Off Checklist',
                    sourcePayloadKey: self::FRT_ONE_OFF_SOURCE_PAYLOAD_KEY,
                    hasDefectOverride: strcasecmp($condition, 'Not Good') === 0,
                );
            }
        }

        return $rows;
    }

    private function extractHighAngleRows(
        Report $report,
        array $payload,
        string $inspectionType,
        string $inspectionTypeKey,
        ?int $actorUserId,
    ): array {
        $checks = $payload[self::HIGH_ANGLE_SOURCE_PAYLOAD_KEY] ?? $payload['high_angle_checks'] ?? [];
        if (! is_array($checks)) {
            return [];
        }

        $rows = [];
        $sortOrder = 0;
        $selectedKit = trim((string) ($payload['mainLocation'] ?? $payload['main_location'] ?? $payload['selectedLocation'] ?? $payload['location'] ?? ''));

        foreach ($checks as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $equipment = trim((string) ($item['equipment'] ?? $item['title'] ?? $item['name'] ?? ''));
            if ($equipment === '') {
                continue;
            }

            $kit = trim((string) ($item['mainLocation'] ?? $item['main_location'] ?? $item['kit'] ?? $selectedKit));
            $storageLocation = trim((string) ($item['location'] ?? ''));
            $compartment = trim((string) ($item['subLocation'] ?? $item['sub_location'] ?? ''));
            $rowNumber = trim((string) ($item['rowNumber'] ?? $item['row_number'] ?? ''));
            $condition = trim((string) ($item['condition'] ?? ''));
            $remarks = trim((string) ($item['remarks'] ?? $item['remark'] ?? ''));
            $sourceRowId = trim((string) ($item['id'] ?? ''));
            if ($sourceRowId === '') {
                $sourceRowId = $this->slug($kit.' '.$rowNumber.' '.$equipment) ?: 'high-angle-check-'.$index;
            }

            $subLocation = collect([$storageLocation, $compartment])
                ->map(fn ($part) => trim((string) $part))
                ->filter(fn ($part) => $part !== '' && strcasecmp($part, 'N/A') !== 0)
                ->implode(' > ');
            $location = $kit;
            if ($subLocation !== '') {
                $location .= ' > '.$subLocation;
            }

            $rows[] = $this->baseRow(
                report: $report,
                inspectionType: $inspectionType,
                inspectionTypeKey: $inspectionTypeKey,
                actorUserId: $actorUserId,
                locationParts: [
                    'location' => $location,
                    'mainLocation' => $kit !== '' ? $kit : $selectedKit,
                    'subLocation' => $subLocation,
                ],
                equipment: $equipment,
                equipmentCatalogId: null,
                equipmentSource: 'seed',
                checkKey: 'condition',
                checkName: 'Condition',
                checkValue: $condition,
                remarks: $remarks,
                evidenceCount: $this->countEvidencePhotos($item['photos'] ?? []),
                sourceRowId: $sourceRowId,
                sortOrder: $sortOrder++,
                checkGroup: 'High Angle Rescue Equipment Checks',
                sourcePayloadKey: self::HIGH_ANGLE_SOURCE_PAYLOAD_KEY,
                hasDefectOverride: strcasecmp($condition, 'Not Good') === 0,
            );
        }

        return $rows;
    }

    private function extractScbaRows(
        Report $report,
        array $payload,
        string $inspectionType,
        string $inspectionTypeKey,
        ?int $actorUserId,
    ): array {
        $rows = [];
        $sortOrder = 0;

        foreach (self::SCBA_SECTION_META as $sectionKey => $sectionMeta) {
            $payloadKey = $sectionMeta['payloadKey'];
            $payloadKeySnake = $sectionMeta['payloadKeySnake'];
            $checks = $payload[$payloadKey] ?? $payload[$payloadKeySnake] ?? [];
            if (! is_array($checks)) {
                continue;
            }

            foreach ($checks as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $locationParts = $this->resolveLocationParts($payload, $item);
                $brand = trim((string) ($item['brand'] ?? ''));
                $serialNo = trim((string) ($item['serialNo'] ?? $item['serial_no'] ?? $item['serialNumber'] ?? ''));
                $sectionLabel = str_replace(['backPlate', 'faceMask'], ['Back Plate', 'Face Mask'], $sectionKey);
                $equipment = trim($brand.' '.$serialNo);
                if ($equipment === '') {
                    $equipment = $sectionLabel.' '.($index + 1);
                }

                $sourceRowId = trim((string) ($item['id'] ?? ''));
                if ($sourceRowId === '') {
                    $sourceRowId = $this->slug($sectionKey.' '.$locationParts['location'].' '.$equipment) ?: 'scba-check-'.$sectionKey.'-'.$index;
                }

                $rowRemarks = trim((string) ($item['remarks'] ?? $item['remark'] ?? ''));

                foreach ($sectionMeta['fields'] as $field => $checkMeta) {
                    $snakeField = Str::snake($field);
                    $checkValue = trim((string) ($item[$field] ?? $item[$snakeField] ?? ''));
                    $hasDefect = isset($checkMeta['defectValue'])
                        && strcasecmp($checkValue, (string) $checkMeta['defectValue']) === 0;

                    $rows[] = $this->baseRow(
                        report: $report,
                        inspectionType: $inspectionType,
                        inspectionTypeKey: $inspectionTypeKey,
                        actorUserId: $actorUserId,
                        locationParts: $locationParts,
                        equipment: $equipment,
                        equipmentCatalogId: null,
                        equipmentSource: 'seed',
                        checkKey: $checkMeta['key'],
                        checkName: $checkMeta['name'],
                        checkValue: $checkValue,
                        remarks: $hasDefect ? $rowRemarks : '',
                        evidenceCount: 0,
                        sourceRowId: $sourceRowId,
                        sortOrder: $sortOrder++,
                        checkGroup: $sectionMeta['checkGroup'],
                        sourcePayloadKey: $payloadKey,
                        hasDefectOverride: $hasDefect,
                    );
                }
            }
        }

        return $rows;
    }

    private function extractFireExtinguisherRows(
        Report $report,
        array $payload,
        string $inspectionType,
        string $inspectionTypeKey,
        ?int $actorUserId,
    ): array {
        $checks = $payload[self::FIRE_EXTINGUISHER_SOURCE_PAYLOAD_KEY] ?? $payload['fire_extinguisher_checks'] ?? [];
        if (! is_array($checks)) {
            return [];
        }

        $rows = [];
        $sortOrder = 0;

        foreach ($checks as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $idLocNo = trim((string) ($item['idLocNo'] ?? $item['id_loc_no'] ?? ''));
            $barcodeNo = trim((string) ($item['barcodeNo'] ?? $item['barcode_no'] ?? ''));
            $feType = trim((string) ($item['feType'] ?? $item['fe_type'] ?? ''));
            $equipment = trim(implode(' ', array_filter([$idLocNo, $feType, $barcodeNo])));
            if ($equipment === '') {
                $equipment = 'Fire Extinguisher '.($index + 1);
            }

            $locationParts = $this->resolveLocationParts($payload, $item);
            $equipmentCatalogId = $this->nullableInteger($item['catalogId'] ?? $item['catalog_id'] ?? null);
            $equipmentSource = trim((string) ($item['equipmentSource'] ?? $item['equipment_source'] ?? $item['source'] ?? '')) ?: 'seed';
            $sourceRowId = trim((string) ($item['id'] ?? ''));
            if ($sourceRowId === '') {
                $sourceRowId = $this->slug('fire extinguisher '.($item['sourceRowNumber'] ?? $item['source_row_number'] ?? '').' '.$idLocNo.' '.$barcodeNo) ?: 'fire-extinguisher-check-'.$index;
            }
            $rowRemarks = trim((string) ($item['remarks'] ?? ''));

            foreach (self::FIRE_EXTINGUISHER_CHECK_FIELDS as $fieldKey => $fieldMeta) {
                $checkValue = trim((string) ($item[$fieldKey] ?? $item[Str::snake($fieldKey)] ?? ''));
                if ($checkValue === '') {
                    continue;
                }

                $remarksKey = $fieldMeta['remarks'];
                $photosKey = $fieldMeta['photos'];
                $fieldRemarks = trim((string) ($item[$remarksKey] ?? $item[Str::snake($remarksKey)] ?? ''));
                $fieldEvidenceCount = $this->countEvidencePhotos($item[$photosKey] ?? $item[Str::snake($photosKey)] ?? []);
                $isDefect = $this->isFireExtinguisherDefectValue($checkValue);

                $rows[] = $this->baseRow(
                    report: $report,
                    inspectionType: $inspectionType,
                    inspectionTypeKey: $inspectionTypeKey,
                    actorUserId: $actorUserId,
                    locationParts: $locationParts,
                    equipment: $equipment,
                    equipmentCatalogId: $equipmentCatalogId,
                    equipmentSource: $equipmentSource,
                    checkKey: $fieldMeta['key'],
                    checkName: $fieldMeta['name'],
                    checkValue: $checkValue,
                    remarks: $fieldRemarks !== '' ? $fieldRemarks : ($isDefect ? $rowRemarks : ''),
                    evidenceCount: $fieldEvidenceCount,
                    sourceRowId: $sourceRowId,
                    sortOrder: $sortOrder++,
                    checkGroup: 'Fire Extinguisher Checks',
                    sourcePayloadKey: self::FIRE_EXTINGUISHER_SOURCE_PAYLOAD_KEY,
                    hasDefectOverride: $isDefect,
                );
            }
        }

        return $rows;
    }

    private function baseRow(
        Report $report,
        string $inspectionType,
        string $inspectionTypeKey,
        ?int $actorUserId,
        array $locationParts,
        string $equipment,
        ?int $equipmentCatalogId,
        string $equipmentSource,
        string $checkKey,
        string $checkName,
        string $checkValue,
        string $remarks,
        int $evidenceCount,
        string $sourceRowId,
        int $sortOrder,
        string $checkGroup = 'Hydraulic Equipment Checks',
        string $sourcePayloadKey = self::HYDRAULIC_SOURCE_PAYLOAD_KEY,
        ?bool $hasDefectOverride = null,
    ): array {
        $timestamp = now();
        $submittedBy = $actorUserId ?: (int) $report->owner_user_id;

        return [
            'report_id' => (int) $report->id,
            'report_uid' => (string) $report->report_uid,
            'display_id' => (string) $report->display_id,
            'owner_user_id' => (int) $report->owner_user_id,
            'created_by_user_id' => (int) $report->owner_user_id,
            'updated_by_user_id' => $actorUserId,
            'submitted_by_user_id' => $submittedBy,
            'inspection_type' => $inspectionType,
            'inspection_type_key' => $inspectionTypeKey,
            'location' => $locationParts['location'],
            'main_location' => $locationParts['mainLocation'],
            'sub_location' => $locationParts['subLocation'],
            'equipment' => $equipment,
            'equipment_key' => $this->slug($equipment),
            'equipment_catalog_id' => $equipmentCatalogId,
            'equipment_source' => $equipmentSource !== '' ? $equipmentSource : 'seed',
            'check_group' => $checkGroup,
            'check_key' => $checkKey,
            'check_name' => $checkName,
            'check_value' => $checkValue,
            'remarks' => $remarks !== '' ? $remarks : null,
            'has_defect' => $hasDefectOverride ?? (
                strcasecmp($checkValue, 'Defect') === 0
                || strcasecmp($checkValue, 'Missing') === 0
            ),
            'has_evidence' => $evidenceCount > 0,
            'evidence_count' => $evidenceCount,
            'report_status' => (string) $report->status,
            'report_version' => (int) $report->version,
            'report_revision' => (int) $report->revision,
            'submitted_at' => $this->submittedAt($report),
            'source_payload_key' => $sourcePayloadKey,
            'source_row_id' => $sourceRowId,
            'sort_order' => $sortOrder,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    private function resolveLocationParts(array $payload, array $row): array
    {
        $mainLocation = trim((string) ($row['mainLocation'] ?? $row['main_location'] ?? $row['location'] ?? $payload['mainLocation'] ?? $payload['main_location'] ?? ''));
        $subLocation = trim((string) ($row['subLocation'] ?? $row['sub_location'] ?? $payload['subLocation'] ?? $payload['sub_location'] ?? ''));
        $location = $this->normalizeLocationValue($row['selectedLocation'] ?? $row['locationPath'] ?? $payload['selectedLocation'] ?? $payload['location'] ?? '');

        if ($location === '') {
            $location = $subLocation !== '' ? "{$mainLocation} > {$subLocation}" : $mainLocation;
        }
        if ($mainLocation === '' && str_contains($location, '>')) {
            [$mainLocation, $subLocationFromPath] = array_map('trim', explode('>', $location, 2));
            $subLocation = $subLocation !== '' ? $subLocation : $subLocationFromPath;
        }
        if ($mainLocation === '') {
            $mainLocation = $location;
        }

        return [
            'location' => $location,
            'mainLocation' => $mainLocation,
            'subLocation' => $subLocation,
        ];
    }

    private function isInspectionReport(Report $report): bool
    {
        return strtolower(trim((string) $report->report_type)) === self::REPORT_TYPE_INSPECTION;
    }

    private function normalizeLocationValue(mixed $value): string
    {
        if (is_array($value)) {
            return collect($value)
                ->map(fn ($part) => trim((string) $part))
                ->filter()
                ->implode(' > ');
        }

        return trim((string) $value);
    }

    private function countEvidencePhotos(mixed $photos): int
    {
        if (! is_array($photos)) {
            return 0;
        }

        return collect($photos)
            ->filter(fn ($photo) => is_array($photo) && trim((string) ($photo['url'] ?? '')) !== '')
            ->count();
    }

    private function isFireExtinguisherDefectValue(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['not good', 'no', 'not operational'], true);
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function isDraft(Report $report): bool
    {
        return (string) $report->status === self::STATUS_DRAFT;
    }

    private function submittedAt(Report $report): ?Carbon
    {
        return $report->submitted_at ?: null;
    }

    private function slug(string $value): string
    {
        return Str::slug($value);
    }
}
