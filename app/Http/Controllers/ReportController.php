<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\ReportTimelineEntry;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\InspectionCheckRowSyncService;
use App\Services\InspectionWorkflowService;
use App\Services\WorkflowNotificationService;
use App\Support\Inspection\FrtDailyReference;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function __construct(
        private readonly WorkflowNotificationService $notificationService,
        private readonly AssignmentAuthorizationService $authorizationService,
        private readonly InspectionCheckRowSyncService $inspectionCheckRowSyncService,
        private readonly InspectionWorkflowService $inspectionWorkflowService,
    ) {
    }

    private const STATUS_DRAFT = 'Draft';
    private const STATUS_SUBMITTED = 'Submitted';
    private const STATUS_REVIEWED = 'Reviewed';
    private const STATUS_APPROVED = 'Approved';
    private const STATUS_REJECTED = 'Rejected';
    private const STATUS_CANCELLED = 'Cancelled';
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
        $reportTypeFilter = trim((string) $request->input('reportType', ''));
        if (strtolower($reportTypeFilter) === 'inspection') {
            $this->ensureInspectionPermission($request);
        }
        $scope = strtolower(trim((string) $request->input('scope', 'mine')));
        $isAllInspectionScope =
            $scope === 'all'
            && strtolower($reportTypeFilter) === 'inspection'
            && $this->authorizationService->hasPermission($user, 'reports.manage|reports.inspection.view');

        $query = Report::query()->with('timelineEntries');
        if (! $isAllInspectionScope) {
            $query->where('owner_user_id', $user->id);
        }

        if ($request->filled('reportType') && $request->input('reportType') !== 'All') {
            $query->where('report_type', trim((string) $request->input('reportType')));
        }
        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', trim((string) $request->input('status')));
        }
        if (strtolower($reportTypeFilter) === 'inspection') {
            if ($request->filled('has_checklist')) {
                $query->where('inspection_has_checklist', filter_var($request->input('has_checklist'), FILTER_VALIDATE_BOOLEAN));
            }
            $checklistItem = trim((string) ($request->input('checklist_item') ?? $request->input('checklistItem') ?? ''));
            if ($checklistItem !== '') {
                $query->where(function ($builder) use ($checklistItem) {
                    $builder
                        ->whereJsonContains('inspection_checklist_item_ids', $checklistItem)
                        ->orWhereJsonContains('inspection_checklist_item_labels', $checklistItem);
                });
            }
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($search) {
                $builder->where('display_id', 'like', "%{$search}%")
                    ->orWhere('report_uid', 'like', "%{$search}%")
                    ->orWhere('report_type', 'like', "%{$search}%");
            });
        }

        $sort = (string) $request->input('sort', 'updated_at:desc');
        [$col, $dir] = array_pad(explode(':', $sort), 2, 'desc');
        $allowedCols = ['created_at', 'updated_at', 'submitted_at', 'report_type', 'status', 'display_id'];
        $col = in_array($col, $allowedCols, true) ? $col : 'updated_at';
        $dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';

        $rows = $query->orderBy($col, $dir)->orderByDesc('id')->get();

        return response()->json([
            'data' => $rows->map(fn (Report $report) => $this->formatReport($report)),
        ]);
    }

    public function show(Request $request, string $reportUid): JsonResponse
    {
        $report = $this->findReadableReport($request, $reportUid);

        return response()->json(['data' => $this->formatReport($report)]);
    }

    public function inspectionChecklistSummary(Request $request): JsonResponse
    {
        $this->ensureInspectionPermission($request);

        $query = Report::query()
            ->where('owner_user_id', $request->user()->id)
            ->where('report_type', 'inspection');

        if ($request->filled('date_from')) {
            $query->whereDate(DB::raw('COALESCE(submitted_at, created_at)'), '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate(DB::raw('COALESCE(submitted_at, created_at)'), '<=', $request->input('date_to'));
        }
        if ($request->filled('inspection_type')) {
            $query->where('payload->incidentType', trim((string) $request->input('inspection_type')));
        }
        if ($request->filled('location')) {
            $query->where('payload->location', trim((string) $request->input('location')));
        }
        if ($request->filled('has_checklist')) {
            $query->where('inspection_has_checklist', filter_var($request->input('has_checklist'), FILTER_VALIDATE_BOOLEAN));
        }

        $checklistItem = trim((string) ($request->input('checklist_item') ?? $request->input('checklistItem') ?? ''));
        if ($checklistItem !== '') {
            $query->where(function ($builder) use ($checklistItem) {
                $builder
                    ->whereJsonContains('inspection_checklist_item_ids', $checklistItem)
                    ->orWhereJsonContains('inspection_checklist_item_labels', $checklistItem);
            });
        }

        $reports = $query->get([
            'payload',
            'inspection_has_checklist',
            'submitted_at',
            'created_at',
            'updated_at',
        ]);
        $items = [];

        foreach ($reports as $report) {
            $payload = is_array($report->payload) ? $report->payload : [];
            $inspectionType = trim((string) ($payload['incidentType'] ?? ''));
            $seenAt = $this->inspectionSummaryTimestamp($report);
            $checklist = is_array($payload['checklist'] ?? null) ? $payload['checklist'] : [];
            foreach ($checklist as $item) {
                if (!is_array($item) || ($item['selected'] ?? true) === false) {
                    continue;
                }
                $label = trim((string) ($item['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $id = trim((string) ($item['id'] ?? '')) ?: strtolower(preg_replace('/[^a-z0-9]+/i', '-', $inspectionType.'-'.$label));
                if ($checklistItem !== '' && $checklistItem !== $id && $checklistItem !== $label) {
                    continue;
                }
                if (!isset($items[$id])) {
                    $items[$id] = [
                        'id' => $id,
                        'label' => $label,
                        'count' => 0,
                        'lastSeenAt' => null,
                        'inspectionTypes' => [],
                    ];
                }
                $items[$id]['count'] += 1;
                if ($seenAt !== null && ($items[$id]['lastSeenAt'] === null || $seenAt > $items[$id]['lastSeenAt'])) {
                    $items[$id]['lastSeenAt'] = $seenAt;
                }
                if ($inspectionType !== '' && !in_array($inspectionType, $items[$id]['inspectionTypes'], true)) {
                    $items[$id]['inspectionTypes'][] = $inspectionType;
                }
            }
        }

        $summaryItems = array_values($items);
        usort($summaryItems, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['label'], $b['label']));

        return response()->json([
            'data' => [
                'totalReports' => $reports->count(),
                'withChecklist' => $reports->where('inspection_has_checklist', true)->count(),
                'withoutChecklist' => $reports->where('inspection_has_checklist', false)->count(),
                'items' => $summaryItems,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'report_uid' => ['nullable', 'string', 'max:190'],
            'submission_key' => ['nullable', 'string', 'max:190'],
            'display_id' => ['required', 'string', 'max:190'],
            'report_type' => ['required', 'string', 'max:64'],
            'payload' => ['required', 'array'],
            'status' => ['nullable', 'string', 'in:Draft,Submitted'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $status = (string) ($data['status'] ?? self::STATUS_SUBMITTED);
        $isInspection = strtolower(trim((string) ($data['report_type'] ?? ''))) === 'inspection';
        if ($isInspection) {
            $this->ensureInspectionPermission($request);
            $this->validateInspectionPayload((array) $data['payload']);
            $data['payload'] = $this->normalizeInspectionPayload((array) $data['payload']);
        }
        $action = $status === self::STATUS_DRAFT ? 'DraftSaved' : 'Submitted';
        $submissionKey = trim((string) ($data['submission_key'] ?? ''));
        $checklistIndex = $isInspection
            ? $this->extractInspectionChecklistIndex((array) $data['payload'])
            : ['ids' => [], 'labels' => [], 'hasChecklist' => false];
        $workflowFields = [];
        if ($isInspection) {
            if ($status === self::STATUS_SUBMITTED && ($blockReason = $this->inspectionWorkflowService->submissionBlockReason($user)) !== null) {
                throw ValidationException::withMessages(['workflow' => [$blockReason]]);
            }
            $workflowFields = $status === self::STATUS_SUBMITTED
                ? $this->inspectionWorkflowService->appendSubmissionHistory(
                    $this->inspectionWorkflowService->buildWorkflowForSubmission($user),
                    $user,
                    'Submitted',
                    (string) ($data['remarks'] ?? ''),
                )
                : $this->inspectionWorkflowService->draftWorkflowFields();
        }

        if ($submissionKey !== '') {
            $existing = Report::query()
                ->where('owner_user_id', $user->id)
                ->where('submission_key', $submissionKey)
                ->with('timelineEntries')
                ->first();
            if ($existing instanceof Report) {
                return response()->json([
                    'data' => array_merge($this->formatReport($existing), [
                        'idempotent_replay' => true,
                    ]),
                ]);
            }
        }

        try {
            $report = DB::transaction(function () use ($data, $status, $action, $submissionKey, $user, $checklistIndex, $isInspection, $workflowFields) {
                $report = Report::create([
                    'report_uid' => trim((string) ($data['report_uid'] ?? Str::uuid()->toString())),
                    'display_id' => trim((string) $data['display_id']),
                    'submission_key' => $submissionKey !== '' ? $submissionKey : null,
                    'owner_user_id' => $user->id,
                    'report_type' => trim((string) $data['report_type']),
                    'status' => $status,
                    'version' => 1,
                    'revision' => 1,
                    'payload' => $data['payload'],
                    'inspection_checklist_item_ids' => $checklistIndex['ids'],
                    'inspection_checklist_item_labels' => $checklistIndex['labels'],
                    'inspection_has_checklist' => $checklistIndex['hasChecklist'],
                    'submitted_at' => $status === self::STATUS_SUBMITTED ? now() : null,
                ] + $workflowFields);

                $this->appendTimeline(
                    report: $report,
                    action: $action,
                    fromStatus: null,
                    toStatus: $status,
                    userId: (int) $user->id,
                    byName: (string) $user->name,
                    remarks: (string) ($data['remarks'] ?? ''),
                );

                if ($isInspection) {
                    $report->refresh();
                    $this->inspectionCheckRowSyncService->syncForReport($report, (int) $user->id);
                }

                return $report->load('timelineEntries');
            });
        } catch (QueryException $exception) {
            if ($submissionKey !== '' && $this->isSubmissionKeyDuplicateException($exception)) {
                $existing = Report::query()
                    ->where('owner_user_id', $user->id)
                    ->where('submission_key', $submissionKey)
                    ->with('timelineEntries')
                    ->first();
                if ($existing instanceof Report) {
                    return response()->json([
                        'data' => array_merge($this->formatReport($existing), [
                            'idempotent_replay' => true,
                        ]),
                    ]);
                }
            }
            throw $exception;
        }

        AuditLogger::log($request, 'report_created', $user, [
            'report_uid' => $report->report_uid,
            'display_id' => $report->display_id,
            'report_type' => $report->report_type,
            'status' => $report->status,
        ]);
        $this->emitWorkflowNotificationSafely(
            eventType: $status === self::STATUS_DRAFT ? 'edited' : 'submitted',
            report: $report,
            actor: $user,
            actionRequired: $isInspection && $status === self::STATUS_SUBMITTED,
            remarks: (string) ($data['remarks'] ?? ''),
        );

        return response()->json([
            'data' => array_merge($this->formatReport($report), [
                'idempotent_replay' => false,
            ]),
        ], 201);
    }

    public function update(Request $request, string $reportUid): JsonResponse
    {
        $user = $request->user();
        $report = $this->findOwnedReport($request, $reportUid);
        $data = $request->validate([
            'payload' => ['required', 'array'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'in:Submitted,Draft'],
        ]);

        if ((int) $data['version'] !== (int) $report->version) {
            return response()->json([
                'message' => 'Version conflict. Reload the latest report before updating.',
                'code' => 'REPORT_VERSION_CONFLICT',
                'currentVersion' => $report->version,
                'currentReport' => $this->formatReport($report->load('timelineEntries')),
            ], 409);
        }

        $targetStatus = (string) ($data['status'] ?? self::STATUS_SUBMITTED);
        $isInspection = strtolower(trim((string) ($report->report_type ?? ''))) === 'inspection';
        if ($isInspection) {
            $this->ensureInspectionPermission($request);
            $this->validateInspectionPayload((array) $data['payload']);
            $data['payload'] = $this->normalizeInspectionPayload((array) $data['payload']);
        }
        if (!in_array($report->status, [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Report cannot be edited in its current status.'],
            ]);
        }

        $nextRevision = (int) $report->revision + 1;
        $nextVersion = (int) $report->version + 1;
        $checklistIndex = $isInspection
            ? $this->extractInspectionChecklistIndex((array) $data['payload'])
            : ['ids' => [], 'labels' => [], 'hasChecklist' => false];
        $workflowFields = [];
        if ($isInspection) {
            if ($targetStatus === self::STATUS_SUBMITTED && ($blockReason = $this->inspectionWorkflowService->submissionBlockReason($user)) !== null) {
                throw ValidationException::withMessages(['workflow' => [$blockReason]]);
            }
            $workflowFields = $targetStatus === self::STATUS_SUBMITTED
                ? $this->inspectionWorkflowService->appendSubmissionHistory(
                    $this->inspectionWorkflowService->buildWorkflowForSubmission($user),
                    $user,
                    'Resubmitted',
                    (string) ($data['remarks'] ?? ''),
                )
                : $this->inspectionWorkflowService->draftWorkflowFields();
        }

        DB::transaction(function () use ($report, $data, $targetStatus, $nextRevision, $nextVersion, $user, $checklistIndex, $isInspection, $workflowFields) {
            $fromStatus = $report->status;
            $report->update([
                'payload' => $data['payload'],
                'inspection_checklist_item_ids' => $checklistIndex['ids'],
                'inspection_checklist_item_labels' => $checklistIndex['labels'],
                'inspection_has_checklist' => $checklistIndex['hasChecklist'],
                'status' => $targetStatus,
                'revision' => $nextRevision,
                'version' => $nextVersion,
                'submitted_at' => $targetStatus === self::STATUS_SUBMITTED ? now() : $report->submitted_at,
                'reviewed_at' => null,
                'approved_at' => null,
                'rejected_at' => null,
            ] + $workflowFields);

            $action = $targetStatus === self::STATUS_DRAFT ? 'DraftSaved' : 'Resubmitted';
            $this->appendTimeline(
                report: $report,
                action: $action,
                fromStatus: $fromStatus,
                toStatus: $targetStatus,
                userId: (int) $user->id,
                byName: (string) $user->name,
                remarks: (string) ($data['remarks'] ?? ''),
                revision: $nextRevision,
            );

            if ($isInspection) {
                $report->refresh();
                $this->inspectionCheckRowSyncService->syncForReport($report, (int) $user->id);
            }
        });

        $report->load('timelineEntries');
        AuditLogger::log($request, 'report_updated', $user, [
            'report_uid' => $report->report_uid,
            'display_id' => $report->display_id,
            'status' => $report->status,
            'version' => $report->version,
            'revision' => $report->revision,
        ]);
        $this->emitWorkflowNotificationSafely(
            eventType: $targetStatus === self::STATUS_SUBMITTED ? 'submitted' : 'edited',
            report: $report,
            actor: $user,
            actionRequired: $isInspection && $targetStatus === self::STATUS_SUBMITTED,
            remarks: (string) ($data['remarks'] ?? ''),
        );

        return response()->json(['data' => $this->formatReport($report)]);
    }

    public function destroy(Request $request, string $reportUid): JsonResponse
    {
        $user = $request->user();
        $report = $this->findOwnedReport($request, $reportUid);

        DB::transaction(function () use ($report, $user) {
            $this->appendTimeline(
                report: $report,
                action: 'Deleted',
                fromStatus: $report->status,
                toStatus: $report->status,
                userId: (int) $user->id,
                byName: (string) $user->name,
                remarks: 'Owner deleted report.',
            );
            $this->inspectionCheckRowSyncService->softDeleteForReport($report);
            $report->delete();
        });

        AuditLogger::log($request, 'report_deleted', $user, [
            'report_uid' => $report->report_uid,
            'display_id' => $report->display_id,
        ]);
        $this->emitWorkflowNotificationSafely(
            eventType: 'cancelled',
            report: $report,
            actor: $user,
            actionRequired: false,
            remarks: 'Owner deleted report.',
        );

        return response()->json(null, 204);
    }

    public function review(Request $request, string $reportUid): JsonResponse
    {
        return $this->applyTransition(
            request: $request,
            reportUid: $reportUid,
            action: 'Reviewed',
            allowedFrom: [self::STATUS_SUBMITTED],
            toStatus: self::STATUS_REVIEWED,
        );
    }

    public function approve(Request $request, string $reportUid): JsonResponse
    {
        return $this->applyTransition(
            request: $request,
            reportUid: $reportUid,
            action: 'Approved',
            allowedFrom: [self::STATUS_REVIEWED],
            toStatus: self::STATUS_APPROVED,
        );
    }

    public function reject(Request $request, string $reportUid): JsonResponse
    {
        return $this->applyTransition(
            request: $request,
            reportUid: $reportUid,
            action: 'Rejected',
            allowedFrom: [self::STATUS_SUBMITTED, self::STATUS_REVIEWED],
            toStatus: self::STATUS_REJECTED,
            remarksRequired: true,
        );
    }

    private function applyTransition(
        Request $request,
        string $reportUid,
        string $action,
        array $allowedFrom,
        string $toStatus,
        bool $remarksRequired = false,
    ): JsonResponse {
        $user = $request->user();
        $payload = $request->validate([
            'remarks' => [$remarksRequired ? 'required' : 'nullable', 'string', 'max:2000'],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        $report = Report::query()
            ->where('report_uid', $reportUid)
            ->firstOrFail();
        $isInspection = strtolower(trim((string) ($report->report_type ?? ''))) === 'inspection';
        if ($isInspection) {
            $this->ensureInspectionPermission($request);
        } else {
            if ((int) $report->owner_user_id !== (int) $user->id) {
                abort(404);
            }
        }
        if ((int) $payload['version'] !== (int) $report->version) {
            $isLikelyReplay =
                (int) $payload['version'] === ((int) $report->version - 1) &&
                (
                    strtolower((string) $report->status) === strtolower((string) $toStatus) ||
                    in_array(strtolower((string) $report->status), ['approved', 'rejected', 'reviewed'], true)
                )
            ;
            if ($isLikelyReplay) {
                return response()->json([
                    'data' => array_merge($this->formatReport($report->load('timelineEntries')), [
                        'idempotent_replay' => true,
                    ]),
                ]);
            }
            return response()->json([
                'message' => 'Version conflict. Reload the latest report before updating.',
                'code' => 'REPORT_VERSION_CONFLICT',
                'currentVersion' => $report->version,
                'currentReport' => $this->formatReport($report->load('timelineEntries')),
            ], 409);
        }
        if (!in_array($report->status, $allowedFrom, true)) {
            return response()->json([
                'message' => "Invalid transition from status {$report->status} via {$action}.",
                'code' => 'REPORT_INVALID_TRANSITION',
                'fromStatus' => $report->status,
                'action' => $action,
            ], 409);
        }
        if ($isInspection) {
            $workflowAction = match ($toStatus) {
                self::STATUS_REVIEWED => 'review',
                self::STATUS_APPROVED => 'approve',
                self::STATUS_REJECTED => 'reject',
                default => '',
            };
            $authorizationError = $this->inspectionWorkflowService->authorizeAction($report, $user, $workflowAction);
            if ($authorizationError !== null) {
                return response()->json([
                    'message' => $authorizationError,
                    'code' => 'INSPECTION_WORKFLOW_FORBIDDEN',
                ], 403);
            }
        }

        DB::transaction(function () use ($report, $toStatus, $action, $payload, $user, $isInspection) {
            $fromStatus = $report->status;
            $nextVersion = (int) $report->version + 1;
            $update = [
                'status' => $toStatus,
                'version' => $nextVersion,
            ];
            if ($isInspection) {
                $workflowAction = match ($toStatus) {
                    self::STATUS_REVIEWED => 'review',
                    self::STATUS_APPROVED => 'approve',
                    self::STATUS_REJECTED => 'reject',
                    default => '',
                };
                $update = array_merge(
                    $update,
                    $this->inspectionWorkflowService->advanceWorkflow(
                        $report,
                        $workflowAction,
                        $user,
                        (string) ($payload['remarks'] ?? ''),
                    ),
                );
            }
            if ($toStatus === self::STATUS_REVIEWED) $update['reviewed_at'] = now();
            if ($toStatus === self::STATUS_APPROVED) $update['approved_at'] = now();
            if ($toStatus === self::STATUS_REJECTED) $update['rejected_at'] = now();
            $report->update($update);

            $this->appendTimeline(
                report: $report,
                action: $action,
                fromStatus: $fromStatus,
                toStatus: $toStatus,
                userId: (int) $user->id,
                byName: (string) $user->name,
                remarks: (string) ($payload['remarks'] ?? ''),
            );

            if ($isInspection) {
                $report->refresh();
                $this->inspectionCheckRowSyncService->syncStatusForReport($report, (int) $user->id);
            }
        });

        $report->refresh()->load('timelineEntries');
        AuditLogger::log($request, 'report_transitioned', $user, [
            'report_uid' => $report->report_uid,
            'display_id' => $report->display_id,
            'action' => $action,
            'status' => $report->status,
            'version' => $report->version,
        ]);
        $this->emitWorkflowNotificationSafely(
            eventType: strtolower($action),
            report: $report,
            actor: $user,
            actionRequired: $isInspection && $toStatus === self::STATUS_REVIEWED,
            remarks: (string) ($payload['remarks'] ?? ''),
        );

        return response()->json(['data' => $this->formatReport($report)]);
    }

    private function findOwnedReport(Request $request, string $reportUid): Report
    {
        $user = $request->user();
        $report = Report::query()
            ->where('report_uid', $reportUid)
            ->where('owner_user_id', $user->id)
            ->with('timelineEntries')
            ->firstOrFail();
        if (strtolower(trim((string) ($report->report_type ?? ''))) === 'inspection') {
            $this->ensureInspectionPermission($request);
        }
        return $report;
    }

    private function findReadableReport(Request $request, string $reportUid): Report
    {
        $user = $request->user();
        $report = Report::query()
            ->where('report_uid', $reportUid)
            ->with('timelineEntries')
            ->firstOrFail();

        if (strtolower(trim((string) ($report->report_type ?? ''))) === 'inspection') {
            $this->ensureInspectionPermission($request);
            return $report;
        }

        if ((int) $report->owner_user_id !== (int) $user->id) {
            abort(404);
        }

        return $report;
    }

    private function appendTimeline(
        Report $report,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        ?int $userId,
        ?string $byName,
        ?string $remarks,
        ?int $revision = null,
    ): ReportTimelineEntry {
        return ReportTimelineEntry::query()->create([
            'report_id' => $report->id,
            'revision' => $revision ?? (int) $report->revision,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'by_user_id' => $userId,
            'by_name_snapshot' => $byName,
            'remarks' => $remarks,
            'meta' => null,
        ]);
    }

    private function formatReport(Report $report): array
    {
        $payload = is_array($report->payload) ? $report->payload : [];
        $history = $report->timelineEntries->map(function (ReportTimelineEntry $entry) {
            return [
                'id' => $entry->id,
                'revision' => $entry->revision,
                'action' => $entry->action,
                'fromStatus' => $entry->from_status,
                'toStatus' => $entry->to_status,
                'by' => $entry->by_name_snapshot,
                'byUserId' => $entry->by_user_id,
                'at' => optional($entry->created_at)->toIso8601String(),
                'remarks' => $entry->remarks,
                'meta' => $entry->meta ?? [],
            ];
        })->values()->all();

        if (empty($history)) {
            $fallbackAction = $report->status === self::STATUS_DRAFT ? 'DraftSaved' : 'Submitted';
            $history[] = [
                'id' => "legacy-{$report->id}-1",
                'revision' => (int) $report->revision,
                'action' => $fallbackAction,
                'fromStatus' => null,
                'toStatus' => $report->status,
                'by' => null,
                'byUserId' => null,
                'at' => optional($report->created_at)->toIso8601String(),
                'remarks' => '',
                'meta' => [],
            ];
        }

        return array_merge($payload, [
            'id' => $report->report_uid,
            'displayId' => $report->display_id,
            'submissionKey' => $report->submission_key,
            'reportType' => $report->report_type,
            'ownerUserId' => (int) $report->owner_user_id,
            'status' => $report->status,
            'submittedAt' => optional($report->submitted_at)->toIso8601String(),
            'reviewedAt' => optional($report->reviewed_at)->toIso8601String(),
            'approvedAt' => optional($report->approved_at)->toIso8601String(),
            'rejectedAt' => optional($report->rejected_at)->toIso8601String(),
            'version' => (int) $report->version,
            'revision' => (int) $report->revision,
            'workflowStage' => $this->formatWorkflowStage($report),
            'workflowSnapshot' => $this->formatWorkflowSnapshot($report),
            'nextActionRole' => $this->formatNextActionRole($report),
            'scopeTeamId' => $this->formatScopeTeamId($report),
            'approvalHistory' => is_array($report->approval_history) ? $report->approval_history : [],
            'canReview' => $this->formatCanReview($report),
            'canApprove' => $this->formatCanApprove($report),
            'canReject' => $this->formatCanReject($report),
            'timeline' => $history,
            'createdAt' => optional($report->created_at)->toIso8601String(),
            'updatedAt' => optional($report->updated_at)->toIso8601String(),
        ]);
    }

    private function isInspectionReport(Report $report): bool
    {
        return strtolower(trim((string) ($report->report_type ?? ''))) === 'inspection';
    }

    private function effectiveInspectionWorkflow(Report $report): array
    {
        return $this->isInspectionReport($report)
            ? $this->inspectionWorkflowService->effectiveWorkflow($report)
            : [];
    }

    private function formatWorkflowStage(Report $report): ?string
    {
        return $this->effectiveInspectionWorkflow($report)['workflow_stage'] ?? $report->workflow_stage;
    }

    private function formatWorkflowSnapshot(Report $report): array
    {
        return $this->effectiveInspectionWorkflow($report)['workflow_snapshot'] ?? (is_array($report->workflow_snapshot) ? $report->workflow_snapshot : []);
    }

    private function formatNextActionRole(Report $report): ?string
    {
        return $this->effectiveInspectionWorkflow($report)['next_action_role'] ?? $report->next_action_role;
    }

    private function formatScopeTeamId(Report $report): ?int
    {
        $teamId = $this->effectiveInspectionWorkflow($report)['scope_team_id'] ?? $report->scope_team_id;

        return $teamId !== null ? (int) $teamId : null;
    }

    private function formatCanReview(Report $report): bool
    {
        $user = request()?->user();
        return $this->isInspectionReport($report) && $user
            ? $this->inspectionWorkflowService->canReview($report, $user)
            : false;
    }

    private function formatCanApprove(Report $report): bool
    {
        $user = request()?->user();
        return $this->isInspectionReport($report) && $user
            ? $this->inspectionWorkflowService->canApprove($report, $user)
            : false;
    }

    private function formatCanReject(Report $report): bool
    {
        $user = request()?->user();
        return $this->isInspectionReport($report) && $user
            ? $this->inspectionWorkflowService->canReject($report, $user)
            : false;
    }

    private function emitWorkflowNotificationSafely(
        string $eventType,
        Report $report,
        mixed $actor,
        bool $actionRequired = false,
        ?string $remarks = null,
    ): void {
        try {
            $this->emitWorkflowNotification($eventType, $report, $actor, $actionRequired, $remarks);
        } catch (\Throwable $exception) {
            Log::warning('Report workflow notification dispatch failed.', [
                'report_uid' => $report->report_uid,
                'event_type' => $eventType,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function emitWorkflowNotification(
        string $eventType,
        Report $report,
        mixed $actor,
        bool $actionRequired = false,
        ?string $remarks = null,
    ): void {
        $module = 'report';
        $targetUserIds = [(int) $report->owner_user_id];
        $targetRoles = [];
        $excludeOwner = false;
        $workflowStage = strtolower($report->status) === 'submitted' ? 'review' : 'done';
        $nextActionRole = null;
        $scopeTeamId = null;

        if ($this->isInspectionReport($report)) {
            $workflow = $this->inspectionWorkflowService->effectiveWorkflow($report);
            $workflowStage = $workflow['workflow_stage'] ?? $workflowStage;
            $nextActionRole = $workflow['next_action_role'] ?? null;
            $scopeTeamId = $workflow['scope_team_id'] ?? null;
            if ($actionRequired) {
                $targetUserIds = $this->inspectionWorkflowService->recipientUserIdsForNextAction($report);
                $excludeOwner = true;
            } elseif (in_array($eventType, ['approved', 'rejected'], true)) {
                $targetUserIds = [(int) $report->owner_user_id];
            }
        }

        $this->notificationService->emit(
            module: $module,
            eventType: $eventType,
            recordType: 'report',
            recordId: (int) $report->id,
            recordDisplayId: (string) $report->display_id,
            ownerUserId: (int) $report->owner_user_id,
            actor: [
                'userId' => $actor?->id ?? null,
                'name' => $actor?->name ?? '',
                'email' => $actor?->email ?? '',
            ],
            targetRoles: $targetRoles,
            targetUserIds: $targetUserIds,
            actionRequired: $actionRequired,
            remarks: $remarks,
            metadata: [
                'module' => $module,
                'status' => $report->status,
                'workflowStage' => $workflowStage,
                'nextActionRole' => $nextActionRole,
                'scopeTeamId' => $scopeTeamId,
                'reportType' => $report->report_type,
                'reportUid' => $report->report_uid,
                'detailRouteKey' => $report->report_uid,
            ],
            excludeOwner: $excludeOwner,
        );
    }

    private function isSubmissionKeyDuplicateException(QueryException $exception): bool
    {
        $message = strtolower((string) $exception->getMessage());
        $errorInfo = is_array($exception->errorInfo ?? null) ? $exception->errorInfo : [];
        $sqlState = strtolower((string) ($errorInfo[0] ?? ''));
        $driverCode = (string) ($errorInfo[1] ?? '');
        if ($sqlState === '23000' && in_array($driverCode, ['1062', '2067'], true)) {
            return true;
        }
        return str_contains($message, 'reports_owner_submission_unique')
            || (str_contains($message, 'submission_key') && str_contains($message, 'duplicate'));
    }

    private function ensureInspectionPermission(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! $this->authorizationService->hasPermission($user, 'reports.manage|reports.inspection.view')) {
            abort(403, 'Forbidden');
        }
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

    private function validateInspectionHsePayload(array $payload): void
    {
        $inspectedBy = trim((string) ($payload['hseInspectedBy'] ?? $payload['hse_inspected_by'] ?? ''));
        $inspectionDate = trim((string) ($payload['hseInspectionDate'] ?? $payload['hse_inspection_date'] ?? ''));
        $selections = $this->normalizeInspectionHseSelections($payload['hseSelections'] ?? $payload['hse_selections'] ?? []);
        $severity = $this->normalizeInspectionHseSeverity($payload['hseSeverity'] ?? $payload['hse_severity'] ?? '', 'payload.hseSeverity');

        if ($inspectedBy === '') {
            throw ValidationException::withMessages([
                'payload.hseInspectedBy' => ['HSE inspected by is required.'],
            ]);
        }

        if ($inspectionDate === '') {
            throw ValidationException::withMessages([
                'payload.hseInspectionDate' => ['HSE inspection date is required.'],
            ]);
        }

        if ($selections === []) {
            throw ValidationException::withMessages([
                'payload.hseSelections' => ['Select Area Satisfactory or at least one HSE finding.'],
            ]);
        }

        if (in_array('areaSatisfactory', $selections, true) && count($selections) > 1) {
            throw ValidationException::withMessages([
                'payload.hseSelections' => ['Area Satisfactory cannot be combined with HSE findings.'],
            ]);
        }

        if (in_array('areaSatisfactory', $selections, true)) {
            $remarks = trim((string) ($payload['hseAreaConditionRemarks'] ?? $payload['hse_area_condition_remarks'] ?? ''));
            if ($remarks === '') {
                throw ValidationException::withMessages([
                    'payload.hseAreaConditionRemarks' => ['Area condition remarks are required for Area Satisfactory.'],
                ]);
            }

            return;
        }

        if ($severity === '') {
            throw ValidationException::withMessages([
                'payload.hseSeverity' => ['HSE severity is required when findings are selected.'],
            ]);
        }

        $detailFields = [
            'unsafeAct' => ['field' => 'hseUnsafeActDetails', 'snake' => 'hse_unsafe_act_details', 'message' => 'Unsafe act details are required.'],
            'unsafeCondition' => ['field' => 'hseUnsafeConditionDetails', 'snake' => 'hse_unsafe_condition_details', 'message' => 'Unsafe condition details are required.'],
            'environmental' => ['field' => 'hseEnvironmentalDetails', 'snake' => 'hse_environmental_details', 'message' => 'Environmental details are required.'],
        ];

        foreach ($detailFields as $selection => $meta) {
            if (! in_array($selection, $selections, true)) {
                continue;
            }

            if (trim((string) ($payload[$meta['field']] ?? $payload[$meta['snake']] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'payload.'.$meta['field'] => [$meta['message']],
                ]);
            }
        }
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
        $shift = trim((string) ($payload['frtShift'] ?? $payload['frt_shift'] ?? ''));

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

        if ($shift === '') {
            throw ValidationException::withMessages([
                'payload.frtShift' => ['FRT shift is required.'],
            ]);
        }
    }

    private function validateInspectionFrtSubmittedRoster(array $dailyRows, array $oneOffRows): void
    {
        $this->validateInspectionFrtCanonicalRows(
            rows: $dailyRows,
            fieldPath: 'payload.frtDailyChecks',
            expectedRows: FrtDailyReference::dailyRowMap(),
            expectedCountMessage: 'FRT daily checklist must include all 92 seeded rows.',
        );
        $this->validateInspectionFrtCanonicalRows(
            rows: $oneOffRows,
            fieldPath: 'payload.frtOneOffChecks',
            expectedRows: FrtDailyReference::oneOffRowMap(),
            expectedCountMessage: 'FRT one-off checklist must include all 46 seeded rows.',
        );
    }

    private function validateInspectionFrtCanonicalRows(
        array $rows,
        string $fieldPath,
        array $expectedRows,
        string $expectedCountMessage,
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

        if (count($seen) !== count($expectedRows)) {
            throw ValidationException::withMessages([
                $fieldPath => [$expectedCountMessage],
            ]);
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

    private function extractInspectionChecklistIndex(array $payload): array
    {
        $checklist = is_array($payload['checklist'] ?? null) ? $payload['checklist'] : [];
        $selectedRows = collect($checklist)
            ->filter(fn ($item) => is_array($item) && ($item['selected'] ?? true) !== false);

        $ids = $selectedRows
            ->map(fn ($item) => trim((string) ($item['id'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $labels = $selectedRows
            ->map(fn ($item) => trim((string) ($item['label'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'ids' => $ids,
            'labels' => $labels,
            'hasChecklist' => count($ids) > 0 || count($labels) > 0,
        ];
    }

    private function inspectionSummaryTimestamp(Report $report): ?string
    {
        $timestamp = $report->submitted_at ?: ($report->updated_at ?: $report->created_at);

        return $timestamp instanceof Carbon ? $timestamp->toIso8601String() : null;
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
            $this->validateInspectionFrtSessionMeta($payload);
            $dailyRows = $this->normalizeInspectionFrtDailyChecks(
                $payload['frtDailyChecks'] ?? $payload['frt_daily_checks'] ?? []
            );
            $oneOffRows = $this->normalizeInspectionFrtOneOffChecks(
                $payload['frtOneOffChecks'] ?? $payload['frt_one_off_checks'] ?? []
            );
            $this->validateInspectionFrtSubmittedRoster($dailyRows, $oneOffRows);
            $this->validateInspectionFrtDailyRows($dailyRows, 'payload.frtDailyChecks');
            $this->validateInspectionFrtOneOffRows($oneOffRows, 'payload.frtOneOffChecks');
        }

        if (array_key_exists('highAngleChecks', $payload) || array_key_exists('high_angle_checks', $payload)) {
            $rows = $this->normalizeInspectionHighAngleChecks(
                $payload['highAngleChecks'] ?? $payload['high_angle_checks']
            );
            $this->validateInspectionHighAngleSessionMeta($payload);
            $this->validateInspectionHighAngleRemarks($rows, 'payload.highAngleChecks');
        }

        if (array_key_exists('fireExtinguisherChecks', $payload) || array_key_exists('fire_extinguisher_checks', $payload)) {
            $rows = $this->normalizeInspectionFireExtinguisherChecks(
                $payload['fireExtinguisherChecks'] ?? $payload['fire_extinguisher_checks']
            );
            $this->validateInspectionFireExtinguisherSessionMeta($payload);
            $this->validateInspectionFireExtinguisherRows($rows, 'payload.fireExtinguisherChecks');
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

        if (
            $this->isHseInspectionType((string) ($payload['incidentType'] ?? $payload['inspectionType'] ?? ''))
            || array_key_exists('hseSelections', $payload)
            || array_key_exists('hse_selections', $payload)
        ) {
            $this->validateInspectionHsePayload($payload);
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
}
