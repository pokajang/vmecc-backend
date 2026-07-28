<?php

namespace App\Services;

use App\Models\ReportMedia;
use App\Services\Inspection\HsePayloadService;
use App\Support\Inspection\FrtDailyReference;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InspectionPayloadService
{
    public function __construct(
        private readonly HsePayloadService $hsePayloadService,
    ) {}

    private const INSPECTION_MAX_PHOTO_COUNT = 10;

    private const INSPECTION_MAX_PHOTO_BYTES = 1572864; // 1.5 MB

    private const INSPECTION_MAX_TOTAL_PHOTO_BYTES = 12582912; // 12 MB

    private const INSPECTION_REPORT_REMARKS_MAX_LENGTH = 2000;

    private const INSPECTION_ALLOWED_IMAGE_MIMES = ['jpeg', 'jpg', 'png', 'webp'];

    private const INSPECTION_ER_AUX_CONDITION_VALUES = ['OK', 'Defect', 'Missing', 'N/A'];

    private const INSPECTION_FRT_DAILY_STATUS_VALUES = ['Checked', 'Issue'];

    private const INSPECTION_FRT_ONE_OFF_STATUS_VALUES = ['Good', 'Not Good'];

    private const INSPECTION_HIGH_ANGLE_STATUS_VALUES = ['Good', 'Not Good'];

    private const INSPECTION_HYDRAULIC_STATUS_VALUES = ['OK', 'Defect', 'N/A'];

    private const INSPECTION_SCBA_STATUS_VALUES = ['Good', 'Not Good'];

    private const INSPECTION_FIRE_EXTINGUISHER_STATUS_VALUES = [
        'physicalCondition' => ['Good', 'Not Good', 'N/A'],
        'signageCondition' => ['Good', 'Not Good', 'N/A'],
        'boxKeyAvailability' => ['Yes', 'No', 'N/A'],
        'boxGlassAvailability' => ['Yes', 'No', 'N/A'],
        'operationalCondition' => ['Good', 'Not Good', 'N/A', 'Operational', 'Not Operational'],
    ];

    private const INSPECTION_FIRE_EXTINGUISHER_CHECK_EVIDENCE_FIELDS = [
        'physicalCondition' => ['remarks' => 'physicalConditionRemarks', 'photos' => 'physicalConditionPhotos'],
        'signageCondition' => ['remarks' => 'signageConditionRemarks', 'photos' => 'signageConditionPhotos'],
        'boxKeyAvailability' => ['remarks' => 'boxKeyAvailabilityRemarks', 'photos' => 'boxKeyAvailabilityPhotos'],
        'boxGlassAvailability' => ['remarks' => 'boxGlassAvailabilityRemarks', 'photos' => 'boxGlassAvailabilityPhotos'],
        'operationalCondition' => ['remarks' => 'operationalConditionRemarks', 'photos' => 'operationalConditionPhotos'],
    ];

    private const INSPECTION_HYDRAULIC_CHECK_FIELDS = [
        'physicalCondition',
        'mechanicalCondition',
        'noLeakage',
        'functionTest',
    ];

    private const INSPECTION_HYDRAULIC_CHECK_EVIDENCE_FIELDS = [
        'physicalCondition' => ['remarks' => 'physicalConditionRemarks', 'photos' => 'physicalConditionPhotos'],
        'mechanicalCondition' => ['remarks' => 'mechanicalConditionRemarks', 'photos' => 'mechanicalConditionPhotos'],
        'noLeakage' => ['remarks' => 'noLeakageRemarks', 'photos' => 'noLeakagePhotos'],
        'functionTest' => ['remarks' => 'functionTestRemarks', 'photos' => 'functionTestPhotos'],
    ];

    private const INSPECTION_SCBA_SECTION_FIELDS = [
        'backPlate' => [
            'backPlateHarnessCondition' => 'status',
            'highPressureHose' => 'status',
            'pressureGauge' => 'status',
            'alarmDevice' => 'status',
            'demandValve' => 'status',
            'sealing' => 'status',
            'cleanliness' => 'status',
        ],
        'cylinder' => [
            'servicePressure' => 'text',
            'containedPressure' => 'text',
            'physicalCondition' => 'status',
            'handwheelCondition' => 'status',
            'valveBodyCondition' => 'status',
            'screwPlugCondition' => 'status',
            'cleanliness' => 'status',
        ],
        'faceMask' => [
            'visorCondition' => 'status',
            'ldvPort' => 'status',
            'ldvReleaseButton' => 'status',
            'leakTest' => 'status',
            'speechDiaphragm' => 'status',
            'harness' => 'status',
            'neckStrap' => 'status',
        ],
    ];

    private function hasInspectionRows(array $payload, string $camelKey, string $snakeKey): bool
    {
        $rows = $payload[$camelKey] ?? $payload[$snakeKey] ?? [];

        return is_array($rows) && count($rows) > 0;
    }

    public function inspectorField(array $payload): ?string
    {
        $type = Str::of((string) ($payload['incidentType'] ?? $payload['inspectionType'] ?? ''))
            ->squish()
            ->lower()
            ->toString();

        if (
            str_contains($type, 'er aux')
            || $this->hasInspectionRows($payload, 'erAuxChecks', 'er_aux_checks')
        ) {
            return 'erAuxInspectedBy';
        }

        if (
            str_contains($type, 'fire extinguisher')
            || $this->hasInspectionRows(
                $payload,
                'fireExtinguisherChecks',
                'fire_extinguisher_checks'
            )
        ) {
            return 'fireExtinguisherInspectedBy';
        }

        if (
            $this->isFrtDailyInspectionType((string) ($payload['incidentType'] ?? $payload['inspectionType'] ?? ''))
            || $this->hasInspectionRows($payload, 'frtDailyChecks', 'frt_daily_checks')
            || $this->hasInspectionRows($payload, 'frtOneOffChecks', 'frt_one_off_checks')
        ) {
            return 'frtInspectedBy';
        }

        if (
            str_contains($type, 'high angle')
            || $this->hasInspectionRows($payload, 'highAngleChecks', 'high_angle_checks')
        ) {
            return 'highAngleInspectedBy';
        }

        if (
            str_contains($type, 'scba')
            || $this->hasInspectionRows($payload, 'scbaBackPlateChecks', 'scba_back_plate_checks')
            || $this->hasInspectionRows($payload, 'scbaCylinderChecks', 'scba_cylinder_checks')
            || $this->hasInspectionRows($payload, 'scbaFaceMaskChecks', 'scba_face_mask_checks')
            || $this->hasInspectionRows($payload, 'scbaCustomSections', 'scba_custom_sections')
        ) {
            return 'scbaInspectedBy';
        }

        if (
            $this->isHseInspectionType((string) ($payload['incidentType'] ?? $payload['inspectionType'] ?? ''))
            || $this->hasInspectionRows($payload, 'hseSelections', 'hse_selections')
        ) {
            return 'hseInspectedBy';
        }

        return null;
    }

    public function normalize(array $payload): array
    {
        return $this->normalizeInspectionPayload($payload, true);
    }

    public function normalizeForDraft(array $payload): array
    {
        return $this->normalizeInspectionPayload($payload, false);
    }

    private function normalizeInspectionPayload(array $payload, bool $validateCompleteness): array
    {
        if (! array_key_exists('checklist', $payload)) {
            $payload['checklist'] = [];
        }

        $payload['checklist'] = $this->normalizeInspectionChecklist($payload['checklist']);
        if (! empty($payload['checklist']) && trim((string) ($payload['checklistVersion'] ?? '')) === '') {
            $payload['checklistVersion'] = 'inspection-checklist-v1';
        }

        $payload['reportRemarks'] = trim((string) ($payload['reportRemarks'] ?? $payload['report_remarks'] ?? ''));
        unset($payload['report_remarks']);

        $inspectionType = (string) ($payload['incidentType'] ?? $payload['inspectionType'] ?? '');
        if (
            array_key_exists('inspectionIssues', $payload)
            || array_key_exists('inspection_issues', $payload)
            || (
                ($this->isGeneralInspectionType($inspectionType) || $this->isHseInspectionType($inspectionType))
                && array_key_exists('issues', $payload)
            )
        ) {
            $payload['inspectionIssues'] = $this->normalizeInspectionIssues(
                $payload['inspectionIssues'] ?? $payload['inspection_issues'] ?? $payload['issues'] ?? [],
                'payload.inspectionIssues'
            );
            $payload['issues'] = $payload['inspectionIssues'];
            unset($payload['inspection_issues']);
        }

        if (array_key_exists('erAuxChecks', $payload) || array_key_exists('er_aux_checks', $payload)) {
            $payload['erAuxChecks'] = $this->normalizeInspectionErAuxChecks(
                $payload['erAuxChecks'] ?? $payload['er_aux_checks']
            );
            unset($payload['er_aux_checks']);
        }

        if (array_key_exists('highAngleChecks', $payload) || array_key_exists('high_angle_checks', $payload)) {
            $payload['highAngleChecks'] = $this->normalizeInspectionHighAngleChecks(
                $payload['highAngleChecks'] ?? $payload['high_angle_checks']
            );
            unset($payload['high_angle_checks']);
        }

        if (array_key_exists('fireExtinguisherChecks', $payload) || array_key_exists('fire_extinguisher_checks', $payload)) {
            $payload['fireExtinguisherChecks'] = $this->normalizeInspectionFireExtinguisherChecks(
                $payload['fireExtinguisherChecks'] ?? $payload['fire_extinguisher_checks']
            );
            unset($payload['fire_extinguisher_checks']);
        }

        if (array_key_exists('erAuxInspectedBy', $payload) || array_key_exists('er_aux_inspected_by', $payload)) {
            $payload['erAuxInspectedBy'] = trim((string) ($payload['erAuxInspectedBy'] ?? $payload['er_aux_inspected_by'] ?? ''));
            unset($payload['er_aux_inspected_by']);
        }

        if (array_key_exists('erAuxInspectionDate', $payload) || array_key_exists('er_aux_inspection_date', $payload)) {
            $payload['erAuxInspectionDate'] = trim((string) ($payload['erAuxInspectionDate'] ?? $payload['er_aux_inspection_date'] ?? ''));
            unset($payload['er_aux_inspection_date']);
        }

        if (array_key_exists('highAngleInspectedBy', $payload) || array_key_exists('high_angle_inspected_by', $payload)) {
            $payload['highAngleInspectedBy'] = trim((string) ($payload['highAngleInspectedBy'] ?? $payload['high_angle_inspected_by'] ?? ''));
            unset($payload['high_angle_inspected_by']);
        }

        if (array_key_exists('highAngleInspectionDate', $payload) || array_key_exists('high_angle_inspection_date', $payload)) {
            $payload['highAngleInspectionDate'] = trim((string) ($payload['highAngleInspectionDate'] ?? $payload['high_angle_inspection_date'] ?? ''));
            unset($payload['high_angle_inspection_date']);
        }

        if (array_key_exists('fireExtinguisherInspectedBy', $payload) || array_key_exists('fire_extinguisher_inspected_by', $payload)) {
            $payload['fireExtinguisherInspectedBy'] = trim((string) ($payload['fireExtinguisherInspectedBy'] ?? $payload['fire_extinguisher_inspected_by'] ?? ''));
            unset($payload['fire_extinguisher_inspected_by']);
        }

        if (array_key_exists('fireExtinguisherInspectionDate', $payload) || array_key_exists('fire_extinguisher_inspection_date', $payload)) {
            $payload['fireExtinguisherInspectionDate'] = trim((string) ($payload['fireExtinguisherInspectionDate'] ?? $payload['fire_extinguisher_inspection_date'] ?? ''));
            unset($payload['fire_extinguisher_inspection_date']);
        }

        if (array_key_exists('hydraulicChecks', $payload) || array_key_exists('hydraulic_checks', $payload)) {
            $payload['hydraulicChecks'] = $this->normalizeInspectionHydraulicChecks(
                $payload['hydraulicChecks'] ?? $payload['hydraulic_checks']
            );
            unset($payload['hydraulic_checks']);
        }

        $isFrtPayload = $this->isFrtDailyInspectionType((string) ($payload['incidentType'] ?? $payload['inspectionType'] ?? ''))
            || $this->hasInspectionRows($payload, 'frtDailyChecks', 'frt_daily_checks')
            || $this->hasInspectionRows($payload, 'frtOneOffChecks', 'frt_one_off_checks')
            || trim((string) ($payload['frtTruckId'] ?? $payload['frt_truck_id'] ?? '')) !== ''
            || trim((string) ($payload['frtTruckPlateNo'] ?? $payload['frt_truck_plate_no'] ?? '')) !== '';

        if ($isFrtPayload) {
            $hasExplicitTruckReference = array_key_exists('frtTruckReference', $payload) || array_key_exists('frt_truck_reference', $payload);
            $legacyLocation = trim((string) ($payload['mainLocation'] ?? $payload['main_location'] ?? $payload['selectedLocation'] ?? $payload['location'] ?? ''));
            $truckReference = $this->normalizeInspectionFrtTruckReference(
                $payload['frtTruckReference'] ?? $payload['frt_truck_reference'] ?? []
            );
            $truckPlate = trim((string) (
                $payload['frtTruckPlateNo'] ??
                $payload['frt_truck_plate_no'] ??
                ($hasExplicitTruckReference ? ($truckReference['plateNo'] ?? '') : '') ??
                $payload['mainLocation'] ??
                $payload['main_location'] ??
                ''
            ));
            if (Str::of($truckPlate)->squish()->lower()->toString() === Str::of(FrtDailyReference::MAIN_LOCATION)->squish()->lower()->toString()) {
                $truckPlate = trim((string) ($truckReference['plateNo'] ?? ''));
            }
            if ($truckPlate === '' && Str::of($legacyLocation)->squish()->lower()->toString() === Str::of(FrtDailyReference::MAIN_LOCATION)->squish()->lower()->toString()) {
                $truckPlate = trim((string) ($truckReference['plateNo'] ?? FrtDailyReference::TRUCK_REFERENCE['plateNo']));
            }

            $payload['frtTruckReference'] = array_merge($truckReference, ['plateNo' => $truckPlate]);
            $payload['frtTruckPlateNo'] = $truckPlate;
            $payload['frtTruckId'] = trim((string) ($payload['frtTruckId'] ?? $payload['frt_truck_id'] ?? ''));
            $payload['location'] = $truckPlate;
            $payload['selectedLocation'] = $truckPlate;
            $payload['mainLocation'] = $truckPlate;
            $payload['subLocation'] = '';
            $payload['locationPath'] = [$truckPlate];
            unset($payload['frt_truck_id'], $payload['frt_truck_plate_no']);
        }

        if (array_key_exists('frtInspectedBy', $payload) || array_key_exists('frt_inspected_by', $payload)) {
            $payload['frtInspectedBy'] = trim((string) ($payload['frtInspectedBy'] ?? $payload['frt_inspected_by'] ?? ''));
            unset($payload['frt_inspected_by']);
        }

        if (array_key_exists('frtInspectionDate', $payload) || array_key_exists('frt_inspection_date', $payload)) {
            $payload['frtInspectionDate'] = trim((string) ($payload['frtInspectionDate'] ?? $payload['frt_inspection_date'] ?? ''));
            unset($payload['frt_inspection_date']);
        }

        if (array_key_exists('frtShift', $payload) || array_key_exists('frt_shift', $payload)) {
            $payload['frtShift'] = trim((string) ($payload['frtShift'] ?? $payload['frt_shift'] ?? ''));
            unset($payload['frt_shift']);
        }

        if ($isFrtPayload) {
            $payload['frtTruckReference'] = $this->normalizeInspectionFrtTruckReference(
                $payload['frtTruckReference'] ?? $payload['frt_truck_reference'] ?? []
            );
            unset($payload['frt_truck_reference']);
        }

        if (array_key_exists('frtDailyChecks', $payload) || array_key_exists('frt_daily_checks', $payload)) {
            $payload['frtDailyChecks'] = $this->normalizeInspectionFrtDailyChecks(
                $payload['frtDailyChecks'] ?? $payload['frt_daily_checks']
            );
            unset($payload['frt_daily_checks']);
        }

        if (array_key_exists('frtDailyRemarks', $payload) || array_key_exists('frt_daily_remarks', $payload)) {
            $payload['frtDailyRemarks'] = trim((string) ($payload['frtDailyRemarks'] ?? $payload['frt_daily_remarks'] ?? ''));
            unset($payload['frt_daily_remarks']);
        }

        if (array_key_exists('frtOneOffChecks', $payload) || array_key_exists('frt_one_off_checks', $payload)) {
            $payload['frtOneOffChecks'] = $this->normalizeInspectionFrtOneOffChecks(
                $payload['frtOneOffChecks'] ?? $payload['frt_one_off_checks']
            );
            unset($payload['frt_one_off_checks']);
        }

        if (array_key_exists('frtOneOffRemarks', $payload) || array_key_exists('frt_one_off_remarks', $payload)) {
            $payload['frtOneOffRemarks'] = trim((string) ($payload['frtOneOffRemarks'] ?? $payload['frt_one_off_remarks'] ?? ''));
            unset($payload['frt_one_off_remarks']);
        }

        if (array_key_exists('scbaInspectedBy', $payload) || array_key_exists('scba_inspected_by', $payload)) {
            $payload['scbaInspectedBy'] = trim((string) ($payload['scbaInspectedBy'] ?? $payload['scba_inspected_by'] ?? ''));
            unset($payload['scba_inspected_by']);
        }

        if (array_key_exists('scbaInspectionDate', $payload) || array_key_exists('scba_inspection_date', $payload)) {
            $payload['scbaInspectionDate'] = trim((string) ($payload['scbaInspectionDate'] ?? $payload['scba_inspection_date'] ?? ''));
            unset($payload['scba_inspection_date']);
        }

        if (array_key_exists('scbaBackPlateChecks', $payload) || array_key_exists('scba_back_plate_checks', $payload)) {
            $payload['scbaBackPlateChecks'] = $this->normalizeInspectionScbaChecks(
                $payload['scbaBackPlateChecks'] ?? $payload['scba_back_plate_checks'],
                'backPlate',
                'payload.scbaBackPlateChecks'
            );
            unset($payload['scba_back_plate_checks']);
        }

        if (array_key_exists('scbaCylinderChecks', $payload) || array_key_exists('scba_cylinder_checks', $payload)) {
            $payload['scbaCylinderChecks'] = $this->normalizeInspectionScbaChecks(
                $payload['scbaCylinderChecks'] ?? $payload['scba_cylinder_checks'],
                'cylinder',
                'payload.scbaCylinderChecks'
            );
            unset($payload['scba_cylinder_checks']);
        }

        if (array_key_exists('scbaFaceMaskChecks', $payload) || array_key_exists('scba_face_mask_checks', $payload)) {
            $payload['scbaFaceMaskChecks'] = $this->normalizeInspectionScbaChecks(
                $payload['scbaFaceMaskChecks'] ?? $payload['scba_face_mask_checks'],
                'faceMask',
                'payload.scbaFaceMaskChecks'
            );
            unset($payload['scba_face_mask_checks']);
        }

        if (array_key_exists('scbaCustomSections', $payload) || array_key_exists('scba_custom_sections', $payload)) {
            $payload['scbaCustomSections'] = $this->normalizeInspectionScbaCustomSections(
                $payload['scbaCustomSections'] ?? $payload['scba_custom_sections'],
                'payload.scbaCustomSections',
                $validateCompleteness
            );
            unset($payload['scba_custom_sections']);
        }

        $payload = $this->hsePayloadService->normalize($payload);

        return $payload;
    }

    private function normalizeInspectionChecklist(mixed $checklist): array
    {
        if (! is_array($checklist)) {
            throw ValidationException::withMessages([
                'payload.checklist' => ['Checklist must be an array.'],
            ]);
        }

        $rows = [];
        foreach ($checklist as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    "payload.checklist.{$index}" => ['Invalid checklist item.'],
                ]);
            }
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '') {
                throw ValidationException::withMessages([
                    "payload.checklist.{$index}.label" => ['Checklist label is required.'],
                ]);
            }
            $id = trim((string) ($item['id'] ?? ''));
            $inspectionType = trim((string) ($item['inspectionType'] ?? $item['incidentType'] ?? ''));
            $selected = ($item['selected'] ?? true) !== false;
            $selectedAt = trim((string) ($item['selectedAt'] ?? $item['selected_at'] ?? ''));

            $rows[] = array_merge($item, [
                'id' => $id !== '' ? $id : strtolower(preg_replace('/[^a-z0-9]+/i', '-', $inspectionType.'-'.$label)),
                'label' => $label,
                'inspectionType' => $inspectionType,
                'selected' => $selected,
                'selectedAt' => $selectedAt,
            ]);
        }

        return $rows;
    }

    private function normalizeInspectionIssues(mixed $issues, string $fieldPath): array
    {
        if (! is_array($issues)) {
            throw ValidationException::withMessages([
                $fieldPath => ['Inspection findings must be an array.'],
            ]);
        }

        $rows = [];
        foreach ($issues as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}" => ['Invalid inspection finding item.'],
                ]);
            }

            $description = trim((string) ($item['description'] ?? $item['details'] ?? ''));
            $actionRequired = trim((string) ($item['actionRequired'] ?? $item['action_required'] ?? ''));
            $photos = $this->normalizeInspectionPhotos($item['photos'] ?? $item['issue_photos'] ?? []);
            if ($description === '' && $actionRequired === '' && count($photos) === 0) {
                continue;
            }

            $normalized = array_merge($item, [
                'id' => trim((string) ($item['id'] ?? $item['issueId'] ?? $item['issue_id'] ?? '')) ?: 'issue-'.($index + 1),
                'description' => $description,
                'actionRequired' => $actionRequired,
                'photos' => $photos,
                'createdAt' => trim((string) ($item['createdAt'] ?? $item['created_at'] ?? '')),
                'updatedAt' => trim((string) ($item['updatedAt'] ?? $item['updated_at'] ?? '')),
            ]);
            unset(
                $normalized['details'],
                $normalized['action_required'],
                $normalized['issueId'],
                $normalized['issue_id'],
                $normalized['issue_photos'],
                $normalized['created_at'],
                $normalized['updated_at']
            );

            $rows[] = $normalized;
        }

        return $rows;
    }

    private function normalizeInspectionHydraulicChecks(mixed $checks): array
    {
        if (! is_array($checks)) {
            throw ValidationException::withMessages([
                'payload.hydraulicChecks' => ['Hydraulic checks must be an array.'],
            ]);
        }

        $rows = [];
        foreach ($checks as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    "payload.hydraulicChecks.{$index}" => ['Invalid hydraulic check item.'],
                ]);
            }

            $location = trim((string) ($item['location'] ?? $item['mainLocation'] ?? $item['main_location'] ?? ''));
            $equipment = trim((string) ($item['equipment'] ?? $item['title'] ?? $item['name'] ?? ''));
            if ($equipment === '') {
                throw ValidationException::withMessages([
                    "payload.hydraulicChecks.{$index}.equipment" => ['Hydraulic equipment is required.'],
                ]);
            }

            $normalized = array_merge($item, [
                'id' => trim((string) ($item['id'] ?? '')) ?: $this->inspectionPayloadSlug($location.' '.$equipment),
                'location' => $location,
                'equipment' => $equipment,
                'equipmentId' => $this->nullableInteger($item['equipmentId'] ?? $item['equipment_id'] ?? $item['equipmentCatalogId'] ?? $item['equipment_catalog_id'] ?? null),
                'equipmentKey' => trim((string) ($item['equipmentKey'] ?? $item['equipment_key'] ?? '')) ?: $this->inspectionPayloadSlug($equipment),
                'equipmentSource' => trim((string) ($item['equipmentSource'] ?? $item['equipment_source'] ?? '')) ?: 'seed',
                'equipmentDescription' => trim((string) ($item['equipmentDescription'] ?? $item['equipment_description'] ?? $item['description'] ?? '')),
                'isCustomEquipment' => filter_var($item['isCustomEquipment'] ?? $item['is_custom_equipment'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'remarks' => trim((string) ($item['remarks'] ?? $item['remark'] ?? $item['defects'] ?? '')),
                'photos' => $this->normalizeInspectionPhotos($item['photos'] ?? []),
            ]);
            unset(
                $normalized['equipment_id'],
                $normalized['equipmentCatalogId'],
                $normalized['equipment_catalog_id'],
                $normalized['equipment_key'],
                $normalized['equipment_source'],
                $normalized['equipment_description'],
                $normalized['is_custom_equipment']
            );

            foreach (self::INSPECTION_HYDRAULIC_CHECK_FIELDS as $field) {
                $snakeField = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
                $normalized[$field] = $this->normalizeInspectionHydraulicStatus(
                    $item[$field] ?? $item[$snakeField] ?? '',
                    "payload.hydraulicChecks.{$index}.{$field}"
                );
                unset($normalized[$snakeField]);
            }

            foreach (self::INSPECTION_HYDRAULIC_CHECK_EVIDENCE_FIELDS as $meta) {
                $remarksKey = $meta['remarks'];
                $photosKey = $meta['photos'];
                $snakeRemarksKey = Str::snake($remarksKey);
                $snakePhotosKey = Str::snake($photosKey);

                $normalized[$remarksKey] = trim((string) ($item[$remarksKey] ?? $item[$snakeRemarksKey] ?? ''));
                $normalized[$photosKey] = $this->normalizeInspectionPhotos($item[$photosKey] ?? $item[$snakePhotosKey] ?? []);
                unset($normalized[$snakeRemarksKey], $normalized[$snakePhotosKey]);
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    private function normalizeInspectionErAuxChecks(mixed $checks): array
    {
        if (! is_array($checks)) {
            throw ValidationException::withMessages([
                'payload.erAuxChecks' => ['ER Aux checks must be an array.'],
            ]);
        }

        $rows = [];
        foreach ($checks as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    "payload.erAuxChecks.{$index}" => ['Invalid ER Aux check item.'],
                ]);
            }

            $location = trim((string) ($item['location'] ?? $item['mainLocation'] ?? $item['main_location'] ?? ''));
            $equipment = trim((string) ($item['equipment'] ?? $item['title'] ?? $item['name'] ?? ''));
            if ($equipment === '') {
                throw ValidationException::withMessages([
                    "payload.erAuxChecks.{$index}.equipment" => ['ER Aux equipment is required.'],
                ]);
            }

            $normalized = array_merge($item, [
                'id' => trim((string) ($item['id'] ?? '')) ?: $this->inspectionPayloadSlug($location.' '.$equipment),
                'location' => $location,
                'equipment' => $equipment,
                'equipmentId' => $this->nullableInteger($item['equipmentId'] ?? $item['equipment_id'] ?? $item['equipmentCatalogId'] ?? $item['equipment_catalog_id'] ?? null),
                'equipmentKey' => trim((string) ($item['equipmentKey'] ?? $item['equipment_key'] ?? '')) ?: $this->inspectionPayloadSlug($equipment),
                'equipmentSource' => trim((string) ($item['equipmentSource'] ?? $item['equipment_source'] ?? '')) ?: 'seed',
                'equipmentDescription' => trim((string) ($item['equipmentDescription'] ?? $item['equipment_description'] ?? $item['description'] ?? '')),
                'defaultQuantity' => trim((string) ($item['defaultQuantity'] ?? $item['default_quantity'] ?? '')),
                'isCustomEquipment' => filter_var($item['isCustomEquipment'] ?? $item['is_custom_equipment'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'quantity' => trim((string) ($item['quantity'] ?? $item['qty'] ?? '')),
                'condition' => $this->normalizeInspectionErAuxCondition(
                    $item['condition'] ?? '',
                    "payload.erAuxChecks.{$index}.condition"
                ),
                'remarks' => trim((string) ($item['remarks'] ?? $item['remark'] ?? '')),
                'defectRemarks' => trim((string) ($item['defectRemarks'] ?? $item['defect_remarks'] ?? '')),
                'additionalNotes' => trim((string) ($item['additionalNotes'] ?? $item['additional_notes'] ?? '')),
                'defectPhotos' => $this->normalizeInspectionPhotos($item['defectPhotos'] ?? $item['defect_photos'] ?? []),
                'photos' => $this->normalizeInspectionPhotos($item['photos'] ?? []),
            ]);

            unset(
                $normalized['equipment_id'],
                $normalized['equipmentCatalogId'],
                $normalized['equipment_catalog_id'],
                $normalized['equipment_key'],
                $normalized['equipment_source'],
                $normalized['equipment_description'],
                $normalized['default_quantity'],
                $normalized['is_custom_equipment'],
                $normalized['defect_remarks'],
                $normalized['additional_notes'],
                $normalized['defect_photos']
            );

            $rows[] = $normalized;
        }

        return $rows;
    }

    private function normalizeInspectionFireExtinguisherChecks(mixed $checks): array
    {
        if (! is_array($checks)) {
            throw ValidationException::withMessages([
                'payload.fireExtinguisherChecks' => ['Fire extinguisher checks must be an array.'],
            ]);
        }

        $rows = [];
        foreach ($checks as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    "payload.fireExtinguisherChecks.{$index}" => ['Invalid fire extinguisher check item.'],
                ]);
            }

            $catalogId = $this->nullableInteger($item['catalogId'] ?? $item['catalog_id'] ?? null);
            $sourceRowNumber = trim((string) ($item['sourceRowNumber'] ?? $item['source_row_number'] ?? ''));
            $idLocNo = trim((string) ($item['idLocNo'] ?? $item['id_loc_no'] ?? ''));
            $barcodeNo = trim((string) ($item['barcodeNo'] ?? $item['barcode_no'] ?? ''));
            if ($catalogId === null && $sourceRowNumber === '' && $idLocNo === '' && $barcodeNo === '') {
                throw ValidationException::withMessages([
                    "payload.fireExtinguisherChecks.{$index}.id" => ['Fire extinguisher row identity is required.'],
                ]);
            }

            $mainLocation = trim((string) ($item['mainLocation'] ?? $item['main_location'] ?? $item['location'] ?? ''));
            $subLocation = trim((string) ($item['subLocation'] ?? $item['sub_location'] ?? ''));
            $normalized = array_merge($item, [
                'id' => trim((string) ($item['id'] ?? '')) ?: $this->inspectionPayloadSlug('fire-extinguisher '.$sourceRowNumber.' '.$idLocNo.' '.$barcodeNo),
                'catalogId' => $catalogId,
                'sourceRowNumber' => $sourceRowNumber,
                'equipmentSource' => trim((string) ($item['equipmentSource'] ?? $item['equipment_source'] ?? $item['source'] ?? '')) ?: 'seed',
                'zone' => trim((string) ($item['zone'] ?? '')),
                'mainLocation' => $mainLocation,
                'subLocation' => $subLocation,
                'location' => trim((string) ($item['location'] ?? $mainLocation)),
                'locationPath' => array_values(array_filter([$mainLocation, $subLocation], fn ($value) => trim((string) $value) !== '')),
                'idLocNo' => $idLocNo,
                'barcodeNo' => $barcodeNo,
                'feType' => str_replace(["CO\u{00B2}", "CO\u{FFFD}"], 'CO2', trim((string) ($item['feType'] ?? $item['fe_type'] ?? ''))),
                'certificationValidity' => trim((string) ($item['certificationValidity'] ?? $item['certification_validity'] ?? '')),
                'remarks' => trim((string) ($item['remarks'] ?? $item['remark'] ?? '')),
                'photos' => $this->normalizeInspectionPhotos($item['photos'] ?? []),
            ]);
            unset(
                $normalized['catalog_id'],
                $normalized['source_row_number'],
                $normalized['equipment_source'],
                $normalized['main_location'],
                $normalized['sub_location'],
                $normalized['id_loc_no'],
                $normalized['barcode_no'],
                $normalized['fe_type'],
                $normalized['certification_validity'],
                $normalized['certificationValidityRaw'],
                $normalized['certification_validity_raw'],
                $normalized['daysLeftToExpire'],
                $normalized['days_left_to_expire'],
                $normalized['remark']
            );

            foreach (self::INSPECTION_FIRE_EXTINGUISHER_STATUS_VALUES as $field => $allowed) {
                $snakeField = Str::snake($field);
                $normalized[$field] = $this->normalizeInspectionFireExtinguisherStatus(
                    $item[$field] ?? $item[$snakeField] ?? '',
                    $allowed,
                    "payload.fireExtinguisherChecks.{$index}.{$field}"
                );
                unset($normalized[$snakeField]);
            }

            foreach (self::INSPECTION_FIRE_EXTINGUISHER_CHECK_EVIDENCE_FIELDS as $meta) {
                $remarksKey = $meta['remarks'];
                $photosKey = $meta['photos'];
                $snakeRemarksKey = Str::snake($remarksKey);
                $snakePhotosKey = Str::snake($photosKey);
                $normalized[$remarksKey] = trim((string) ($item[$remarksKey] ?? $item[$snakeRemarksKey] ?? ''));
                $normalized[$photosKey] = $this->normalizeInspectionPhotos($item[$photosKey] ?? $item[$snakePhotosKey] ?? []);
                unset($normalized[$snakeRemarksKey], $normalized[$snakePhotosKey]);
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    private function normalizeInspectionFrtTruckReference(mixed $reference): array
    {
        $value = is_array($reference) ? $reference : [];

        return [
            'plateNo' => trim((string) ($value['plateNo'] ?? $value['plate_no'] ?? FrtDailyReference::TRUCK_REFERENCE['plateNo'])),
            'roadTaxExpiry' => trim((string) ($value['roadTaxExpiry'] ?? $value['road_tax_expiry'] ?? FrtDailyReference::TRUCK_REFERENCE['roadTaxExpiry'])),
            'insuranceExpiry' => trim((string) ($value['insuranceExpiry'] ?? $value['insurance_expiry'] ?? FrtDailyReference::TRUCK_REFERENCE['insuranceExpiry'])),
            'puspakomExpiry' => trim((string) ($value['puspakomExpiry'] ?? $value['puspakom_expiry'] ?? FrtDailyReference::TRUCK_REFERENCE['puspakomExpiry'])),
        ];
    }

    private function normalizeInspectionFrtDailyChecks(mixed $checks): array
    {
        if (! is_array($checks)) {
            throw ValidationException::withMessages([
                'payload.frtDailyChecks' => ['FRT daily checks must be an array.'],
            ]);
        }

        $rows = [];
        foreach ($checks as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    "payload.frtDailyChecks.{$index}" => ['Invalid FRT daily check item.'],
                ]);
            }

            $rawId = trim((string) ($item['id'] ?? ''));
            $rowNumber = trim((string) ($item['rowNumber'] ?? $item['row_number'] ?? ''));
            $canonical = FrtDailyReference::findDailyRow($rawId, $rowNumber);
            $equipment = trim((string) ($item['equipment'] ?? $item['title'] ?? $item['name'] ?? ($canonical['equipment'] ?? '')));
            if ($equipment === '') {
                throw ValidationException::withMessages([
                    "payload.frtDailyChecks.{$index}.equipment" => ['FRT daily equipment is required.'],
                ]);
            }

            $location = trim((string) ($item['location'] ?? ($canonical['location'] ?? '')));
            $rowKind = trim((string) ($item['rowKind'] ?? $item['row_kind'] ?? 'status')) ?: 'status';
            if (! in_array(strtolower($rowKind), ['status', 'reading'], true)) {
                throw ValidationException::withMessages([
                    "payload.frtDailyChecks.{$index}.rowKind" => ['FRT daily row kind must be status or reading.'],
                ]);
            }

            $normalized = array_merge($item, [
                'id' => $canonical['id'] ?? ($rawId !== '' ? $rawId : $this->inspectionPayloadSlug('frt-daily '.$rowNumber.' '.$equipment)),
                'rowNumber' => $canonical['rowNumber'] ?? $rowNumber,
                'mainLocation' => FrtDailyReference::MAIN_LOCATION,
                'location' => $canonical['location'] ?? $location,
                'equipment' => $canonical['equipment'] ?? $equipment,
                'quantity' => $canonical['quantity'] ?? trim((string) ($item['quantity'] ?? '')),
                'rowKind' => $canonical['rowKind'] ?? (strtolower($rowKind) === 'reading' ? 'reading' : 'status'),
                'status' => $this->normalizeInspectionFrtDailyStatus(
                    $item['status'] ?? '',
                    "payload.frtDailyChecks.{$index}.status"
                ),
                'readingValue' => trim((string) ($item['readingValue'] ?? $item['reading_value'] ?? '')),
                'remarks' => trim((string) ($item['remarks'] ?? $item['remark'] ?? '')),
                'photos' => $this->normalizeInspectionPhotos($item['photos'] ?? []),
                'additionalNotes' => trim((string) ($item['additionalNotes'] ?? $item['additional_notes'] ?? '')),
                'additionalPhotos' => $this->normalizeInspectionPhotos(
                    $item['additionalPhotos'] ?? $item['additional_photos'] ?? []
                ),
            ]);
            unset(
                $normalized['row_number'],
                $normalized['main_location'],
                $normalized['row_kind'],
                $normalized['reading_value'],
                $normalized['additional_notes'],
                $normalized['additional_photos']
            );

            $rows[] = $normalized;
        }

        return $this->orderNormalizedFrtDailyRows($rows);
    }

    private function normalizeInspectionFrtOneOffChecks(mixed $checks): array
    {
        if (! is_array($checks)) {
            throw ValidationException::withMessages([
                'payload.frtOneOffChecks' => ['FRT one-off checks must be an array.'],
            ]);
        }

        $rows = [];
        foreach ($checks as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    "payload.frtOneOffChecks.{$index}" => ['Invalid FRT one-off check item.'],
                ]);
            }

            $rawId = trim((string) ($item['id'] ?? ''));
            $rowNumber = trim((string) ($item['rowNumber'] ?? $item['row_number'] ?? ''));
            $canonical = FrtDailyReference::findOneOffRow($rawId, $rowNumber);
            $equipment = trim((string) ($item['equipment'] ?? $item['title'] ?? $item['name'] ?? ($canonical['equipment'] ?? '')));
            if ($equipment === '') {
                throw ValidationException::withMessages([
                    "payload.frtOneOffChecks.{$index}.equipment" => ['FRT one-off equipment is required.'],
                ]);
            }

            $location = trim((string) ($item['location'] ?? ($canonical['location'] ?? '')));

            $normalized = array_merge($item, [
                'id' => $canonical['id'] ?? ($rawId !== '' ? $rawId : $this->inspectionPayloadSlug('frt-one-off '.$rowNumber.' '.$equipment)),
                'rowNumber' => $canonical['rowNumber'] ?? $rowNumber,
                'mainLocation' => FrtDailyReference::MAIN_LOCATION,
                'location' => $canonical['location'] ?? $location,
                'equipment' => $canonical['equipment'] ?? $equipment,
                'condition' => $this->normalizeInspectionFrtOneOffStatus(
                    $item['condition'] ?? '',
                    "payload.frtOneOffChecks.{$index}.condition"
                ),
                'remarks' => trim((string) ($item['remarks'] ?? $item['remark'] ?? '')),
                'photos' => $this->normalizeInspectionPhotos($item['photos'] ?? []),
                'additionalNotes' => trim((string) ($item['additionalNotes'] ?? $item['additional_notes'] ?? '')),
                'additionalPhotos' => $this->normalizeInspectionPhotos(
                    $item['additionalPhotos'] ?? $item['additional_photos'] ?? []
                ),
            ]);
            unset(
                $normalized['row_number'],
                $normalized['main_location'],
                $normalized['additional_notes'],
                $normalized['additional_photos']
            );

            $rows[] = $normalized;
        }

        return $this->orderNormalizedFrtOneOffRows($rows);
    }

    private function normalizeInspectionHighAngleChecks(mixed $checks): array
    {
        if (! is_array($checks)) {
            throw ValidationException::withMessages([
                'payload.highAngleChecks' => ['High Angle checks must be an array.'],
            ]);
        }

        $rows = [];
        foreach ($checks as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    "payload.highAngleChecks.{$index}" => ['Invalid High Angle check item.'],
                ]);
            }

            $mainLocation = trim((string) ($item['mainLocation'] ?? $item['main_location'] ?? $item['kit'] ?? ''));
            $equipment = trim((string) ($item['equipment'] ?? $item['title'] ?? $item['name'] ?? ''));
            if ($equipment === '') {
                throw ValidationException::withMessages([
                    "payload.highAngleChecks.{$index}.equipment" => ['High Angle equipment is required.'],
                ]);
            }

            $rowNumber = trim((string) ($item['rowNumber'] ?? $item['row_number'] ?? ''));
            $location = trim((string) ($item['location'] ?? ''));
            $subLocation = trim((string) ($item['subLocation'] ?? $item['sub_location'] ?? ''));

            $normalized = array_merge($item, [
                'id' => trim((string) ($item['id'] ?? '')) ?: $this->inspectionPayloadSlug($mainLocation.' '.$rowNumber.' '.$equipment),
                'rowNumber' => $rowNumber,
                'mainLocation' => $mainLocation,
                'location' => $location,
                'subLocation' => $subLocation,
                'equipment' => $equipment,
                'quantity' => trim((string) ($item['quantity'] ?? '')),
                'condition' => $this->normalizeInspectionHighAngleStatus(
                    $item['condition'] ?? '',
                    "payload.highAngleChecks.{$index}.condition"
                ),
                'remarks' => trim((string) ($item['remarks'] ?? $item['remark'] ?? '')),
                'conditionRemarks' => trim((string) (
                    $item['conditionRemarks'] ??
                    $item['condition_remarks'] ??
                    $item['remarks'] ??
                    $item['remark'] ??
                    ''
                )),
                'conditionPhotos' => $this->normalizeInspectionPhotos(
                    $item['conditionPhotos'] ?? $item['condition_photos'] ?? []
                ),
                'additionalNotes' => trim((string) ($item['additionalNotes'] ?? $item['additional_notes'] ?? '')),
                'additionalPhotos' => $this->normalizeInspectionPhotos(
                    $item['additionalPhotos'] ?? $item['additional_photos'] ?? []
                ),
            ]);
            unset(
                $normalized['main_location'],
                $normalized['row_number'],
                $normalized['sub_location'],
                $normalized['condition_remarks'],
                $normalized['condition_photos'],
                $normalized['additional_notes'],
                $normalized['additional_photos']
            );

            $rows[] = $normalized;
        }

        return $rows;
    }

    private function normalizeInspectionScbaChecks(mixed $checks, string $sectionKey, string $fieldPath): array
    {
        if (! is_array($checks)) {
            throw ValidationException::withMessages([
                $fieldPath => ['SCBA checks must be an array.'],
            ]);
        }

        $fieldMap = self::INSPECTION_SCBA_SECTION_FIELDS[$sectionKey] ?? null;
        if (! is_array($fieldMap)) {
            return [];
        }

        $rows = [];
        foreach ($checks as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}" => ['Invalid SCBA check item.'],
                ]);
            }

            $location = trim((string) ($item['location'] ?? $item['mainLocation'] ?? $item['main_location'] ?? ''));
            $brand = trim((string) ($item['brand'] ?? ''));
            $serialNo = trim((string) ($item['serialNo'] ?? $item['serial_no'] ?? $item['serialNumber'] ?? ''));
            if ($serialNo === '') {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.serialNo" => ['SCBA serial number is required.'],
                ]);
            }

            $normalized = array_merge($item, [
                'id' => trim((string) ($item['id'] ?? '')) ?: $this->inspectionPayloadSlug($sectionKey.' '.$location.' '.$brand.' '.$serialNo),
                'sectionKey' => $sectionKey,
                'location' => $location,
                'brand' => $brand,
                'serialNo' => $serialNo,
                'size' => trim((string) ($item['size'] ?? '')),
                'cylinderType' => trim((string) ($item['cylinderType'] ?? $item['cylinder_type'] ?? $item['type'] ?? '')),
                'remarks' => trim((string) ($item['remarks'] ?? $item['remark'] ?? '')),
                'photos' => $this->normalizeInspectionPhotos($item['photos'] ?? []),
            ]);
            unset($normalized['serial_no'], $normalized['serialNumber'], $normalized['cylinder_type'], $normalized['type']);

            foreach ($fieldMap as $field => $kind) {
                $snakeField = Str::snake($field);
                $rawValue = $item[$field] ?? $item[$snakeField] ?? '';
                $normalized[$field] = $kind === 'status'
                    ? $this->normalizeInspectionScbaStatus($rawValue, "{$fieldPath}.{$index}.{$field}")
                    : trim((string) $rawValue);
                if ($snakeField !== $field) {
                    unset($normalized[$snakeField]);
                }
                if ($kind === 'status') {
                    $remarksKey = "{$field}Remarks";
                    $photosKey = "{$field}Photos";
                    $normalized[$remarksKey] = trim((string) (
                        $item[$remarksKey] ??
                        $item[Str::snake($remarksKey)] ??
                        ''
                    ));
                    $normalized[$photosKey] = $this->normalizeInspectionPhotos(
                        $item[$photosKey] ?? $item[Str::snake($photosKey)] ?? []
                    );
                    unset($normalized[Str::snake($remarksKey)], $normalized[Str::snake($photosKey)]);
                }
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    private function validateInspectionScbaRemarks(array $rows, string $fieldPath, string $sectionKey): void
    {
        $fieldMap = self::INSPECTION_SCBA_SECTION_FIELDS[$sectionKey] ?? [];
        $this->validateInspectionScbaRowsAgainstFieldMap($rows, $fieldPath, $fieldMap);
    }

    private function validateInspectionScbaRowsAgainstFieldMap(array $rows, string $fieldPath, array $fieldMap): void
    {
        foreach ($rows as $index => $row) {
            if (($row['removed'] ?? false) === true) {
                continue;
            }
            foreach ($fieldMap as $field => $kind) {
                if ($kind !== 'status') {
                    continue;
                }
                if (strcasecmp(trim((string) ($row[$field] ?? '')), 'Not Good') === 0) {
                    $remarksKey = "{$field}Remarks";
                    $remarks = trim((string) ($row[$remarksKey] ?? $row['remarks'] ?? ''));
                    if ($remarks === '') {
                        throw ValidationException::withMessages([
                            "{$fieldPath}.{$index}.{$remarksKey}" => ['SCBA remarks are required when this status is Not Good.'],
                        ]);
                    }
                }
            }
        }
    }

    private function normalizeInspectionScbaCustomSections(
        mixed $sections,
        string $fieldPath,
        bool $validateCompleteness = true
    ): array {
        if (! is_array($sections)) {
            throw ValidationException::withMessages([
                $fieldPath => ['SCBA custom sections must be an array.'],
            ]);
        }

        $rows = [];
        $usedSectionKeys = ['backPlate' => true, 'cylinder' => true, 'faceMask' => true];
        foreach ($sections as $index => $section) {
            if (! is_array($section)) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}" => ['Invalid SCBA custom section.'],
                ]);
            }

            $title = trim((string) ($section['title'] ?? $section['name'] ?? ''));
            if ($title === '') {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.title" => ['SCBA custom section title is required.'],
                ]);
            }

            $fields = $this->normalizeInspectionScbaCustomFields(
                $section['fields'] ?? $section['checks'] ?? [],
                "{$fieldPath}.{$index}.fields"
            );
            if (count($fields) === 0) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.fields" => ['At least one SCBA custom check is required.'],
                ]);
            }

            $providedKey = trim((string) ($section['key'] ?? ''));
            $baseKey = $this->inspectionPayloadSlug((string) ($providedKey ?: ($section['id'] ?? $title))) ?: 'custom-scba-section';
            $key = $providedKey !== '' ? $providedKey : 'customScba-'.$baseKey;
            $suffix = 2;
            while (isset($usedSectionKeys[$key])) {
                $key = ($providedKey !== '' ? $providedKey : 'customScba-'.$baseKey).'-'.$suffix;
                $suffix++;
            }
            $usedSectionKeys[$key] = true;

            $fieldMap = [];
            foreach ($fields as $field) {
                $fieldMap[$field['key']] = 'status';
            }

            $sectionRows = $this->normalizeInspectionScbaCustomRows(
                $section['rows'] ?? [],
                $key,
                $fieldMap,
                "{$fieldPath}.{$index}.rows"
            );
            if ($validateCompleteness && ($section['removed'] ?? false) !== true) {
                $this->validateInspectionScbaRowsAgainstFieldMap($sectionRows, "{$fieldPath}.{$index}.rows", $fieldMap);
            }

            $rows[] = [
                'id' => trim((string) ($section['id'] ?? '')) ?: $key,
                'catalogSectionId' => $section['catalogSectionId'] ?? $section['catalog_section_id'] ?? null,
                'key' => $key,
                'title' => $title,
                'shortLabel' => trim((string) ($section['shortLabel'] ?? $section['short_label'] ?? $title)),
                'isCustomSection' => true,
                'source' => trim((string) ($section['source'] ?? 'custom')) ?: 'custom',
                'canEdit' => $section['canEdit'] ?? $section['can_edit'] ?? true,
                'canDelete' => $section['canDelete'] ?? $section['can_delete'] ?? true,
                'removed' => ($section['removed'] ?? false) === true,
                'removedAt' => trim((string) ($section['removedAt'] ?? $section['removed_at'] ?? '')),
                'removedBy' => trim((string) ($section['removedBy'] ?? $section['removed_by'] ?? '')),
                'removedReason' => trim((string) ($section['removedReason'] ?? $section['removed_reason'] ?? '')),
                'fields' => $fields,
                'rows' => $sectionRows,
            ];
        }

        return $rows;
    }

    private function normalizeInspectionScbaCustomFields(mixed $fields, string $fieldPath): array
    {
        if (! is_array($fields)) {
            throw ValidationException::withMessages([
                $fieldPath => ['SCBA custom section fields must be an array.'],
            ]);
        }

        $rows = [];
        $usedKeys = [];
        foreach ($fields as $index => $field) {
            $label = is_array($field)
                ? trim((string) ($field['label'] ?? $field['name'] ?? ''))
                : trim((string) $field);
            if ($label === '') {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.label" => ['SCBA custom check label is required.'],
                ]);
            }
            $keySource = is_array($field) ? ($field['key'] ?? $label) : $label;
            $providedKey = trim((string) (is_array($field) ? ($field['key'] ?? '') : ''));
            $key = $providedKey !== '' && preg_match('/^[a-z][A-Za-z0-9]*$/', $providedKey)
                ? $providedKey
                : Str::camel($this->inspectionPayloadSlug((string) $keySource) ?: 'check');
            $suffix = 2;
            while (isset($usedKeys[$key])) {
                $key = Str::camel(($this->inspectionPayloadSlug((string) $keySource) ?: 'check').'-'.$suffix);
                $suffix++;
            }
            $usedKeys[$key] = true;
            $rows[] = [
                'key' => $key,
                'label' => $label,
                'kind' => 'status',
            ];
        }

        return $rows;
    }

    private function normalizeInspectionScbaCustomRows(mixed $checks, string $sectionKey, array $fieldMap, string $fieldPath): array
    {
        if (! is_array($checks)) {
            throw ValidationException::withMessages([
                $fieldPath => ['SCBA custom section rows must be an array.'],
            ]);
        }

        $rows = [];
        foreach ($checks as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}" => ['Invalid SCBA custom section row.'],
                ]);
            }
            $location = trim((string) ($item['location'] ?? $item['mainLocation'] ?? $item['main_location'] ?? ''));
            $brand = trim((string) ($item['brand'] ?? ''));
            $serialNo = trim((string) ($item['serialNo'] ?? $item['serial_no'] ?? $item['serialNumber'] ?? ''));
            if ($brand === '' && $serialNo === '') {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.serialNo" => ['SCBA custom item brand or serial number is required.'],
                ]);
            }

            $normalized = array_merge($item, [
                'id' => trim((string) ($item['id'] ?? '')) ?: $this->inspectionPayloadSlug($sectionKey.' '.$location.' '.$brand.' '.$serialNo),
                'catalogItemId' => $item['catalogItemId'] ?? $item['catalog_item_id'] ?? null,
                'catalogSectionId' => $item['catalogSectionId'] ?? $item['catalog_section_id'] ?? null,
                'sectionKey' => $sectionKey,
                'location' => $location,
                'brand' => $brand,
                'serialNo' => $serialNo,
                'size' => trim((string) ($item['size'] ?? '')),
                'cylinderType' => trim((string) ($item['cylinderType'] ?? $item['cylinder_type'] ?? $item['type'] ?? '')),
                'equipmentDescription' => trim((string) ($item['equipmentDescription'] ?? $item['equipment_description'] ?? $item['description'] ?? '')),
                'equipmentSource' => 'custom',
                'isCustomEquipment' => true,
                'removed' => ($item['removed'] ?? false) === true,
                'removedAt' => trim((string) ($item['removedAt'] ?? $item['removed_at'] ?? '')),
                'removedBy' => trim((string) ($item['removedBy'] ?? $item['removed_by'] ?? '')),
                'removedReason' => trim((string) ($item['removedReason'] ?? $item['removed_reason'] ?? '')),
                'remarks' => trim((string) ($item['remarks'] ?? $item['remark'] ?? '')),
                'photos' => $this->normalizeInspectionPhotos($item['photos'] ?? []),
            ]);
            unset($normalized['serial_no'], $normalized['serialNumber'], $normalized['cylinder_type'], $normalized['type']);

            foreach ($fieldMap as $field => $kind) {
                $snakeField = Str::snake($field);
                $normalized[$field] = $this->normalizeInspectionScbaStatus(
                    $item[$field] ?? $item[$snakeField] ?? '',
                    "{$fieldPath}.{$index}.{$field}"
                );
                unset($normalized[$snakeField]);
                $remarksKey = "{$field}Remarks";
                $photosKey = "{$field}Photos";
                $normalized[$remarksKey] = trim((string) ($item[$remarksKey] ?? $item[Str::snake($remarksKey)] ?? ''));
                $normalized[$photosKey] = $this->normalizeInspectionPhotos(
                    $item[$photosKey] ?? $item[Str::snake($photosKey)] ?? []
                );
                unset($normalized[Str::snake($remarksKey)], $normalized[Str::snake($photosKey)]);
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    private function validateInspectionHighAngleRemarks(array $rows, string $fieldPath): void
    {
        foreach ($rows as $index => $row) {
            if (
                strcasecmp(trim((string) ($row['condition'] ?? '')), 'Not Good') === 0
                && trim((string) ($row['conditionRemarks'] ?? $row['remarks'] ?? '')) === ''
            ) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.conditionRemarks" => ['High Angle remarks are required when condition is Not Good.'],
                ]);
            }
        }
    }

    private function validateInspectionErAuxRows(array $rows, string $fieldPath): void
    {
        foreach ($rows as $index => $row) {
            if (trim((string) ($row['quantity'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.quantity" => ['ER Aux quantity is required before submission.'],
                ]);
            }

            if (trim((string) ($row['condition'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.condition" => ['ER Aux condition is required before submission.'],
                ]);
            }

            if (strcasecmp(trim((string) ($row['condition'] ?? '')), 'Defect') !== 0) {
                continue;
            }

            if (trim((string) ($row['defectRemarks'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.defectRemarks" => ['ER Aux defect remarks are required when condition is Defect.'],
                ]);
            }

        }
    }

    private function validateInspectionHydraulicRows(array $rows, string $fieldPath): void
    {
        foreach ($rows as $index => $row) {
            foreach (self::INSPECTION_HYDRAULIC_CHECK_FIELDS as $field) {
                if (trim((string) ($row[$field] ?? '')) === '') {
                    throw ValidationException::withMessages([
                        "{$fieldPath}.{$index}.{$field}" => ['Hydraulic check status is required before submission.'],
                    ]);
                }

                $status = trim((string) ($row[$field] ?? ''));
                if (! in_array(strtolower($status), ['defect', 'n/a'], true)) {
                    continue;
                }

                $meta = self::INSPECTION_HYDRAULIC_CHECK_EVIDENCE_FIELDS[$field];
                $remarksKey = $meta['remarks'];

                if (trim((string) ($row[$remarksKey] ?? '')) === '') {
                    throw ValidationException::withMessages([
                        "{$fieldPath}.{$index}.{$remarksKey}" => ['Hydraulic remarks are required for Defect or N/A statuses.'],
                    ]);
                }
            }
        }
    }

    private function validateInspectionFireExtinguisherSessionMeta(array $payload): void
    {
        $inspectedBy = trim((string) ($payload['fireExtinguisherInspectedBy'] ?? $payload['fire_extinguisher_inspected_by'] ?? ''));
        $inspectionDate = trim((string) ($payload['fireExtinguisherInspectionDate'] ?? $payload['fire_extinguisher_inspection_date'] ?? ''));

        if ($inspectedBy === '') {
            throw ValidationException::withMessages([
                'payload.fireExtinguisherInspectedBy' => ['Fire extinguisher inspected by is required.'],
            ]);
        }

        if ($inspectionDate === '') {
            throw ValidationException::withMessages([
                'payload.fireExtinguisherInspectionDate' => ['Fire extinguisher inspection date is required.'],
            ]);
        }
    }

    private function validateInspectionFireExtinguisherRows(array $rows, string $fieldPath): void
    {
        if ($rows === []) {
            throw ValidationException::withMessages([
                $fieldPath => ['At least one fire extinguisher row is required.'],
            ]);
        }

        foreach ($rows as $index => $row) {
            foreach (self::INSPECTION_FIRE_EXTINGUISHER_STATUS_VALUES as $field => $allowed) {
                $status = trim((string) ($row[$field] ?? ''));
                if ($status === '') {
                    throw ValidationException::withMessages([
                        "{$fieldPath}.{$index}.{$field}" => ['Fire extinguisher check status is required.'],
                    ]);
                }

                $meta = self::INSPECTION_FIRE_EXTINGUISHER_CHECK_EVIDENCE_FIELDS[$field] ?? null;
                if (! $meta || ! $this->isFireExtinguisherDefectStatus($status)) {
                    continue;
                }

                if (trim((string) ($row[$meta['remarks']] ?? '')) === '') {
                    throw ValidationException::withMessages([
                        "{$fieldPath}.{$index}.{$meta['remarks']}" => ['Fire extinguisher remarks are required for defect or failed statuses.'],
                    ]);
                }

            }
        }
    }

    private function isFireExtinguisherDefectStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['not good', 'no', 'not operational'], true);
    }

    private function isHseInspectionType(string $inspectionType): bool
    {
        return Str::of($inspectionType)->squish()->lower()->toString() === 'health safety environment inspection';
    }

    private function isGeneralInspectionType(string $inspectionType): bool
    {
        return Str::of($inspectionType)->squish()->lower()->toString() === 'general inspection';
    }

    private function isHighAngleInspectionType(string $inspectionType): bool
    {
        return Str::of($inspectionType)->squish()->lower()->toString() === 'high angle rescue equipment inspection';
    }

    private function validateInspectionHighAngleSessionMeta(array $payload): void
    {
        $inspectedBy = trim((string) ($payload['highAngleInspectedBy'] ?? $payload['high_angle_inspected_by'] ?? ''));
        $inspectionDate = trim((string) ($payload['highAngleInspectionDate'] ?? $payload['high_angle_inspection_date'] ?? ''));

        if ($inspectedBy === '') {
            throw ValidationException::withMessages([
                'payload.highAngleInspectedBy' => ['High Angle inspected by is required.'],
            ]);
        }

        if ($inspectionDate === '') {
            throw ValidationException::withMessages([
                'payload.highAngleInspectionDate' => ['High Angle inspection date is required.'],
            ]);
        }
    }

    private function validateInspectionFrtDailyRows(array $rows, string $fieldPath): void
    {
        foreach ($rows as $index => $row) {
            $rowKind = trim((string) ($row['rowKind'] ?? $row['row_kind'] ?? 'status')) ?: 'status';
            if ($rowKind === 'reading') {
                if (trim((string) ($row['readingValue'] ?? $row['reading_value'] ?? '')) === '') {
                    throw ValidationException::withMessages([
                        "{$fieldPath}.{$index}.readingValue" => ['FRT reading value is required for reading rows.'],
                    ]);
                }

                continue;
            }

            if (trim((string) ($row['status'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.status" => ['FRT daily status is required.'],
                ]);
            }
            if (
                strcasecmp(trim((string) ($row['status'] ?? '')), 'Issue') === 0
                && trim((string) ($row['remarks'] ?? '')) === ''
            ) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.remarks" => ['FRT daily remarks are required when status is Issue.'],
                ]);
            }
        }
    }

    private function validateInspectionFrtOneOffRows(array $rows, string $fieldPath): void
    {
        foreach ($rows as $index => $row) {
            if (trim((string) ($row['condition'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.condition" => ['FRT one-off condition is required.'],
                ]);
            }
            if (
                strcasecmp(trim((string) ($row['condition'] ?? '')), 'Not Good') === 0
                && trim((string) ($row['remarks'] ?? '')) === ''
            ) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.remarks" => ['FRT one-off remarks are required when condition is Not Good.'],
                ]);
            }
        }
    }

    private function validateInspectionFrtSessionMeta(array $payload): void
    {
        $inspectedBy = trim((string) ($payload['frtInspectedBy'] ?? $payload['frt_inspected_by'] ?? ''));
        $inspectionDate = trim((string) ($payload['frtInspectionDate'] ?? $payload['frt_inspection_date'] ?? ''));
        $rawTruckReference = is_array($payload['frtTruckReference'] ?? null)
            ? $payload['frtTruckReference']
            : (is_array($payload['frt_truck_reference'] ?? null) ? $payload['frt_truck_reference'] : []);
        $truckPlate = trim((string) (
            $payload['frtTruckPlateNo'] ??
            $payload['frt_truck_plate_no'] ??
            $rawTruckReference['plateNo'] ??
            $rawTruckReference['plate_no'] ??
            ''
        ));
        $legacyLocation = trim((string) ($payload['mainLocation'] ?? $payload['main_location'] ?? $payload['selectedLocation'] ?? $payload['location'] ?? ''));
        if ($truckPlate === '' && Str::of($legacyLocation)->squish()->lower()->toString() === Str::of(FrtDailyReference::MAIN_LOCATION)->squish()->lower()->toString()) {
            $truckPlate = trim((string) ($rawTruckReference['plateNo'] ?? $rawTruckReference['plate_no'] ?? FrtDailyReference::TRUCK_REFERENCE['plateNo']));
        }

        if ($inspectedBy === '') {
            throw ValidationException::withMessages([
                'payload.frtInspectedBy' => ['FRT inspected by is required.'],
            ]);
        }

        if ($inspectionDate === '') {
            throw ValidationException::withMessages([
                'payload.frtInspectionDate' => ['FRT inspection date is required.'],
            ]);
        }

        if ($truckPlate === '') {
            throw ValidationException::withMessages([
                'payload.frtTruckPlateNo' => ['Fire truck plate number is required.'],
            ]);
        }
    }

    private function validateInspectionFrtSubmittedRoster(array $dailyRows, array $oneOffRows): void
    {
        if (count($dailyRows) === 0 && count($oneOffRows) === 0) {
            throw ValidationException::withMessages([
                'payload.frtDailyChecks' => ['Submit at least one completed fire truck checklist row.'],
            ]);
        }

        $this->validateInspectionFrtCanonicalRows(
            rows: $dailyRows,
            fieldPath: 'payload.frtDailyChecks',
            expectedRows: FrtDailyReference::dailyRowMap(),
        );
        $this->validateInspectionFrtCanonicalRows(
            rows: $oneOffRows,
            fieldPath: 'payload.frtOneOffChecks',
            expectedRows: FrtDailyReference::oneOffRowMap(),
        );
    }

    private function validateInspectionFrtRawCanonicalRows(
        mixed $rows,
        string $fieldPath,
        array $expectedRows,
    ): void {
        if (! is_array($rows)) {
            return;
        }

        $expectedRowsByNumber = [];
        foreach ($expectedRows as $expectedRow) {
            $expectedRowsByNumber[trim((string) ($expectedRow['rowNumber'] ?? ''))] = $expectedRow;
        }

        $seen = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $rawId = trim((string) ($row['id'] ?? ''));
            $rowNumber = trim((string) ($row['rowNumber'] ?? $row['row_number'] ?? ''));
            if ($rawId !== '' && ! array_key_exists($rawId, $expectedRows)) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.id" => ['Unsupported FRT checklist row.'],
                ]);
            }

            $expected = $rawId !== ''
                ? ($expectedRows[$rawId] ?? null)
                : ($expectedRowsByNumber[$rowNumber] ?? null);
            if (! is_array($expected)) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.id" => ['Unsupported FRT checklist row.'],
                ]);
            }

            $canonicalId = (string) $expected['id'];
            if (array_key_exists($canonicalId, $seen)) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.id" => ['Duplicate FRT checklist row.'],
                ]);
            }

            foreach ($expected as $key => $expectedValue) {
                if ($key === 'id') {
                    continue;
                }

                $snakeKey = Str::snake($key);
                if (! array_key_exists($key, $row) && ! array_key_exists($snakeKey, $row)) {
                    continue;
                }

                $actualValue = trim((string) ($row[$key] ?? $row[$snakeKey] ?? ''));
                if ($actualValue !== trim((string) $expectedValue)) {
                    throw ValidationException::withMessages([
                        "{$fieldPath}.{$index}.{$key}" => ['FRT checklist row metadata must match the seeded workbook roster.'],
                    ]);
                }
            }

            $seen[$canonicalId] = true;
        }
    }

    private function validateInspectionFrtCanonicalRows(
        array $rows,
        string $fieldPath,
        array $expectedRows,
    ): void {
        $seen = [];

        foreach ($rows as $index => $row) {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '' || ! array_key_exists($id, $expectedRows)) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.id" => ['Unsupported FRT checklist row.'],
                ]);
            }

            if (array_key_exists($id, $seen)) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.id" => ['Duplicate FRT checklist row.'],
                ]);
            }

            $expected = $expectedRows[$id];
            foreach ($expected as $key => $expectedValue) {
                $actualValue = trim((string) ($row[$key] ?? ''));
                if ($actualValue !== trim((string) $expectedValue)) {
                    throw ValidationException::withMessages([
                        "{$fieldPath}.{$index}.{$key}" => ['FRT checklist row metadata must match the seeded workbook roster.'],
                    ]);
                }
            }

            $seen[$id] = true;
        }
    }

    private function normalizeInspectionErAuxCondition(mixed $value, string $fieldPath): string
    {
        $condition = trim((string) $value);
        if ($condition === '') {
            return '';
        }

        foreach (self::INSPECTION_ER_AUX_CONDITION_VALUES as $allowed) {
            if (strcasecmp($condition, $allowed) === 0) {
                return $allowed;
            }
        }

        throw ValidationException::withMessages([
            $fieldPath => ['ER Aux condition must be OK, Defect, Missing, or N/A.'],
        ]);
    }

    private function normalizeInspectionHydraulicStatus(mixed $value, string $fieldPath): string
    {
        $status = trim((string) $value);
        if ($status === '') {
            return '';
        }

        foreach (self::INSPECTION_HYDRAULIC_STATUS_VALUES as $allowed) {
            if (strcasecmp($status, $allowed) === 0) {
                return $allowed;
            }
        }

        throw ValidationException::withMessages([
            $fieldPath => ['Hydraulic check status must be OK, Defect, or N/A.'],
        ]);
    }

    private function normalizeInspectionFireExtinguisherStatus(mixed $value, array $allowed, string $fieldPath): string
    {
        $status = trim((string) $value);
        if ($status === '') {
            return '';
        }

        foreach ($allowed as $candidate) {
            if (strcasecmp($status, $candidate) === 0) {
                return $candidate;
            }
        }

        throw ValidationException::withMessages([
            $fieldPath => ['Fire extinguisher status value is not valid for this check.'],
        ]);
    }

    private function normalizeInspectionFrtDailyStatus(mixed $value, string $fieldPath): string
    {
        $status = trim((string) $value);
        if ($status === '') {
            return '';
        }

        foreach (self::INSPECTION_FRT_DAILY_STATUS_VALUES as $allowed) {
            if (strcasecmp($status, $allowed) === 0) {
                return $allowed;
            }
        }

        throw ValidationException::withMessages([
            $fieldPath => ['FRT daily status must be Checked or Issue.'],
        ]);
    }

    private function normalizeInspectionFrtOneOffStatus(mixed $value, string $fieldPath): string
    {
        $status = trim((string) $value);
        if ($status === '') {
            return '';
        }

        foreach (self::INSPECTION_FRT_ONE_OFF_STATUS_VALUES as $allowed) {
            if (strcasecmp($status, $allowed) === 0) {
                return $allowed;
            }
        }

        throw ValidationException::withMessages([
            $fieldPath => ['FRT one-off condition must be Good or Not Good.'],
        ]);
    }

    private function normalizeInspectionHighAngleStatus(mixed $value, string $fieldPath): string
    {
        $status = trim((string) $value);
        if ($status === '') {
            return '';
        }

        foreach (self::INSPECTION_HIGH_ANGLE_STATUS_VALUES as $allowed) {
            if (strcasecmp($status, $allowed) === 0) {
                return $allowed;
            }
        }

        throw ValidationException::withMessages([
            $fieldPath => ['High Angle condition must be Good or Not Good.'],
        ]);
    }

    private function normalizeInspectionScbaStatus(mixed $value, string $fieldPath): string
    {
        $status = trim((string) $value);
        if ($status === '') {
            return '';
        }

        foreach (self::INSPECTION_SCBA_STATUS_VALUES as $allowed) {
            if (strcasecmp($status, $allowed) === 0) {
                return $allowed;
            }
        }

        throw ValidationException::withMessages([
            $fieldPath => ['SCBA check status must be Good or Not Good.'],
        ]);
    }

    private function isFrtDailyInspectionType(string $inspectionType): bool
    {
        return in_array(Str::of($inspectionType)->squish()->lower()->toString(), [
            Str::of(FrtDailyReference::INSPECTION_TYPE)->squish()->lower()->toString(),
            Str::of(FrtDailyReference::LEGACY_INSPECTION_TYPE)->squish()->lower()->toString(),
        ], true);
    }

    private function orderNormalizedFrtDailyRows(array $rows): array
    {
        return $this->orderNormalizedFrtRows($rows, FrtDailyReference::dailyRows());
    }

    private function orderNormalizedFrtOneOffRows(array $rows): array
    {
        return $this->orderNormalizedFrtRows($rows, FrtDailyReference::oneOffRows());
    }

    private function orderNormalizedFrtRows(array $rows, array $expectedRows): array
    {
        $positions = [];
        foreach ($expectedRows as $position => $expected) {
            $positions[(string) $expected['id']] = $position;
        }

        $indexedRows = [];
        foreach ($rows as $originalPosition => $row) {
            $indexedRows[] = [
                'originalPosition' => $originalPosition,
                'canonicalPosition' => $positions[trim((string) ($row['id'] ?? ''))] ?? PHP_INT_MAX,
                'row' => $row,
            ];
        }

        usort($indexedRows, static function (array $left, array $right): int {
            $canonicalOrder = $left['canonicalPosition'] <=> $right['canonicalPosition'];

            return $canonicalOrder !== 0
                ? $canonicalOrder
                : $left['originalPosition'] <=> $right['originalPosition'];
        });

        return array_map(static fn (array $entry): array => $entry['row'], $indexedRows);
    }

    private function inspectionPayloadSlug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', trim($value)));

        return trim($slug, '-') ?: 'hydraulic-check';
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function normalizeInspectionPhotos(mixed $photos): array
    {
        if (! is_array($photos)) {
            return [];
        }

        $rows = [];
        foreach ($photos as $photo) {
            if (! is_array($photo)) {
                continue;
            }
            $url = trim((string) ($photo['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $normalized = [
                'id' => trim((string) ($photo['id'] ?? '')),
                'fileName' => trim((string) ($photo['fileName'] ?? $photo['file_name'] ?? '')),
                'description' => (string) ($photo['description'] ?? ''),
                'url' => $url,
            ];

            $mediaId = trim((string) ($photo['mediaId'] ?? $photo['media_id'] ?? ''));
            if ($mediaId !== '') {
                $normalized['mediaId'] = $mediaId;
                $normalized['thumbnailUrl'] = trim((string) ($photo['thumbnailUrl'] ?? $photo['thumbnail_url'] ?? ''));
                $normalized['mimeType'] = trim((string) ($photo['mimeType'] ?? $photo['mime_type'] ?? ''));
                $normalized['sizeBytes'] = max(0, (int) ($photo['sizeBytes'] ?? $photo['size_bytes'] ?? 0));
                $normalized['width'] = max(0, (int) ($photo['width'] ?? 0));
                $normalized['height'] = max(0, (int) ($photo['height'] ?? 0));
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    public function validateForSubmit(array $payload): void
    {
        $this->validateInspectionReportRemarks($payload);

        if (array_key_exists('checklist', $payload)) {
            $this->normalizeInspectionChecklist($payload['checklist']);
        }

        if (array_key_exists('erAuxChecks', $payload) || array_key_exists('er_aux_checks', $payload)) {
            $rows = $this->normalizeInspectionErAuxChecks($payload['erAuxChecks'] ?? $payload['er_aux_checks']);
            $this->validateInspectionErAuxRows($rows, 'payload.erAuxChecks');
        }

        if ($this->hasInspectionRows($payload, 'hydraulicChecks', 'hydraulic_checks')) {
            $rows = $this->normalizeInspectionHydraulicChecks(
                $payload['hydraulicChecks'] ?? $payload['hydraulic_checks']
            );
            $this->validateInspectionHydraulicRows($rows, 'payload.hydraulicChecks');
        }

        if (
            $this->isFrtDailyInspectionType((string) ($payload['incidentType'] ?? $payload['inspectionType'] ?? ''))
            || $this->hasInspectionRows($payload, 'frtDailyChecks', 'frt_daily_checks')
            || $this->hasInspectionRows($payload, 'frtOneOffChecks', 'frt_one_off_checks')
        ) {
            $this->validateInspectionFrtSessionMeta($payload);
            $rawDailyRows = $payload['frtDailyChecks'] ?? $payload['frt_daily_checks'] ?? [];
            $rawOneOffRows = $payload['frtOneOffChecks'] ?? $payload['frt_one_off_checks'] ?? [];
            $dailyRows = $this->normalizeInspectionFrtDailyChecks(
                $rawDailyRows
            );
            $oneOffRows = $this->normalizeInspectionFrtOneOffChecks(
                $rawOneOffRows
            );
            $this->validateInspectionFrtRawCanonicalRows(
                $rawDailyRows,
                'payload.frtDailyChecks',
                FrtDailyReference::dailyRowMap(),
            );
            $this->validateInspectionFrtRawCanonicalRows(
                $rawOneOffRows,
                'payload.frtOneOffChecks',
                FrtDailyReference::oneOffRowMap(),
            );
            $this->validateInspectionFrtSubmittedRoster($dailyRows, $oneOffRows);
            $this->validateInspectionFrtDailyRows($dailyRows, 'payload.frtDailyChecks');
            $this->validateInspectionFrtOneOffRows($oneOffRows, 'payload.frtOneOffChecks');
        }

        if (
            $this->isHighAngleInspectionType((string) ($payload['incidentType'] ?? $payload['inspectionType'] ?? ''))
            || $this->hasInspectionRows($payload, 'highAngleChecks', 'high_angle_checks')
        ) {
            $rows = $this->normalizeInspectionHighAngleChecks(
                $payload['highAngleChecks'] ?? $payload['high_angle_checks'] ?? []
            );
            if ($rows === []) {
                throw ValidationException::withMessages([
                    'payload.highAngleChecks' => ['Submit at least one completed High Angle equipment row.'],
                ]);
            }
            $this->validateInspectionHighAngleSessionMeta($payload);
            $this->validateInspectionHighAngleRemarks($rows, 'payload.highAngleChecks');
        }

        if ($this->hasInspectionRows($payload, 'fireExtinguisherChecks', 'fire_extinguisher_checks')) {
            $rows = $this->normalizeInspectionFireExtinguisherChecks(
                $payload['fireExtinguisherChecks'] ?? $payload['fire_extinguisher_checks']
            );
            $this->validateInspectionFireExtinguisherSessionMeta($payload);
            $this->validateInspectionFireExtinguisherRows($rows, 'payload.fireExtinguisherChecks');
        }

        if ($this->hasInspectionRows($payload, 'scbaBackPlateChecks', 'scba_back_plate_checks')) {
            $rows = $this->normalizeInspectionScbaChecks(
                $payload['scbaBackPlateChecks'] ?? $payload['scba_back_plate_checks'],
                'backPlate',
                'payload.scbaBackPlateChecks'
            );
            $this->validateInspectionScbaRemarks($rows, 'payload.scbaBackPlateChecks', 'backPlate');
        }

        if ($this->hasInspectionRows($payload, 'scbaCylinderChecks', 'scba_cylinder_checks')) {
            $rows = $this->normalizeInspectionScbaChecks(
                $payload['scbaCylinderChecks'] ?? $payload['scba_cylinder_checks'],
                'cylinder',
                'payload.scbaCylinderChecks'
            );
            $this->validateInspectionScbaRemarks($rows, 'payload.scbaCylinderChecks', 'cylinder');
        }

        if ($this->hasInspectionRows($payload, 'scbaFaceMaskChecks', 'scba_face_mask_checks')) {
            $rows = $this->normalizeInspectionScbaChecks(
                $payload['scbaFaceMaskChecks'] ?? $payload['scba_face_mask_checks'],
                'faceMask',
                'payload.scbaFaceMaskChecks'
            );
            $this->validateInspectionScbaRemarks($rows, 'payload.scbaFaceMaskChecks', 'faceMask');
        }

        if (array_key_exists('scbaCustomSections', $payload) || array_key_exists('scba_custom_sections', $payload)) {
            $this->normalizeInspectionScbaCustomSections(
                $payload['scbaCustomSections'] ?? $payload['scba_custom_sections'],
                'payload.scbaCustomSections'
            );
        }

        if ($this->isHseInspectionType((string) ($payload['incidentType'] ?? $payload['inspectionType'] ?? ''))) {
            $this->hsePayloadService->validateForSubmit($payload);
        }

        $payloadJson = json_encode($payload);
        if ($payloadJson !== false && strlen($payloadJson) > self::INSPECTION_MAX_TOTAL_PHOTO_BYTES * 2) {
            throw ValidationException::withMessages([
                'payload' => ['Inspection payload is too large. Please reduce photo count/size.'],
            ]);
        }

        $photoRows = $this->inspectionPayloadPhotoRows($payload);
        if (count($photoRows) > self::INSPECTION_MAX_PHOTO_COUNT) {
            throw ValidationException::withMessages([
                'payload.photos' => ['Maximum 10 photos are allowed for inspection reports.'],
            ]);
        }

        $totalPhotoBytes = 0;
        foreach ($photoRows as $row) {
            $photo = $row['photo'];
            $fieldPath = $row['path'];
            if (! is_array($photo)) {
                throw ValidationException::withMessages([
                    $fieldPath => ['Invalid photo payload.'],
                ]);
            }

            $managedPhotoBytes = $this->managedInspectionPhotoBytes($photo, $fieldPath);
            if ($managedPhotoBytes !== null) {
                $totalPhotoBytes += $managedPhotoBytes;

                continue;
            }

            $url = trim((string) ($photo['url'] ?? ''));
            if ($url === '') {
                throw ValidationException::withMessages([
                    "{$fieldPath}.url" => ['Photo URL is required.'],
                ]);
            }

            if (! preg_match('/^data:image\/([a-z0-9.+-]+);base64,([a-z0-9+\/=\r\n]+)$/i', $url, $match)) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.url" => [
                        'Photo must be an inline base64 data URL image.',
                    ],
                ]);
            }

            $imageMime = strtolower(trim((string) ($match[1] ?? '')));
            if (! in_array($imageMime, self::INSPECTION_ALLOWED_IMAGE_MIMES, true)) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.url" => [
                        'Only jpeg, png, and webp images are allowed.',
                    ],
                ]);
            }

            $base64Data = preg_replace('/\s+/u', '', (string) ($match[2] ?? ''));
            $decoded = base64_decode($base64Data, true);
            if ($decoded === false) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.url" => ['Invalid base64 image data.'],
                ]);
            }

            $photoBytes = strlen($decoded);
            if ($photoBytes > self::INSPECTION_MAX_PHOTO_BYTES) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.url" => ['Each photo must be 1.5 MB or smaller.'],
                ]);
            }
            $totalPhotoBytes += $photoBytes;
        }

        if ($totalPhotoBytes > self::INSPECTION_MAX_TOTAL_PHOTO_BYTES) {
            throw ValidationException::withMessages([
                'payload.photos' => ['Total photo size must be 12 MB or smaller.'],
            ]);
        }
    }

    public function validateForDraft(array $payload): void
    {
        $this->validateInspectionReportRemarks($payload);

        if (array_key_exists('checklist', $payload)) {
            $this->normalizeInspectionChecklist($payload['checklist']);
        }

        if (array_key_exists('erAuxChecks', $payload) || array_key_exists('er_aux_checks', $payload)) {
            $this->normalizeInspectionErAuxChecks($payload['erAuxChecks'] ?? $payload['er_aux_checks']);
        }

        if (array_key_exists('hydraulicChecks', $payload) || array_key_exists('hydraulic_checks', $payload)) {
            $this->normalizeInspectionHydraulicChecks(
                $payload['hydraulicChecks'] ?? $payload['hydraulic_checks']
            );
        }

        if (
            $this->isFrtDailyInspectionType((string) ($payload['incidentType'] ?? $payload['inspectionType'] ?? ''))
            || $this->hasInspectionRows($payload, 'frtDailyChecks', 'frt_daily_checks')
            || $this->hasInspectionRows($payload, 'frtOneOffChecks', 'frt_one_off_checks')
        ) {
            $this->normalizeInspectionFrtDailyChecks(
                $payload['frtDailyChecks'] ?? $payload['frt_daily_checks'] ?? []
            );
            $this->normalizeInspectionFrtOneOffChecks(
                $payload['frtOneOffChecks'] ?? $payload['frt_one_off_checks'] ?? []
            );
        }

        if ($this->hasInspectionRows($payload, 'highAngleChecks', 'high_angle_checks')) {
            $this->normalizeInspectionHighAngleChecks(
                $payload['highAngleChecks'] ?? $payload['high_angle_checks']
            );
        }

        if ($this->hasInspectionRows($payload, 'fireExtinguisherChecks', 'fire_extinguisher_checks')) {
            $this->normalizeInspectionFireExtinguisherChecks(
                $payload['fireExtinguisherChecks'] ?? $payload['fire_extinguisher_checks']
            );
        }

        if ($this->hasInspectionRows($payload, 'scbaBackPlateChecks', 'scba_back_plate_checks')) {
            $this->normalizeInspectionScbaChecks(
                $payload['scbaBackPlateChecks'] ?? $payload['scba_back_plate_checks'],
                'backPlate',
                'payload.scbaBackPlateChecks'
            );
        }

        if ($this->hasInspectionRows($payload, 'scbaCylinderChecks', 'scba_cylinder_checks')) {
            $this->normalizeInspectionScbaChecks(
                $payload['scbaCylinderChecks'] ?? $payload['scba_cylinder_checks'],
                'cylinder',
                'payload.scbaCylinderChecks'
            );
        }

        if ($this->hasInspectionRows($payload, 'scbaFaceMaskChecks', 'scba_face_mask_checks')) {
            $this->normalizeInspectionScbaChecks(
                $payload['scbaFaceMaskChecks'] ?? $payload['scba_face_mask_checks'],
                'faceMask',
                'payload.scbaFaceMaskChecks'
            );
        }

        if (array_key_exists('scbaCustomSections', $payload) || array_key_exists('scba_custom_sections', $payload)) {
            $this->normalizeInspectionScbaCustomSections(
                $payload['scbaCustomSections'] ?? $payload['scba_custom_sections'],
                'payload.scbaCustomSections',
                false
            );
        }

        if ($this->isHseInspectionType((string) ($payload['incidentType'] ?? $payload['inspectionType'] ?? ''))) {
            $this->hsePayloadService->validateForDraft($payload);
        }

        $payloadJson = json_encode($payload);
        if ($payloadJson !== false && strlen($payloadJson) > self::INSPECTION_MAX_TOTAL_PHOTO_BYTES * 2) {
            throw ValidationException::withMessages([
                'payload' => ['Inspection payload is too large. Please reduce photo count/size.'],
            ]);
        }

        $photoRows = $this->inspectionPayloadPhotoRows($payload);
        if (count($photoRows) > self::INSPECTION_MAX_PHOTO_COUNT) {
            throw ValidationException::withMessages([
                'payload.photos' => ['Maximum 10 photos are allowed for inspection drafts.'],
            ]);
        }

        $totalPhotoBytes = 0;
        foreach ($photoRows as $row) {
            $photo = $row['photo'];
            $fieldPath = $row['path'];
            if (! is_array($photo)) {
                throw ValidationException::withMessages([
                    $fieldPath => ['Invalid photo payload.'],
                ]);
            }

            $managedPhotoBytes = $this->managedInspectionPhotoBytes($photo, $fieldPath);
            if ($managedPhotoBytes !== null) {
                $totalPhotoBytes += $managedPhotoBytes;

                continue;
            }

            $url = trim((string) ($photo['url'] ?? ''));
            if ($url === '') {
                throw ValidationException::withMessages([
                    "{$fieldPath}.url" => ['Photo URL is required.'],
                ]);
            }

            if (! preg_match('/^data:image\/([a-z0-9.+-]+);base64,([a-z0-9+\/=\r\n]+)$/i', $url, $match)) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.url" => [
                        'Photo must be an inline base64 data URL image.',
                    ],
                ]);
            }

            $imageMime = strtolower(trim((string) ($match[1] ?? '')));
            if (! in_array($imageMime, self::INSPECTION_ALLOWED_IMAGE_MIMES, true)) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.url" => [
                        'Only jpeg, png, and webp images are allowed.',
                    ],
                ]);
            }

            $base64Data = preg_replace('/\s+/u', '', (string) ($match[2] ?? ''));
            $decoded = base64_decode($base64Data, true);
            if ($decoded === false) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.url" => ['Invalid base64 image data.'],
                ]);
            }

            $photoBytes = strlen($decoded);
            if ($photoBytes > self::INSPECTION_MAX_PHOTO_BYTES) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.url" => ['Each photo must be 1.5 MB or smaller.'],
                ]);
            }
            $totalPhotoBytes += $photoBytes;
        }

        if ($totalPhotoBytes > self::INSPECTION_MAX_TOTAL_PHOTO_BYTES) {
            throw ValidationException::withMessages([
                'payload.photos' => ['Total photo size must be 12 MB or smaller.'],
            ]);
        }
    }

    private function managedInspectionPhotoBytes(array $photo, string $fieldPath): ?int
    {
        $mediaId = trim((string) ($photo['mediaId'] ?? $photo['media_id'] ?? ''));
        if ($mediaId === '') {
            return null;
        }

        $media = ReportMedia::query()
            ->where('public_id', $mediaId)
            ->where('module', 'inspection')
            ->first();
        if (! $media) {
            throw ValidationException::withMessages([
                "{$fieldPath}.mediaId" => ['Invalid managed photo reference.'],
            ]);
        }
        if ((int) $media->size_bytes > self::INSPECTION_MAX_PHOTO_BYTES) {
            throw ValidationException::withMessages([
                "{$fieldPath}.mediaId" => ['Each photo must be 1.5 MB or smaller.'],
            ]);
        }

        return (int) $media->size_bytes;
    }

    private function validateInspectionReportRemarks(array $payload): void
    {
        $value = $payload['reportRemarks'] ?? $payload['report_remarks'] ?? '';
        if (is_array($value) || is_object($value)) {
            throw ValidationException::withMessages([
                'payload.reportRemarks' => ['Additional report remarks must be text.'],
            ]);
        }

        if (mb_strlen(trim((string) $value), 'UTF-8') > self::INSPECTION_REPORT_REMARKS_MAX_LENGTH) {
            throw ValidationException::withMessages([
                'payload.reportRemarks' => ['Additional report remarks may not exceed 2000 characters.'],
            ]);
        }
    }

    private function inspectionPayloadPhotoRows(array $payload): array
    {
        $rows = [];
        $rootPhotos = is_array($payload['photos'] ?? null) ? $payload['photos'] : [];
        foreach ($rootPhotos as $index => $photo) {
            $rows[] = [
                'path' => "payload.photos.{$index}",
                'photo' => $photo,
            ];
        }

        $inspectionIssues = $payload['inspectionIssues']
            ?? $payload['inspection_issues']
            ?? $payload['issues']
            ?? [];
        if (is_array($inspectionIssues)) {
            foreach ($inspectionIssues as $issueIndex => $issue) {
                if (! is_array($issue)) {
                    continue;
                }
                $photos = $issue['photos'] ?? $issue['issue_photos'] ?? [];
                if (! is_array($photos)) {
                    continue;
                }
                foreach ($photos as $photoIndex => $photo) {
                    $rows[] = [
                        'path' => "payload.inspectionIssues.{$issueIndex}.photos.{$photoIndex}",
                        'photo' => $photo,
                    ];
                }
            }
        }

        $erAuxChecks = $payload['erAuxChecks'] ?? $payload['er_aux_checks'] ?? [];
        if (is_array($erAuxChecks)) {
            foreach ($erAuxChecks as $checkIndex => $check) {
                if (! is_array($check)) {
                    continue;
                }
                $photos = is_array($check['photos'] ?? null) ? $check['photos'] : [];
                foreach ($photos as $photoIndex => $photo) {
                    $rows[] = [
                        'path' => "payload.erAuxChecks.{$checkIndex}.photos.{$photoIndex}",
                        'photo' => $photo,
                    ];
                }
                $defectPhotos = $check['defectPhotos'] ?? $check['defect_photos'] ?? [];
                if (is_array($defectPhotos)) {
                    foreach ($defectPhotos as $photoIndex => $photo) {
                        $rows[] = [
                            'path' => "payload.erAuxChecks.{$checkIndex}.defectPhotos.{$photoIndex}",
                            'photo' => $photo,
                        ];
                    }
                }
            }
        }

        $highAngleChecks = $payload['highAngleChecks'] ?? $payload['high_angle_checks'] ?? [];
        if (is_array($highAngleChecks)) {
            foreach ($highAngleChecks as $checkIndex => $check) {
                if (! is_array($check)) {
                    continue;
                }
                $photos = is_array($check['photos'] ?? null) ? $check['photos'] : [];
                foreach ($photos as $photoIndex => $photo) {
                    $rows[] = [
                        'path' => "payload.highAngleChecks.{$checkIndex}.photos.{$photoIndex}",
                        'photo' => $photo,
                    ];
                }
                $conditionPhotos = $check['conditionPhotos'] ?? $check['condition_photos'] ?? [];
                if (is_array($conditionPhotos)) {
                    foreach ($conditionPhotos as $photoIndex => $photo) {
                        $rows[] = [
                            'path' => "payload.highAngleChecks.{$checkIndex}.conditionPhotos.{$photoIndex}",
                            'photo' => $photo,
                        ];
                    }
                }
                $additionalPhotos = $check['additionalPhotos'] ?? $check['additional_photos'] ?? [];
                if (is_array($additionalPhotos)) {
                    foreach ($additionalPhotos as $photoIndex => $photo) {
                        $rows[] = [
                            'path' => "payload.highAngleChecks.{$checkIndex}.additionalPhotos.{$photoIndex}",
                            'photo' => $photo,
                        ];
                    }
                }
            }
        }

        foreach ([
            'frtDailyChecks' => $payload['frtDailyChecks'] ?? $payload['frt_daily_checks'] ?? [],
            'frtOneOffChecks' => $payload['frtOneOffChecks'] ?? $payload['frt_one_off_checks'] ?? [],
        ] as $checksKey => $checks) {
            if (! is_array($checks)) {
                continue;
            }
            foreach ($checks as $checkIndex => $check) {
                if (! is_array($check)) {
                    continue;
                }
                $photos = is_array($check['photos'] ?? null) ? $check['photos'] : [];
                foreach ($photos as $photoIndex => $photo) {
                    $rows[] = [
                        'path' => "payload.{$checksKey}.{$checkIndex}.photos.{$photoIndex}",
                        'photo' => $photo,
                    ];
                }
                $additionalPhotos = $check['additionalPhotos'] ?? $check['additional_photos'] ?? [];
                if (is_array($additionalPhotos)) {
                    foreach ($additionalPhotos as $photoIndex => $photo) {
                        $rows[] = [
                            'path' => "payload.{$checksKey}.{$checkIndex}.additionalPhotos.{$photoIndex}",
                            'photo' => $photo,
                        ];
                    }
                }
            }
        }

        $fireExtinguisherChecks = $payload['fireExtinguisherChecks'] ?? $payload['fire_extinguisher_checks'] ?? [];
        if (is_array($fireExtinguisherChecks)) {
            foreach ($fireExtinguisherChecks as $checkIndex => $check) {
                if (! is_array($check)) {
                    continue;
                }
                $photos = is_array($check['photos'] ?? null) ? $check['photos'] : [];
                foreach ($photos as $photoIndex => $photo) {
                    $rows[] = [
                        'path' => "payload.fireExtinguisherChecks.{$checkIndex}.photos.{$photoIndex}",
                        'photo' => $photo,
                    ];
                }

                foreach (self::INSPECTION_FIRE_EXTINGUISHER_CHECK_EVIDENCE_FIELDS as $meta) {
                    $photosKey = $meta['photos'];
                    $snakePhotosKey = Str::snake($photosKey);
                    $defectPhotos = $check[$photosKey] ?? $check[$snakePhotosKey] ?? [];
                    if (! is_array($defectPhotos)) {
                        continue;
                    }
                    foreach ($defectPhotos as $photoIndex => $photo) {
                        $rows[] = [
                            'path' => "payload.fireExtinguisherChecks.{$checkIndex}.{$photosKey}.{$photoIndex}",
                            'photo' => $photo,
                        ];
                    }
                }
            }
        }

        $hydraulicChecks = $payload['hydraulicChecks'] ?? $payload['hydraulic_checks'] ?? [];
        if (is_array($hydraulicChecks)) {
            foreach ($hydraulicChecks as $checkIndex => $check) {
                if (! is_array($check)) {
                    continue;
                }
                $photos = is_array($check['photos'] ?? null) ? $check['photos'] : [];
                foreach ($photos as $photoIndex => $photo) {
                    $rows[] = [
                        'path' => "payload.hydraulicChecks.{$checkIndex}.photos.{$photoIndex}",
                        'photo' => $photo,
                    ];
                }

                foreach (self::INSPECTION_HYDRAULIC_CHECK_EVIDENCE_FIELDS as $meta) {
                    $photosKey = $meta['photos'];
                    $snakePhotosKey = Str::snake($photosKey);
                    $defectPhotos = $check[$photosKey] ?? $check[$snakePhotosKey] ?? [];
                    if (! is_array($defectPhotos)) {
                        continue;
                    }
                    foreach ($defectPhotos as $photoIndex => $photo) {
                        $rows[] = [
                            'path' => "payload.hydraulicChecks.{$checkIndex}.{$photosKey}.{$photoIndex}",
                            'photo' => $photo,
                        ];
                    }
                }
            }
        }

        foreach ([
            'scbaBackPlateChecks' => $payload['scbaBackPlateChecks'] ?? $payload['scba_back_plate_checks'] ?? [],
            'scbaCylinderChecks' => $payload['scbaCylinderChecks'] ?? $payload['scba_cylinder_checks'] ?? [],
            'scbaFaceMaskChecks' => $payload['scbaFaceMaskChecks'] ?? $payload['scba_face_mask_checks'] ?? [],
        ] as $checksKey => $checks) {
            if (! is_array($checks)) {
                continue;
            }
            foreach ($checks as $checkIndex => $check) {
                if (! is_array($check)) {
                    continue;
                }
                $photos = is_array($check['photos'] ?? null) ? $check['photos'] : [];
                foreach ($photos as $photoIndex => $photo) {
                    $rows[] = [
                        'path' => "payload.{$checksKey}.{$checkIndex}.photos.{$photoIndex}",
                        'photo' => $photo,
                    ];
                }
                foreach (self::INSPECTION_SCBA_SECTION_FIELDS[$this->scbaSectionKeyFromPayloadKey($checksKey)] ?? [] as $field => $kind) {
                    if ($kind !== 'status') {
                        continue;
                    }
                    $photosKey = "{$field}Photos";
                    $snakePhotosKey = Str::snake($photosKey);
                    $issuePhotos = $check[$photosKey] ?? $check[$snakePhotosKey] ?? [];
                    if (! is_array($issuePhotos)) {
                        continue;
                    }
                    foreach ($issuePhotos as $photoIndex => $photo) {
                        $rows[] = [
                            'path' => "payload.{$checksKey}.{$checkIndex}.{$photosKey}.{$photoIndex}",
                            'photo' => $photo,
                        ];
                    }
                }
            }
        }

        $customSections = $payload['scbaCustomSections'] ?? $payload['scba_custom_sections'] ?? [];
        if (is_array($customSections)) {
            foreach ($customSections as $sectionIndex => $section) {
                if (! is_array($section)) {
                    continue;
                }
                if (($section['removed'] ?? false) === true) {
                    continue;
                }
                $fields = is_array($section['fields'] ?? null) ? $section['fields'] : [];
                $sectionRows = is_array($section['rows'] ?? null) ? $section['rows'] : [];
                foreach ($sectionRows as $checkIndex => $check) {
                    if (! is_array($check)) {
                        continue;
                    }
                    if (($check['removed'] ?? false) === true) {
                        continue;
                    }
                    foreach ($fields as $field) {
                        if (! is_array($field)) {
                            continue;
                        }
                        $fieldKey = trim((string) ($field['key'] ?? ''));
                        if ($fieldKey === '') {
                            continue;
                        }
                        $photosKey = "{$fieldKey}Photos";
                        $snakePhotosKey = Str::snake($photosKey);
                        $issuePhotos = $check[$photosKey] ?? $check[$snakePhotosKey] ?? [];
                        if (! is_array($issuePhotos)) {
                            continue;
                        }
                        foreach ($issuePhotos as $photoIndex => $photo) {
                            $rows[] = [
                                'path' => "payload.scbaCustomSections.{$sectionIndex}.rows.{$checkIndex}.{$photosKey}.{$photoIndex}",
                                'photo' => $photo,
                            ];
                        }
                    }
                    $additionalPhotos = $check['photos'] ?? [];
                    if (is_array($additionalPhotos)) {
                        foreach ($additionalPhotos as $photoIndex => $photo) {
                            $rows[] = [
                                'path' => "payload.scbaCustomSections.{$sectionIndex}.rows.{$checkIndex}.photos.{$photoIndex}",
                                'photo' => $photo,
                            ];
                        }
                    }
                }
            }
        }

        return $rows;
    }

    private function scbaSectionKeyFromPayloadKey(string $payloadKey): string
    {
        return match ($payloadKey) {
            'scbaBackPlateChecks', 'scba_back_plate_checks' => 'backPlate',
            'scbaCylinderChecks', 'scba_cylinder_checks' => 'cylinder',
            'scbaFaceMaskChecks', 'scba_face_mask_checks' => 'faceMask',
            default => '',
        };
    }
}
