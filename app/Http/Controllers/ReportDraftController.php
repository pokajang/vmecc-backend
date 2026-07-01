<?php

namespace App\Http\Controllers;

use App\Models\ReportDraft;
use App\Support\Inspection\FrtDailyReference;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReportDraftController extends Controller
{
    private const ERCO_TYPE = 'erco';
    private const ERCO_DRAFT_CAP = 50;
    private const INSPECTION_TYPE = 'inspection';
    private const INSPECTION_MAX_PHOTO_COUNT = 10;
    private const INSPECTION_MAX_PHOTO_BYTES = 1572864; // 1.5 MB
    private const INSPECTION_MAX_TOTAL_PHOTO_BYTES = 12582912; // 12 MB
    private const INSPECTION_ALLOWED_IMAGE_MIMES = ['jpeg', 'jpg', 'png', 'webp'];
    private const INSPECTION_ER_AUX_CONDITION_VALUES = ['OK', 'Defect', 'Missing', 'N/A'];
    private const INSPECTION_FRT_DAILY_STATUS_VALUES = ['Checked', 'Issue'];
    private const INSPECTION_FRT_ONE_OFF_STATUS_VALUES = ['Good', 'Not Good'];
    private const INSPECTION_HIGH_ANGLE_STATUS_VALUES = ['Good', 'Not Good'];
    private const INSPECTION_HYDRAULIC_STATUS_VALUES = ['OK', 'Defect', 'N/A'];
    private const INSPECTION_SCBA_STATUS_VALUES = ['Good', 'Not Good'];
    private const INSPECTION_HSE_SELECTION_VALUES = ['areaSatisfactory', 'unsafeAct', 'unsafeCondition', 'environmental'];
    private const INSPECTION_HSE_SEVERITY_VALUES = ['Low', 'Medium', 'High', 'Critical'];
    private const INSPECTION_FIRE_EXTINGUISHER_STATUS_VALUES = [
        'physicalCondition' => ['Good', 'Not Good', 'N/A'],
        'signageCondition' => ['Good', 'Not Good', 'N/A'],
        'boxKeyAvailability' => ['Yes', 'No', 'N/A'],
        'boxGlassAvailability' => ['Yes', 'No', 'N/A'],
        'operationalCondition' => ['Operational', 'Not Operational', 'N/A'],
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

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $reportType = $this->normalizeReportType((string) $request->query('report_type', ''));
        if ($reportType === '') {
            return response()->json(['message' => 'report_type is required.'], 422);
        }

        $limit = min(100, max(1, (int) $request->query('limit', 50)));
        $page = max(1, (int) $request->query('page', 1));

        $query = ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('report_type', $reportType)
            ->orderByDesc('saved_at')
            ->orderByDesc('id');

        $total = (clone $query)->count();
        $rows = $query->forPage($page, $limit)->get();

        return response()->json([
            'data' => $rows->map(fn (ReportDraft $row) => $this->formatRow($row))->values()->all(),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
            ],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $reportType = $this->normalizeReportType((string) $request->query('report_type', ''));
        if ($reportType === '') {
            return response()->json(['message' => 'report_type is required.'], 422);
        }

        $row = ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('report_type', $reportType)
            ->orderByDesc('saved_at')
            ->orderByDesc('id')
            ->first();

        if (!$row) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->formatRow($row)]);
    }

    public function showById(Request $request, string $draftId): JsonResponse
    {
        $user = $request->user();
        $row = ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('draft_id', trim((string) $draftId))
            ->firstOrFail();

        return response()->json(['data' => $this->formatRow($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'report_type' => ['required', 'string', 'max:60'],
            'payload' => ['required', 'array'],
            'title' => ['nullable', 'string', 'max:190'],
            'origin_mode' => ['nullable', 'string', 'in:new,edit'],
            'source_report_uid' => ['nullable', 'string', 'max:190'],
            'draft_id' => ['nullable', 'string', 'max:80'],
        ]);

        $reportType = $this->normalizeReportType((string) $data['report_type']);
        if ($reportType === '') {
            return response()->json(['message' => 'report_type is required.'], 422);
        }
        if ($reportType === self::INSPECTION_TYPE) {
            $this->validateInspectionPayload((array) $data['payload']);
            $data['payload'] = $this->normalizeInspectionPayload((array) $data['payload']);
        }

        $incomingDraftId = trim((string) ($data['draft_id'] ?? ''));
        $row = null;

        if ($incomingDraftId !== '') {
            $row = ReportDraft::query()
                ->where('user_id', $user->id)
                ->where('draft_id', $incomingDraftId)
                ->first();
        } else {
            $row = ReportDraft::query()
                ->where('user_id', $user->id)
                ->where('report_type', $reportType)
                ->orderByDesc('saved_at')
                ->orderByDesc('id')
                ->first();
        }

        if (!$row) {
            $row = $this->createDraft($user->id, $data, $reportType);
            return response()->json(['data' => $this->formatRow($row)], 201);
        }

        $row->fill([
            'payload' => $data['payload'],
            'title' => $this->normalizeNullableString($data['title'] ?? null),
            'origin_mode' => $this->normalizeOriginMode($data['origin_mode'] ?? null),
            'source_report_uid' => $this->normalizeNullableString($data['source_report_uid'] ?? null),
            'saved_at' => now(),
        ]);
        $row->save();

        return response()->json(['data' => $this->formatRow($row)]);
    }

    public function updateById(Request $request, string $draftId): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'payload' => ['required', 'array'],
            'title' => ['nullable', 'string', 'max:190'],
            'origin_mode' => ['nullable', 'string', 'in:new,edit'],
            'source_report_uid' => ['nullable', 'string', 'max:190'],
        ]);

        $row = ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('draft_id', trim((string) $draftId))
            ->firstOrFail();
        if ($this->normalizeReportType((string) ($row->report_type ?? '')) === self::INSPECTION_TYPE) {
            $this->validateInspectionPayload((array) $data['payload']);
            $data['payload'] = $this->normalizeInspectionPayload((array) $data['payload']);
        }

        $row->fill([
            'payload' => $data['payload'],
            'title' => $this->normalizeNullableString($data['title'] ?? null),
            'origin_mode' => $this->normalizeOriginMode($data['origin_mode'] ?? null),
            'source_report_uid' => $this->normalizeNullableString($data['source_report_uid'] ?? null),
            'saved_at' => now(),
        ]);
        $row->save();

        return response()->json(['data' => $this->formatRow($row)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $reportType = $this->normalizeReportType((string) $request->query('report_type', ''));
        if ($reportType === '') {
            return response()->json(['message' => 'report_type is required.'], 422);
        }

        ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('report_type', $reportType)
            ->delete();

        return response()->json(['message' => 'Draft cleared.']);
    }

    public function destroyById(Request $request, string $draftId): JsonResponse
    {
        $user = $request->user();
        ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('draft_id', trim((string) $draftId))
            ->delete();

        return response()->json(['message' => 'Draft deleted.']);
    }

    private function normalizeReportType(string $value): string
    {
        return strtolower(trim($value));
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function normalizeOriginMode(mixed $value): string
    {
        $text = strtolower(trim((string) ($value ?? '')));
        return $text === 'edit' ? 'edit' : 'new';
    }

    private function createDraft(int $userId, array $data, string $reportType): ReportDraft
    {
        if ($reportType === self::ERCO_TYPE) {
            $count = ReportDraft::query()
                ->where('user_id', $userId)
                ->where('report_type', $reportType)
                ->count();
            if ($count >= self::ERCO_DRAFT_CAP) {
                throw ValidationException::withMessages([
                    'report_type' => ['Draft limit reached. You can only keep up to 50 ERCO drafts.'],
                ]);
            }
        }

        return ReportDraft::query()->create([
            'user_id' => $userId,
            'draft_id' => 'drf_' . Str::lower(Str::random(20)),
            'report_type' => $reportType,
            'title' => $this->normalizeNullableString($data['title'] ?? null),
            'origin_mode' => $this->normalizeOriginMode($data['origin_mode'] ?? null),
            'source_report_uid' => $this->normalizeNullableString($data['source_report_uid'] ?? null),
            'payload' => $data['payload'],
            'saved_at' => now(),
        ]);
    }

    private function formatRow(ReportDraft $row): array
    {
        return [
            'id' => $row->id,
            'draft_id' => $row->draft_id,
            'report_type' => $row->report_type,
            'title' => $row->title,
            'origin_mode' => $row->origin_mode ?: 'new',
            'source_report_uid' => $row->source_report_uid,
            'payload' => is_array($row->payload) ? $row->payload : [],
            'saved_at' => optional($row->saved_at)->toIso8601String(),
            'created_at' => optional($row->created_at)->toIso8601String(),
            'updated_at' => optional($row->updated_at)->toIso8601String(),
        ];
    }

    private function validateInspectionPayload(array $payload): void
    {
        if (array_key_exists('checklist', $payload)) {
            $this->normalizeInspectionChecklist($payload['checklist']);
        }

        if (array_key_exists('erAuxChecks', $payload) || array_key_exists('er_aux_checks', $payload)) {
            $this->normalizeInspectionErAuxChecks($payload['erAuxChecks'] ?? $payload['er_aux_checks']);
        }

        if (
            $this->isFrtDailyInspectionType((string) ($payload['incidentType'] ?? $payload['inspectionType'] ?? ''))
            || array_key_exists('frtDailyChecks', $payload)
            || array_key_exists('frt_daily_checks', $payload)
            || array_key_exists('frtOneOffChecks', $payload)
            || array_key_exists('frt_one_off_checks', $payload)
        ) {
            $dailyRows = $this->normalizeInspectionFrtDailyChecks(
                $payload['frtDailyChecks'] ?? $payload['frt_daily_checks'] ?? []
            );
            $oneOffRows = $this->normalizeInspectionFrtOneOffChecks(
                $payload['frtOneOffChecks'] ?? $payload['frt_one_off_checks'] ?? []
            );
            $this->validateInspectionFrtDailyRows($dailyRows, 'payload.frtDailyChecks');
            $this->validateInspectionFrtOneOffRows($oneOffRows, 'payload.frtOneOffChecks');
        }

        if (array_key_exists('highAngleChecks', $payload) || array_key_exists('high_angle_checks', $payload)) {
            $rows = $this->normalizeInspectionHighAngleChecks(
                $payload['highAngleChecks'] ?? $payload['high_angle_checks']
            );
            $this->validateInspectionHighAngleRemarks($rows, 'payload.highAngleChecks');
        }

        if (array_key_exists('scbaBackPlateChecks', $payload) || array_key_exists('scba_back_plate_checks', $payload)) {
            $rows = $this->normalizeInspectionScbaChecks(
                $payload['scbaBackPlateChecks'] ?? $payload['scba_back_plate_checks'],
                'backPlate',
                'payload.scbaBackPlateChecks'
            );
            $this->validateInspectionScbaRemarks($rows, 'payload.scbaBackPlateChecks', 'backPlate');
        }

        if (array_key_exists('scbaCylinderChecks', $payload) || array_key_exists('scba_cylinder_checks', $payload)) {
            $rows = $this->normalizeInspectionScbaChecks(
                $payload['scbaCylinderChecks'] ?? $payload['scba_cylinder_checks'],
                'cylinder',
                'payload.scbaCylinderChecks'
            );
            $this->validateInspectionScbaRemarks($rows, 'payload.scbaCylinderChecks', 'cylinder');
        }

        if (array_key_exists('scbaFaceMaskChecks', $payload) || array_key_exists('scba_face_mask_checks', $payload)) {
            $rows = $this->normalizeInspectionScbaChecks(
                $payload['scbaFaceMaskChecks'] ?? $payload['scba_face_mask_checks'],
                'faceMask',
                'payload.scbaFaceMaskChecks'
            );
            $this->validateInspectionScbaRemarks($rows, 'payload.scbaFaceMaskChecks', 'faceMask');
        }

        if (array_key_exists('hseSelections', $payload) || array_key_exists('hse_selections', $payload)) {
            $this->normalizeInspectionHseSelections($payload['hseSelections'] ?? $payload['hse_selections']);
        }

        if (array_key_exists('hseSeverity', $payload) || array_key_exists('hse_severity', $payload)) {
            $this->normalizeInspectionHseSeverity(
                $payload['hseSeverity'] ?? $payload['hse_severity'],
                'payload.hseSeverity'
            );
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
            if (!is_array($photo)) {
                throw ValidationException::withMessages([
                    $fieldPath => ['Invalid photo payload.'],
                ]);
            }

            $url = trim((string) ($photo['url'] ?? ''));
            if ($url === '') {
                throw ValidationException::withMessages([
                    "{$fieldPath}.url" => ['Photo URL is required.'],
                ]);
            }

            if (!preg_match('/^data:image\/([a-z0-9.+-]+);base64,([a-z0-9+\/=\r\n]+)$/i', $url, $match)) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.url" => [
                        'Photo must be an inline base64 data URL image.',
                    ],
                ]);
            }

            $imageMime = strtolower(trim((string) ($match[1] ?? '')));
            if (!in_array($imageMime, self::INSPECTION_ALLOWED_IMAGE_MIMES, true)) {
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

        $erAuxChecks = $payload['erAuxChecks'] ?? $payload['er_aux_checks'] ?? [];
        if (is_array($erAuxChecks)) {
            foreach ($erAuxChecks as $checkIndex => $check) {
                if (!is_array($check)) {
                    continue;
                }
                $photos = is_array($check['photos'] ?? null) ? $check['photos'] : [];
                foreach ($photos as $photoIndex => $photo) {
                    $rows[] = [
                        'path' => "payload.erAuxChecks.{$checkIndex}.photos.{$photoIndex}",
                        'photo' => $photo,
                    ];
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
                if (!is_array($check)) {
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
                    if (!is_array($defectPhotos)) {
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

        return $rows;
    }

    private function normalizeInspectionPayload(array $payload): array
    {
        if (!array_key_exists('checklist', $payload)) {
            $payload['checklist'] = [];
        }

        $payload['checklist'] = $this->normalizeInspectionChecklist($payload['checklist']);
        if (!empty($payload['checklist']) && trim((string) ($payload['checklistVersion'] ?? '')) === '') {
            $payload['checklistVersion'] = 'inspection-checklist-v1';
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
            || array_key_exists('frtInspectedBy', $payload)
            || array_key_exists('frt_inspected_by', $payload)
            || array_key_exists('frtInspectionDate', $payload)
            || array_key_exists('frt_inspection_date', $payload)
            || array_key_exists('frtShift', $payload)
            || array_key_exists('frt_shift', $payload)
            || array_key_exists('frtTruckReference', $payload)
            || array_key_exists('frt_truck_reference', $payload)
            || array_key_exists('frtDailyChecks', $payload)
            || array_key_exists('frt_daily_checks', $payload)
            || array_key_exists('frtOneOffChecks', $payload)
            || array_key_exists('frt_one_off_checks', $payload);

        if ($isFrtPayload) {
            $payload['location'] = FrtDailyReference::MAIN_LOCATION;
            $payload['selectedLocation'] = FrtDailyReference::MAIN_LOCATION;
            $payload['mainLocation'] = FrtDailyReference::MAIN_LOCATION;
            $payload['subLocation'] = '';
            $payload['locationPath'] = [FrtDailyReference::MAIN_LOCATION];
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

        $payload = $this->normalizeInspectionHsePayload($payload);

        return $payload;
    }

    private function normalizeInspectionHsePayload(array $payload): array
    {
        $fields = [
            'hseInspectedBy',
            'hseInspectionDate',
            'hseAreaConditionRemarks',
            'hseUnsafeActDetails',
            'hseUnsafeConditionDetails',
            'hseEnvironmentalDetails',
            'hseImmediateAction',
            'hseCorrectiveAction',
            'hseResponsiblePerson',
            'hseTargetDate',
            'hseRemarks',
        ];

        foreach ($fields as $field) {
            $snakeField = Str::snake($field);
            if (array_key_exists($field, $payload) || array_key_exists($snakeField, $payload)) {
                $payload[$field] = trim((string) ($payload[$field] ?? $payload[$snakeField] ?? ''));
                unset($payload[$snakeField]);
            }
        }

        if (array_key_exists('hseSelections', $payload) || array_key_exists('hse_selections', $payload)) {
            $payload['hseSelections'] = $this->normalizeInspectionHseSelections(
                $payload['hseSelections'] ?? $payload['hse_selections']
            );
            unset($payload['hse_selections']);
        }

        if (array_key_exists('hseSeverity', $payload) || array_key_exists('hse_severity', $payload)) {
            $payload['hseSeverity'] = $this->normalizeInspectionHseSeverity(
                $payload['hseSeverity'] ?? $payload['hse_severity'],
                'payload.hseSeverity'
            );
            unset($payload['hse_severity']);
        }

        return $payload;
    }

    private function normalizeInspectionChecklist(mixed $checklist): array
    {
        if (!is_array($checklist)) {
            throw ValidationException::withMessages([
                'payload.checklist' => ['Checklist must be an array.'],
            ]);
        }

        $rows = [];
        foreach ($checklist as $index => $item) {
            if (!is_array($item)) {
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
            $inspectionType = trim((string) ($item['inspectionType'] ?? $item['incidentType'] ?? ''));
            $id = trim((string) ($item['id'] ?? ''));

            $rows[] = array_merge($item, [
                'id' => $id !== '' ? $id : strtolower(preg_replace('/[^a-z0-9]+/i', '-', $inspectionType.'-'.$label)),
                'label' => $label,
                'inspectionType' => $inspectionType,
                'selected' => ($item['selected'] ?? true) !== false,
                'selectedAt' => trim((string) ($item['selectedAt'] ?? $item['selected_at'] ?? '')),
            ]);
        }

        return $rows;
    }

    private function normalizeInspectionHydraulicChecks(mixed $checks): array
    {
        if (!is_array($checks)) {
            throw ValidationException::withMessages([
                'payload.hydraulicChecks' => ['Hydraulic checks must be an array.'],
            ]);
        }

        $rows = [];
        foreach ($checks as $index => $item) {
            if (!is_array($item)) {
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
        if (!is_array($checks)) {
            throw ValidationException::withMessages([
                'payload.erAuxChecks' => ['ER Aux checks must be an array.'],
            ]);
        }

        $rows = [];
        foreach ($checks as $index => $item) {
            if (!is_array($item)) {
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
                $normalized['is_custom_equipment']
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
                'certificationValidityRaw' => trim((string) ($item['certificationValidityRaw'] ?? $item['certification_validity_raw'] ?? '')),
                'daysLeftToExpire' => trim((string) ($item['daysLeftToExpire'] ?? $item['days_left_to_expire'] ?? '')),
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
                $normalized['certification_validity_raw'],
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
            ]);
            unset($normalized['main_location'], $normalized['row_number'], $normalized['sub_location']);

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
            ]);
            unset($normalized['row_number'], $normalized['main_location'], $normalized['row_kind'], $normalized['reading_value']);

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
            ]);
            unset($normalized['row_number'], $normalized['main_location']);

            $rows[] = $normalized;
        }

        return $this->orderNormalizedFrtOneOffRows($rows);
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
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    private function validateInspectionScbaRemarks(array $rows, string $fieldPath, string $sectionKey): void
    {
        $fieldMap = self::INSPECTION_SCBA_SECTION_FIELDS[$sectionKey] ?? [];
        foreach ($rows as $index => $row) {
            $hasNotGoodStatus = false;
            foreach ($fieldMap as $field => $kind) {
                if ($kind !== 'status') {
                    continue;
                }
                if (strcasecmp(trim((string) ($row[$field] ?? '')), 'Not Good') === 0) {
                    $hasNotGoodStatus = true;
                    break;
                }
            }

            if ($hasNotGoodStatus && trim((string) ($row['remarks'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.remarks" => ['SCBA remarks are required when any status is Not Good.'],
                ]);
            }
        }
    }

    private function validateInspectionHighAngleRemarks(array $rows, string $fieldPath): void
    {
        foreach ($rows as $index => $row) {
            if (
                strcasecmp(trim((string) ($row['condition'] ?? '')), 'Not Good') === 0
                && trim((string) ($row['remarks'] ?? '')) === ''
            ) {
                throw ValidationException::withMessages([
                    "{$fieldPath}.{$index}.remarks" => ['High Angle remarks are required when condition is Not Good.'],
                ]);
            }
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

    private function normalizeInspectionHseSelections(mixed $value): array
    {
        $source = is_array($value) ? $value : [$value];
        $rows = [];

        foreach ($source as $item) {
            $normalized = $this->normalizeInspectionHseSelection($item);
            if ($normalized !== '' && ! in_array($normalized, $rows, true)) {
                $rows[] = $normalized;
            }
        }

        return $rows;
    }

    private function normalizeInspectionHseSelection(mixed $value): string
    {
        $key = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', trim((string) $value)));
        $aliases = [
            'areasatisfactory' => 'areaSatisfactory',
            'satisfactory' => 'areaSatisfactory',
            'unsafeact' => 'unsafeAct',
            'unsafecondition' => 'unsafeCondition',
            'environmental' => 'environmental',
            'environment' => 'environmental',
        ];

        if (isset($aliases[$key])) {
            return $aliases[$key];
        }

        if (in_array((string) $value, self::INSPECTION_HSE_SELECTION_VALUES, true)) {
            return (string) $value;
        }

        throw ValidationException::withMessages([
            'payload.hseSelections' => ['HSE selection value is not valid.'],
        ]);
    }

    private function normalizeInspectionHseSeverity(mixed $value, string $fieldPath): string
    {
        $severity = trim((string) $value);
        if ($severity === '') {
            return '';
        }

        foreach (self::INSPECTION_HSE_SEVERITY_VALUES as $allowed) {
            if (strcasecmp($severity, $allowed) === 0) {
                return $allowed;
            }
        }

        throw ValidationException::withMessages([
            $fieldPath => ['HSE severity must be Low, Medium, High, or Critical.'],
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
        return Str::of($inspectionType)->squish()->lower()->toString() === 'frt daily inspection';
    }

    private function orderNormalizedFrtDailyRows(array $rows): array
    {
        $ordered = [];
        $rowsById = [];

        foreach ($rows as $row) {
            $rowsById[trim((string) ($row['id'] ?? ''))] = $row;
        }

        foreach (FrtDailyReference::dailyRows() as $expected) {
            $id = $expected['id'];
            if (array_key_exists($id, $rowsById)) {
                $ordered[] = $rowsById[$id];
                unset($rowsById[$id]);
            }
        }

        foreach ($rowsById as $row) {
            $ordered[] = $row;
        }

        return $ordered;
    }

    private function orderNormalizedFrtOneOffRows(array $rows): array
    {
        $ordered = [];
        $rowsById = [];

        foreach ($rows as $row) {
            $rowsById[trim((string) ($row['id'] ?? ''))] = $row;
        }

        foreach (FrtDailyReference::oneOffRows() as $expected) {
            $id = $expected['id'];
            if (array_key_exists($id, $rowsById)) {
                $ordered[] = $rowsById[$id];
                unset($rowsById[$id]);
            }
        }

        foreach ($rowsById as $row) {
            $ordered[] = $row;
        }

        return $ordered;
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
        if (!is_array($photos)) {
            return [];
        }

        $rows = [];
        foreach ($photos as $photo) {
            if (!is_array($photo)) {
                continue;
            }
            $url = trim((string) ($photo['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $rows[] = [
                'id' => trim((string) ($photo['id'] ?? '')),
                'fileName' => trim((string) ($photo['fileName'] ?? $photo['file_name'] ?? '')),
                'description' => (string) ($photo['description'] ?? ''),
                'url' => $url,
            ];
        }

        return $rows;
    }
}
