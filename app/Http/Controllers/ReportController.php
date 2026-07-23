<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\ReportTimelineEntry;
use App\Jobs\ReconcileFitnessShadowRead;
use App\Services\FitnessShadowReadCutoverService;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\DrillPayloadService;
use App\Services\ErcoPayloadService;
use App\Services\InspectionCheckRowSyncService;
use App\Services\InspectionDutyConfirmationService;
use App\Services\InspectionDutyContextResolver;
use App\Services\InspectionPayloadService;
use App\Services\InspectionPolicy;
use App\Services\InspectionSessionReportPayloadBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\ReportDraftConsumptionService;
use App\Services\ReportModuleAdapter;
use App\Services\FitnessTestReportXlsxRenderer;
use App\Services\ReportingWorkflowService;
use App\Services\ReportMediaService;
use App\Services\ReportReadAuthorizationService;
use App\Services\ReportModuleRegistry;
use App\Services\RoleCatalog;
use App\Services\WorkflowNotificationService;
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
        private readonly ReportingWorkflowService $reportingWorkflowService,
        private readonly InspectionPayloadService $inspectionPayloadService,
        private readonly InspectionSessionReportPayloadBuilder $inspectionSessionReportPayloadBuilder,
        private readonly ReportMediaService $reportMediaService,
        private readonly ReportDraftConsumptionService $reportDraftConsumptionService,
        private readonly ReportReadAuthorizationService $reportReadAuthorizationService,
        private readonly DrillPayloadService $drillPayloadService,
        private readonly ErcoPayloadService $ercoPayloadService,
        private readonly InspectionDutyConfirmationService $dutyConfirmations,
        private readonly InspectionDutyContextResolver $dutyContextResolver,
        private readonly InspectionPolicy $inspectionPolicy,
        private readonly ReportModuleRegistry $reportModuleRegistry,
        private readonly FitnessTestReportXlsxRenderer $fitnessTestReportXlsxRenderer,
    ) {}

    private const STATUS_DRAFT = 'Draft';

    private const STATUS_SUBMITTED = 'Submitted';

    private const STATUS_REVIEWED = 'Reviewed';

    private const STATUS_APPROVED = 'Approved';

    private const STATUS_REJECTED = 'Rejected';

    private const STATUS_CANCELLED = 'Cancelled';

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $reportTypeFilter = $this->normalizeReportType($request->input('reportType', ''));
        $scope = strtolower(trim((string) $request->input('scope', 'mine')));
        $action = strtolower(trim((string) $request->input('action', '')));
        $isActionableScope = $scope === 'actionable';
        if ($isActionableScope && ! in_array($action, ['review', 'approve'], true)) {
            throw ValidationException::withMessages([
                'action' => ['Actionable report scope requires action=review or action=approve.'],
            ]);
        }
        if ($reportTypeFilter !== '' && $this->isManagedReportingWorkflowType($reportTypeFilter)) {
            $this->ensureReportingModulePermission($request, $reportTypeFilter);
        }
        $isAllManagedScope =
            in_array($scope, ['all', 'actionable'], true)
            && $this->isManagedReportingWorkflowType($reportTypeFilter)
            && $this->hasReportingModulePermission($user, $reportTypeFilter);

        $query = Report::query()->with('timelineEntries');
        if (! $isAllManagedScope) {
            $query->where('owner_user_id', $user->id);
        }

        if ($request->filled('reportType') && $request->input('reportType') !== 'All') {
            $query->where('report_type', trim((string) $request->input('reportType')));
        }
        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', trim((string) $request->input('status')));
        }
        if ($isActionableScope) {
            $query
                ->where('status', $action === 'review' ? self::STATUS_SUBMITTED : self::STATUS_REVIEWED)
                ->where(function ($builder) use ($action) {
                    $builder
                        ->where('workflow_stage', $action)
                        ->orWhereNull('workflow_stage');
                });
        }
        if ($reportTypeFilter === 'inspection') {
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
        if ($isActionableScope) {
            $rows = $rows->filter(fn (Report $report) => $action === 'review'
                ? $this->reportingWorkflowService->canReview($report, $user)
                : $this->reportingWorkflowService->canApprove($report, $user))->values();
        }

        return response()->json([
            'data' => $rows->map(fn (Report $report) => $this->formatReport($report)),
        ]);
    }

    public function show(Request $request, string $reportUid): JsonResponse
    {
        $report = $this->findReadableReport($request, $reportUid);

        return response()->json(['data' => $this->formatReport($report)]);
    }

    public function revisions(Request $request, string $reportUid): JsonResponse
    {
        $report = $this->findReadableReport($request, $reportUid);

        $revisions = $report->revisions()
            ->orderBy('revision')
            ->get()
            ->map(function ($revision) {
                return [
                    'revision' => (int) $revision->revision,
                    'schemaVersion' => (int) $revision->schema_version,
                    'createdBy' => $revision->created_by,
                    'createdAt' => optional($revision->created_at)->toIso8601String(),
                    'payload' => $revision->payload,
                    'payloadChecksum' => $revision->payload_checksum,
                ];
            });

        return response()->json([
            'data' => $revisions,
            'currentRevision' => (int) $report->revision,
            'currentVersion' => (int) $report->version,
        ]);
    }

    public function revision(Request $request, string $reportUid, int $revision): JsonResponse
    {
        $report = $this->findReadableReport($request, $reportUid);

        $revisionRecord = $report->revisions()->where('revision', $revision)->firstOrFail();

        return response()->json([
            'data' => [
                'reportUid' => (string) $report->report_uid,
                'reportType' => (string) $report->report_type,
                'revision' => (int) $revisionRecord->revision,
                'schemaVersion' => (int) $revisionRecord->schema_version,
                'createdBy' => $revisionRecord->created_by,
                'createdAt' => optional($revisionRecord->created_at)->toIso8601String(),
                'payload' => $revisionRecord->payload,
                'payloadChecksum' => $revisionRecord->payload_checksum,
            ],
        ]);
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
                if (! is_array($item) || ($item['selected'] ?? true) === false) {
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
                if (! isset($items[$id])) {
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
                if ($inspectionType !== '' && ! in_array($inspectionType, $items[$id]['inspectionTypes'], true)) {
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
            'source_draft_id' => ['nullable', 'string', 'max:80'],
            'display_id' => ['required', 'string', 'max:190'],
            'report_type' => ['required', 'string', 'max:64'],
            'payload' => ['required', 'array'],
            'status' => ['nullable', 'string', 'in:Draft,Submitted'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'submitted_at' => ['nullable', 'string'],
            'submittedAt' => ['nullable', 'string'],
            'inspected_at' => ['nullable', 'string'],
            'inspectedAt' => ['nullable', 'string'],
        ]);
        $data['payload'] = $this->stripServerManagedPayloadMetadata((array) $data['payload']);

        $status = (string) ($data['status'] ?? self::STATUS_SUBMITTED);
        $reportType = $this->normalizeReportType($data['report_type'] ?? '');
        $isInspection = $reportType === 'inspection';
        $isManagedWorkflow = $this->isManagedReportingWorkflowType($reportType);
        if ($isManagedWorkflow) {
            $this->ensureReportingModulePermission($request, $reportType);
        }
        if ($isInspection) {
            $this->ensureInspectionConductPermission($request);
        }
        $reportModuleAdapter = $this->moduleAdapterForType($reportType);
        if ($reportModuleAdapter === null) {
            if ($reportType === 'drill') {
                if ($status === self::STATUS_DRAFT) {
                    $this->drillPayloadService->validateForDraft((array) $data['payload']);
                } else {
                    $this->drillPayloadService->validateForSubmit((array) $data['payload']);
                }
            }
            if ($reportType === 'erco') {
                if ($status === self::STATUS_DRAFT) {
                    $this->ercoPayloadService->validateForDraft((array) $data['payload']);
                } else {
                    $this->ercoPayloadService->validateForSubmit((array) $data['payload']);
                }
            }
        } elseif ($status === self::STATUS_DRAFT) {
            $data['payload'] = $reportModuleAdapter->validateDraft((array) $data['payload']);
        } else {
            $data['payload'] = $reportModuleAdapter->validateSubmission((array) $data['payload']);
        }
        if ($isInspection) {
            $data['payload'] = $this->applyInspectionSessionInspector(
                (array) $data['payload'],
                $request
            );
            $this->inspectionPayloadService->validateForSubmit((array) $data['payload']);
            $data['payload'] = $this->inspectionPayloadService->normalize((array) $data['payload']);
        }
        $action = $status === self::STATUS_DRAFT ? 'DraftSaved' : 'Submitted';
        $submissionKey = trim((string) ($data['submission_key'] ?? ''));
        $sourceDraftId = trim((string) ($data['source_draft_id'] ?? ''));
        $checklistIndex = $isInspection
            ? $this->extractInspectionChecklistIndex((array) $data['payload'])
            : ['ids' => [], 'labels' => [], 'hasChecklist' => false];
        $workflowFields = [];
        if ($isManagedWorkflow) {
            if ($status === self::STATUS_SUBMITTED) {
                $submissionDecision = $isInspection ? $this->inspectionPolicy->canSubmit($user) : null;
                $blockReason = $submissionDecision !== null
                    ? ($submissionDecision->allowed ? null : $submissionDecision->message)
                    : $this->reportingWorkflowService->submissionBlockReason($user, $reportType);
                if ($blockReason !== null) {
                    throw ValidationException::withMessages(['workflow' => [$blockReason]]);
                }
            }
            $workflowFields = $status === self::STATUS_SUBMITTED
                ? $this->reportingWorkflowService->appendSubmissionHistory(
                    $this->reportingWorkflowService->buildWorkflowForSubmission($user, $reportType),
                    $user,
                    'Submitted',
                    (string) ($data['remarks'] ?? ''),
                )
                : $this->reportingWorkflowService->draftWorkflowFields();
        }

        if ($submissionKey !== '') {
            $existing = Report::query()
                ->where('owner_user_id', $user->id)
                ->where('submission_key', $submissionKey)
                ->with('timelineEntries')
                ->first();
            if ($existing instanceof Report) {
                $shouldConsumeSourceDraft = $status === self::STATUS_SUBMITTED;
                DB::transaction(fn () => $this->reportDraftConsumptionService->consumeOwnedDraft(
                    (int) $user->id,
                    $shouldConsumeSourceDraft ? $sourceDraftId : '',
                    $reportType,
                ));

                return response()->json([
                    'data' => array_merge($this->formatReport($existing), [
                        'idempotent_replay' => true,
                    ]),
                ]);
            }
        }

        $reportUid = trim((string) ($data['report_uid'] ?? '')) ?: Str::uuid()->toString();
        $dutyContext = $isInspection
            ? ($status === self::STATUS_SUBMITTED
                ? $this->dutyConfirmations->consume($request, 'submit', $reportUid, $this->inspectionFormId($data['payload']))
                : $this->dutyContextResolver->resolve($user))
            : null;

        $submittedAt = $status === self::STATUS_SUBMITTED
            ? $this->submittedAtForReportPayload($data, $isInspection)
            : null;

        try {
            $report = DB::transaction(function () use ($data, $status, $action, $submissionKey, $sourceDraftId, $user, $checklistIndex, $isInspection, $workflowFields, $submittedAt, $reportType, $reportUid, $dutyContext, $reportModuleAdapter) {
                $report = Report::create([
                    'report_uid' => $reportUid,
                    'display_id' => trim((string) $data['display_id']),
                    'submission_key' => $submissionKey !== '' ? $submissionKey : null,
                    'owner_user_id' => $user->id,
                    'report_type' => $this->normalizeReportType($data['report_type'] ?? ''),
                    'status' => $status,
                    'version' => 1,
                    'revision' => 1,
                    'payload' => $data['payload'],
                    'inspection_checklist_item_ids' => $checklistIndex['ids'],
                    'inspection_checklist_item_labels' => $checklistIndex['labels'],
                    'inspection_has_checklist' => $checklistIndex['hasChecklist'],
                    'submitted_at' => $submittedAt,
                    ...$this->dutyContextFields($dutyContext),
                ] + $workflowFields);

                $this->appendTimeline(
                    report: $report,
                    action: $action,
                    fromStatus: null,
                    toStatus: $status,
                    userId: (int) $user->id,
                    byName: (string) $user->name,
                    remarks: (string) ($data['remarks'] ?? ''),
                    meta: $isInspection ? $this->inspectionWorkflowActorMeta($user) : null,
                );

            if ($isInspection) {
                $report->refresh();
                $this->inspectionCheckRowSyncService->syncForReport($report, (int) $user->id);
            }
            if ($reportModuleAdapter !== null) {
                $reportModuleAdapter->project($report, (array) $report->payload);
            }
            $this->reportMediaService->syncPayloadLinks(
                (array) $report->payload,
                'report',
                (string) $report->report_uid,
                (int) $user->id,
                $reportType,
            );
            if ($status === self::STATUS_SUBMITTED) {
                $this->reportDraftConsumptionService->consumeOwnedDraft(
                    (int) $user->id,
                    $sourceDraftId,
                    $reportType,
                );
            }

            return $report->load('timelineEntries');
        });
        $this->dispatchFitnessShadowReconciliation($report);
        } catch (QueryException $exception) {
            if ($submissionKey !== '' && $this->isSubmissionKeyDuplicateException($exception)) {
                $existing = Report::query()
                    ->where('owner_user_id', $user->id)
                    ->where('submission_key', $submissionKey)
                    ->with('timelineEntries')
                    ->first();
                if ($existing instanceof Report) {
                    $shouldConsumeSourceDraft = $status === self::STATUS_SUBMITTED;
                    DB::transaction(fn () => $this->reportDraftConsumptionService->consumeOwnedDraft(
                        (int) $user->id,
                        $shouldConsumeSourceDraft ? $sourceDraftId : '',
                        $reportType,
                    ));

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
            'duty_confirmation_token_id' => data_get($report->duty_context_snapshot, 'confirmationTokenId'),
        ]);
        $this->emitWorkflowNotificationSafely(
            eventType: $status === self::STATUS_DRAFT ? 'edited' : 'submitted',
            report: $report,
            actor: $user,
            actionRequired: $isManagedWorkflow && $status === self::STATUS_SUBMITTED,
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
        $report = $this->findEditableReport($request, $reportUid);
        $data = $request->validate([
            'payload' => ['required', 'array'],
            'source_draft_id' => ['nullable', 'string', 'max:80'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'in:Submitted,Draft'],
            'submitted_at' => ['nullable', 'string'],
            'submittedAt' => ['nullable', 'string'],
            'inspected_at' => ['nullable', 'string'],
            'inspectedAt' => ['nullable', 'string'],
        ]);
        $data['payload'] = $this->stripServerManagedPayloadMetadata((array) $data['payload']);

        if ((int) $data['version'] !== (int) $report->version) {
            return response()->json([
                'message' => 'Version conflict. Reload the latest report before updating.',
                'code' => 'REPORT_VERSION_CONFLICT',
                'currentVersion' => $report->version,
                'currentReport' => $this->formatReport($report->load('timelineEntries')),
            ], 409);
        }

        $targetStatus = (string) ($data['status'] ?? self::STATUS_SUBMITTED);
        $reportType = $this->normalizeReportType($report->report_type ?? '');
        $isInspection = $reportType === 'inspection';
        $isManagedWorkflow = $this->isManagedReportingWorkflowType($reportType);
        $isSystemAdministrator = $this->isSystemAdministrator($user);
        $reportModuleAdapter = $this->moduleAdapterForType($reportType);
        if ($reportModuleAdapter === null) {
            if ($reportType === 'drill') {
                if ($targetStatus === self::STATUS_DRAFT) {
                    $this->drillPayloadService->validateForDraft((array) $data['payload']);
                } else {
                    $this->drillPayloadService->validateForSubmit((array) $data['payload']);
                }
            }
            if ($reportType === 'erco') {
                if ($targetStatus === self::STATUS_DRAFT) {
                    $this->ercoPayloadService->validateForDraft((array) $data['payload']);
                } else {
                    $this->ercoPayloadService->validateForSubmit((array) $data['payload']);
                }
            }
        } elseif ($targetStatus === self::STATUS_DRAFT) {
            $data['payload'] = $reportModuleAdapter->validateDraft((array) $data['payload']);
        } else {
            $data['payload'] = $reportModuleAdapter->validateSubmission((array) $data['payload']);
        }
        if ($isInspection) {
            $this->ensureInspectionConductPermission($request);
            $existingPayload = is_array($report->payload) ? $report->payload : [];
            $isSessionFireExtinguisher = $this->inspectionSessionReportPayloadBuilder
                ->isSessionFireExtinguisherPayload($existingPayload);
            $data['payload'] = $this->applyInspectionSessionInspector(
                (array) $data['payload'],
                $request
            );
            if ($isSessionFireExtinguisher) {
                $inspectionType = (string) (
                    $existingPayload['incidentType']
                    ?? $existingPayload['inspectionType']
                    ?? 'Fire Extinguisher Inspection'
                );
                $data['payload']['incidentType'] = $inspectionType;
                $data['payload']['inspectionType'] = $inspectionType;
                $data['payload']['inspectionSessionUid'] = (string) (
                    $existingPayload['inspectionSessionUid']
                    ?? $existingPayload['inspection_session_uid']
                    ?? ''
                );
            }
            $this->inspectionPayloadService->validateForSubmit((array) $data['payload']);
            $data['payload'] = $this->inspectionPayloadService->normalize((array) $data['payload']);
            if ($isSessionFireExtinguisher) {
                $data['payload']['incidentType'] = $inspectionType;
                $data['payload']['inspectionType'] = $inspectionType;
                $data['payload']['inspectionSessionUid'] = (string) (
                    $existingPayload['inspectionSessionUid']
                    ?? $existingPayload['inspection_session_uid']
                    ?? ''
                );
                $data['payload'] = $this->inspectionSessionReportPayloadBuilder
                    ->normalizeDerivedFields((array) $data['payload']);
            }
        }
        if (
            ! ($isInspection && $isSystemAdministrator)
            && ! in_array($report->status, [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_REJECTED], true)
        ) {
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
        if ($isManagedWorkflow) {
            if ($targetStatus === self::STATUS_SUBMITTED) {
                $submissionDecision = $isInspection ? $this->inspectionPolicy->canSubmit($user) : null;
                $blockReason = $submissionDecision !== null
                    ? ($submissionDecision->allowed ? null : $submissionDecision->message)
                    : $this->reportingWorkflowService->submissionBlockReason($user, $reportType);
                if ($blockReason !== null) {
                    throw ValidationException::withMessages(['workflow' => [$blockReason]]);
                }
            }
            $workflowFields = $targetStatus === self::STATUS_SUBMITTED
                ? $this->reportingWorkflowService->appendSubmissionHistory(
                    $this->reportingWorkflowService->buildWorkflowForSubmission($user, $reportType),
                    $user,
                    'Resubmitted',
                    (string) ($data['remarks'] ?? ''),
                )
                : $this->reportingWorkflowService->draftWorkflowFields();
        }

        $submittedAt = $targetStatus === self::STATUS_SUBMITTED
            ? $this->submittedAtForReportPayload($data, $isInspection)
            : $report->submitted_at;

        $dutyContext = $isInspection
            ? ($targetStatus === self::STATUS_SUBMITTED
                ? $this->dutyConfirmations->consume($request, 'submit', $report->report_uid, $this->inspectionFormId($data['payload']))
                : $this->dutyContextResolver->resolve($user))
            : null;

        $sourceDraftId = trim((string) ($data['source_draft_id'] ?? ''));
        DB::transaction(function () use ($report, $data, $sourceDraftId, $targetStatus, $nextRevision, $nextVersion, $user, $checklistIndex, $isInspection, $workflowFields, $submittedAt, $reportType, $dutyContext, $reportModuleAdapter) {
            $fromStatus = $report->status;
            $report->update([
                'payload' => $data['payload'],
                'inspection_checklist_item_ids' => $checklistIndex['ids'],
                'inspection_checklist_item_labels' => $checklistIndex['labels'],
                'inspection_has_checklist' => $checklistIndex['hasChecklist'],
                'status' => $targetStatus,
                'revision' => $nextRevision,
                'version' => $nextVersion,
                'submitted_at' => $submittedAt,
                'reviewed_at' => null,
                'approved_at' => null,
                'rejected_at' => null,
                ...$this->dutyContextFields($dutyContext),
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
                meta: $isInspection ? $this->inspectionWorkflowActorMeta($user) : null,
            );

            if ($isInspection) {
                $report->refresh();
                $this->inspectionCheckRowSyncService->syncForReport($report, (int) $user->id);
            }
            if ($reportModuleAdapter !== null) {
                $reportModuleAdapter->project($report, (array) $report->payload);
            }
            $this->reportMediaService->syncPayloadLinks((array) $report->payload, 'report', (string) $report->report_uid, (int) $user->id, $reportType);
            if ($targetStatus === self::STATUS_SUBMITTED) {
                $this->reportDraftConsumptionService->consumeOwnedDraft(
                    (int) $user->id,
                    $sourceDraftId,
                    $reportType,
                );
            }
        });

        $report->load('timelineEntries');
        $this->dispatchFitnessShadowReconciliation($report);
        AuditLogger::log($request, 'report_updated', $user, [
            'report_uid' => $report->report_uid,
            'display_id' => $report->display_id,
            'status' => $report->status,
            'version' => $report->version,
            'revision' => $report->revision,
            'duty_confirmation_token_id' => data_get($report->duty_context_snapshot, 'confirmationTokenId'),
        ]);
        $this->emitWorkflowNotificationSafely(
            eventType: $targetStatus === self::STATUS_SUBMITTED ? 'submitted' : 'edited',
            report: $report,
            actor: $user,
            actionRequired: $isManagedWorkflow && $targetStatus === self::STATUS_SUBMITTED,
            remarks: (string) ($data['remarks'] ?? ''),
        );

        return response()->json(['data' => $this->formatReport($report)]);
    }

    public function destroy(Request $request, string $reportUid): JsonResponse
    {
        $user = $request->user();
        $report = $this->findDeletableReport($request, $reportUid);
        if ($this->isInspectionReport($report)) {
            $this->ensureInspectionConductPermission($request);
        }
        $dutyContext = null;
        if ($this->isInspectionReport($report) && $report->status !== self::STATUS_DRAFT) {
            $dutyContext = $this->dutyConfirmations->consume(
                $request,
                'delete',
                $report->report_uid,
                $this->inspectionFormId((array) $report->payload),
            );
        }

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
            $this->reportMediaService->removeParentLinks('report', (string) $report->report_uid);
            $report->delete();
        });

        AuditLogger::log($request, 'report_deleted', $user, [
            'report_uid' => $report->report_uid,
            'display_id' => $report->display_id,
            'duty_confirmation_token_id' => data_get($dutyContext, 'confirmationTokenId'),
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

    public function restore(Request $request, string $reportUid): JsonResponse
    {
        $user = $request->user();
        $report = $this->findRestorableReport($request, $reportUid);
        if (! $report->trashed()) {
            return response()->json(['message' => 'Report is already active.'], 200);
        }

        $dutyContext = null;
        if ($this->isInspectionReport($report) && $report->status !== self::STATUS_DRAFT) {
            $dutyContext = $this->dutyConfirmations->consume(
                $request,
                'restore',
                $report->report_uid,
                $this->inspectionFormId((array) $report->payload),
            );
        }

        DB::transaction(function () use ($report, $user): void {
            $report->restore();
            $this->appendTimeline(
                report: $report,
                action: 'Restored',
                fromStatus: $report->status,
                toStatus: $report->status,
                userId: (int) $user->id,
                byName: (string) $user->name,
                remarks: 'Report restored.',
            );
            $this->reportMediaService->syncPayloadLinks(
                (array) $report->payload,
                'report',
                (string) $report->report_uid,
                (int) $user->id,
                (string) $report->report_type,
            );
        });

        AuditLogger::log($request, 'report_restored', $user, [
            'report_uid' => $report->report_uid,
            'display_id' => $report->display_id,
            'report_type' => $report->report_type,
            'status' => $report->status,
            'duty_confirmation_token_id' => data_get($dutyContext, 'confirmationTokenId'),
        ]);
        $this->emitWorkflowNotificationSafely(
            eventType: 'restored',
            report: $report,
            actor: $user,
            actionRequired: false,
            remarks: 'Report restored.',
        );

        $report->refresh();
        $report->load('timelineEntries');

        return response()->json([
            'data' => $this->formatReport($report),
        ]);
    }

    public function exportFitness(Request $request)
    {
        $validated = $request->validate([
            'report_uid' => ['required', 'string', 'max:190'],
            'format' => ['nullable', 'string', 'max:32'],
        ]);

        $reportUid = trim((string) ($validated['report_uid'] ?? ''));
        $format = strtolower(trim((string) ($validated['format'] ?? 'json')));
        $user = $request->user();
        if (! $user
            || ! $this->reportReadAuthorizationService->canViewModule($user, 'fitness-test')
            || ! $this->authorizationService->hasPermission($user, 'reports.fitness.export|reports.fitness.manage|reports.manage')) {
            abort(403, 'Forbidden');
        }
        if (! in_array($format, ['json', 'html', 'pdf', 'xlsx'], true)) {
            return response()->json([
                'message' => 'Unsupported fitness export format.',
                'code' => 'REPORT_EXPORT_FORMAT_UNSUPPORTED',
            ], 422);
        }

        $report = Report::query()
            ->with('timelineEntries')
            ->where('report_uid', $reportUid)
            ->where('report_type', 'fitness-test')
            ->first();
        if (! $report) {
            return response()->json(['message' => 'Report not found.'], 404);
        }
        if (strtolower((string) $report->status) === strtolower(self::STATUS_DRAFT)) {
            return response()->json([
                'message' => 'Fitness export is unavailable until the report is submitted.',
                'code' => 'REPORT_EXPORT_UNAVAILABLE',
            ], 422);
        }

        $adapter = $this->moduleAdapterForType('fitness-test');
        if ($adapter === null) {
            return response()->json([
                'message' => 'Fitness export is unavailable for this report.',
                'code' => 'REPORT_EXPORT_UNSUPPORTED',
            ], 422);
        }

        $basePayload = $adapter->serialize($report);
        if (! is_array($basePayload)) {
            return response()->json([
                'message' => 'Fitness export payload is invalid.',
                'code' => 'REPORT_EXPORT_UNAVAILABLE',
            ], 422);
        }

        $displayId = trim((string) ($report->display_id ?? 'fitness-test-report'));
        $safeId = preg_replace('/[^A-Za-z0-9\\-_]/', '-', $displayId);
        $safeId = trim((string) $safeId, '-');
        $filenameBase = $safeId !== '' ? $safeId : 'fitness-test-report';

        if ($format === 'json') {
            $exportPayload = $adapter->generateExport($report, $format);
            if (! is_array($exportPayload)) {
                return response()->json([
                    'message' => 'This report format is not supported for export.',
                    'code' => 'REPORT_EXPORT_UNAVAILABLE',
                ], 422);
            }
            $json = json_encode($exportPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return response()->json([
                    'message' => 'Unable to encode fitness report export.',
                    'code' => 'REPORT_EXPORT_ENCODING_ERROR',
                ], 500);
            }
            $filename = $filenameBase.'.json';

            AuditLogger::log($request, 'report_exported', $user, [
                'report_uid' => $report->report_uid,
                'report_type' => $report->report_type,
                'report_version' => (int) $report->version,
                'report_status' => $report->status,
                'owner_user_id' => (int) $report->owner_user_id,
                'export_format' => $format,
            ]);

            return response($json, 200, [
                'Content-Type' => 'application/json',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename),
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
                'X-Report-Version' => (string) $report->version,
                'Content-Length' => strlen($json),
            ]);
        }

        $basePayload['id'] = (string) $report->report_uid;
        $basePayload['displayId'] = (string) $report->display_id;
        $basePayload['reportType'] = (string) $report->report_type;
        $basePayload['status'] = (string) $report->status;
        $basePayload['version'] = (int) $report->version;
        $basePayload['revision'] = (int) $report->revision;
        $basePayload['submittedAt'] = optional($report->submitted_at)->toIso8601String();
        $basePayload['reviewedAt'] = optional($report->reviewed_at)->toIso8601String();
        $basePayload['approvedAt'] = optional($report->approved_at)->toIso8601String();
        $basePayload['rejectedAt'] = optional($report->rejected_at)->toIso8601String();

        if ($format === 'html') {
            $html = view('pdf.fitness_test_report', ['record' => $basePayload])->render();
            $filename = $filenameBase.'.html';
            AuditLogger::log($request, 'report_exported', $user, [
                'report_uid' => $report->report_uid,
                'report_type' => $report->report_type,
                'report_version' => (int) $report->version,
                'report_status' => $report->status,
                'owner_user_id' => (int) $report->owner_user_id,
                'export_format' => $format,
            ]);

            return response($html, 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Disposition' => 'inline; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename),
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
                'X-Report-Version' => (string) $report->version,
                'Content-Length' => strlen($html),
            ]);
        }

        if ($format === 'xlsx') {
            $xlsx = $this->fitnessTestReportXlsxRenderer->render($basePayload);
            if ($xlsx === '') {
                return response()->json([
                    'message' => 'Unable to generate fitness report XLSX export.',
                    'code' => 'REPORT_EXPORT_ENCODING_ERROR',
                ], 500);
            }
            $filename = $filenameBase.'.xlsx';

            AuditLogger::log($request, 'report_exported', $user, [
                'report_uid' => $report->report_uid,
                'report_type' => $report->report_type,
                'report_version' => (int) $report->version,
                'report_status' => $report->status,
                'owner_user_id' => (int) $report->owner_user_id,
                'export_format' => $format,
            ]);

            return response($xlsx, 200, [
                'Content-Type' => FitnessTestReportXlsxRenderer::CONTENT_TYPE,
                'Content-Disposition' => 'attachment; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename),
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
                'X-Report-Version' => (string) $report->version,
                'Content-Length' => strlen($xlsx),
            ]);
        }

        $document = Pdf::loadView('pdf.fitness_test_report', ['record' => $basePayload])->setPaper('a4')->setOption([
            'defaultFont' => 'Helvetica',
            'isFontSubsettingEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => false,
        ]);
        $output = $document->output(['compress' => 1]);
        $filename = $filenameBase.'.pdf';

        AuditLogger::log($request, 'report_exported', $user, [
            'report_uid' => $report->report_uid,
            'report_type' => $report->report_type,
            'report_version' => (int) $report->version,
            'report_status' => $report->status,
            'owner_user_id' => (int) $report->owner_user_id,
            'export_format' => $format,
        ]);

        return response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Report-Version' => (string) $report->version,
            'Content-Length' => strlen($output),
        ]);
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
        $reportType = $this->normalizeReportType($report->report_type ?? '');
        $isInspection = $reportType === 'inspection';
        $isManagedWorkflow = $this->isManagedReportingWorkflowType($reportType);
        if ($isManagedWorkflow) {
            $this->ensureReportingModulePermission($request, $reportType);
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
                );
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
        if (! in_array($report->status, $allowedFrom, true)) {
            return response()->json([
                'message' => "Invalid transition from status {$report->status} via {$action}.",
                'code' => 'REPORT_INVALID_TRANSITION',
                'fromStatus' => $report->status,
                'action' => $action,
            ], 409);
        }
        if ($isManagedWorkflow) {
            $workflowAction = match ($toStatus) {
                self::STATUS_REVIEWED => 'review',
                self::STATUS_APPROVED => 'approve',
                self::STATUS_REJECTED => 'reject',
                default => '',
            };
            $decision = $isInspection
                ? $this->inspectionPolicy->canTransition($report, $user, $workflowAction)
                : null;
            $authorizationError = $decision !== null
                ? ($decision->allowed ? null : $decision->message)
                : $this->reportingWorkflowService->authorizeAction($report, $user, $workflowAction);
            if ($authorizationError !== null) {
                return response()->json([
                    'message' => $authorizationError,
                    'code' => $decision?->reasonCode ?? 'REPORTING_WORKFLOW_FORBIDDEN',
                ], 403);
            }
        }

        $dutyContext = $isInspection
            ? $this->dutyConfirmations->consume(
                $request,
                match ($toStatus) {
                    self::STATUS_REVIEWED => 'review',
                    self::STATUS_APPROVED => 'approve',
                    self::STATUS_REJECTED => 'reject',
                    default => '',
                },
                $report->report_uid,
                $this->inspectionFormId((array) $report->payload),
            )
            : null;

        DB::transaction(function () use ($report, $toStatus, $action, $payload, $user, $isInspection, $isManagedWorkflow, $dutyContext) {
            $fromStatus = $report->status;
            $nextVersion = (int) $report->version + 1;
            $update = [
                'status' => $toStatus,
                'version' => $nextVersion,
                ...$this->dutyContextFields($dutyContext),
            ];
            if ($isManagedWorkflow) {
                $workflowAction = match ($toStatus) {
                    self::STATUS_REVIEWED => 'review',
                    self::STATUS_APPROVED => 'approve',
                    self::STATUS_REJECTED => 'reject',
                    default => '',
                };
                $update = array_merge(
                    $update,
                    $this->reportingWorkflowService->advanceWorkflow(
                        $report,
                        $workflowAction,
                        $user,
                        (string) ($payload['remarks'] ?? ''),
                    ),
                );
            }
            if ($toStatus === self::STATUS_REVIEWED) {
                $update['reviewed_at'] = now();
            }
            if ($toStatus === self::STATUS_APPROVED) {
                $update['approved_at'] = now();
            }
            if ($toStatus === self::STATUS_REJECTED) {
                $update['rejected_at'] = now();
            }
            $report->update($update);

            $this->appendTimeline(
                report: $report,
                action: $action,
                fromStatus: $fromStatus,
                toStatus: $toStatus,
                userId: (int) $user->id,
                byName: (string) $user->name,
                remarks: (string) ($payload['remarks'] ?? ''),
                meta: $isInspection ? $this->inspectionWorkflowActorMeta($user) : null,
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
            'duty_confirmation_token_id' => data_get($report->duty_context_snapshot, 'confirmationTokenId'),
        ]);
        $this->emitWorkflowNotificationSafely(
            eventType: strtolower($action),
            report: $report,
            actor: $user,
            actionRequired: $isManagedWorkflow && $toStatus === self::STATUS_REVIEWED,
            remarks: (string) ($payload['remarks'] ?? ''),
        );

        return response()->json(['data' => $this->formatReport($report)]);
    }

    private function findEditableReport(Request $request, string $reportUid): Report
    {
        $user = $request->user();
        $report = Report::query()
            ->where('report_uid', $reportUid)
            ->with('timelineEntries')
            ->firstOrFail();
        $reportType = $this->normalizeReportType($report->report_type ?? '');
        if ($this->isManagedReportingWorkflowType($reportType)) {
            $this->ensureReportingModulePermission($request, $reportType);
        }
        if ($reportType === 'inspection') {
            $decision = $this->inspectionPolicy->canEdit($report, $user, $this->isSystemAdministrator($user));
            if ($decision->allowed) {
                return $report;
            }
            abort(404);
        }
        if ((int) $report->owner_user_id !== (int) $user->id) {
            abort(404);
        }

        return $report;
    }

    private function findDeletableReport(Request $request, string $reportUid): Report
    {
        $user = $request->user();
        $report = Report::query()
            ->where('report_uid', $reportUid)
            ->with('timelineEntries')
            ->firstOrFail();

        $reportType = $this->normalizeReportType($report->report_type ?? '');
        if ($this->isManagedReportingWorkflowType($reportType)) {
            $this->ensureReportingModulePermission($request, $reportType);
        }
        if ($reportType === 'inspection') {
            $decision = $this->inspectionPolicy->canDelete($report, $user, $this->isSystemAdministrator($user));
            if ($decision->allowed) {
                return $report;
            }
            abort(404);
        }

        if ((int) $report->owner_user_id !== (int) $user->id) {
            abort(404);
        }

        return $report;
    }

    private function findRestorableReport(Request $request, string $reportUid): Report
    {
        $user = $request->user();
        $report = Report::query()
            ->withTrashed()
            ->where('report_uid', $reportUid)
            ->with('timelineEntries')
            ->firstOrFail();

        $reportType = $this->normalizeReportType($report->report_type ?? '');
        if ($this->isManagedReportingWorkflowType($reportType)) {
            $this->ensureReportingModulePermission($request, $reportType);
        }
        if ($reportType === 'inspection') {
            $decision = $this->inspectionPolicy->canDelete($report, $user, $this->isSystemAdministrator($user));
            if ($decision->allowed) {
                return $report;
            }
            abort(404);
        }

        if ((int) $report->owner_user_id !== (int) $user->id) {
            abort(404);
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

        $reportType = $this->normalizeReportType($report->report_type ?? '');
        if ((int) $report->owner_user_id === (int) $user->id) {
            return $report;
        }

        if ($this->isManagedReportingWorkflowType($reportType)) {
            $this->ensureReportingModulePermission($request, $reportType);

            return $report;
        }

        abort(404);
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
        ?array $meta = null,
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
            'meta' => $meta,
        ]);
    }

    private function formatReport(Report $report): array
    {
        $payload = $this->reportPayloadForModule($report);
        $canReview = $this->formatCanReview($report);
        $canApprove = $this->formatCanApprove($report);
        $canReject = $this->formatCanReject($report);
        $canDownloadPdf = $this->formatCanDownloadPdf($report);
        $canEdit = $this->formatCanEdit($report);
        $canDelete = $this->formatCanDelete($report);
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
            'dutyContextStatus' => $report->duty_context_status,
            'dutyContextVersion' => $report->duty_context_version,
            'dutySourceVersion' => $report->duty_source_version,
            'approvalHistory' => is_array($report->approval_history) ? $report->approval_history : [],
            'canReview' => $canReview,
            'canApprove' => $canApprove,
            'canReject' => $canReject,
            'canDownloadPdf' => $canDownloadPdf,
            'recordActionsVersion' => 1,
            'recordActions' => $this->formatRecordActions(
                $report,
                $canEdit,
                $canDelete,
                $canReview,
                $canApprove,
                $canReject,
                $canDownloadPdf,
            ),
            'timeline' => $history,
            'createdAt' => optional($report->created_at)->toIso8601String(),
            'updatedAt' => optional($report->updated_at)->toIso8601String(),
        ]);
    }

    private function reportPayloadForModule(Report $report): array
    {
        $adapter = $this->moduleAdapterForType((string) ($report->report_type ?? ''));
        if ($adapter === null) {
            return is_array($report->payload) ? $report->payload : [];
        }
        $reportType = strtolower(trim((string) $report->report_type));
        if ($reportType === 'fitness-test' && (! (bool) config('features.fitness_shadow_reads_enabled', false) || ! app(FitnessShadowReadCutoverService::class)->isReady())) {
            return $adapter->serializeForLegacyReads($report);
        }

        return $adapter->serialize($report);
    }

    private function dispatchFitnessShadowReconciliation(Report $report): void
    {
        if (strtolower(trim((string) $report->report_type)) !== 'fitness-test') {
            return;
        }
        if ($report->id === null) {
            return;
        }

        try {
            ReconcileFitnessShadowRead::dispatch((int) $report->id)->afterCommit();
        } catch (\Throwable $exception) {
            Log::warning('Failed to dispatch fitness shadow reconciliation job.', [
                'report_uid' => $report->report_uid,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function moduleAdapterForType(string $reportType): ?ReportModuleAdapter
    {
        return $this->reportModuleRegistry->for($reportType);
    }

    private function stripServerManagedPayloadMetadata(array $payload): array
    {
        foreach ([
            'recordActionsVersion',
            'recordActions',
            'record_actions_version',
            'record_actions',
            'canReview',
            'canApprove',
            'canReject',
            'canDownloadPdf',
            'can_review',
            'can_approve',
            'can_reject',
            'can_download_pdf',
        ] as $field) {
            unset($payload[$field]);
        }

        return $payload;
    }

    private function isInspectionReport(Report $report): bool
    {
        return $this->normalizeReportType($report->report_type ?? '') === 'inspection';
    }

    private function effectiveReportingWorkflow(Report $report): array
    {
        return $this->isManagedReportingWorkflowType($report->report_type ?? '')
            ? $this->reportingWorkflowService->effectiveWorkflow($report)
            : [];
    }

    private function formatWorkflowStage(Report $report): ?string
    {
        return $this->effectiveReportingWorkflow($report)['workflow_stage'] ?? $report->workflow_stage;
    }

    private function formatWorkflowSnapshot(Report $report): array
    {
        return $this->effectiveReportingWorkflow($report)['workflow_snapshot'] ?? (is_array($report->workflow_snapshot) ? $report->workflow_snapshot : []);
    }

    private function formatNextActionRole(Report $report): ?string
    {
        return $this->effectiveReportingWorkflow($report)['next_action_role'] ?? $report->next_action_role;
    }

    private function formatScopeTeamId(Report $report): ?int
    {
        $teamId = $this->effectiveReportingWorkflow($report)['scope_team_id'] ?? $report->scope_team_id;

        return $teamId !== null ? (int) $teamId : null;
    }

    private function formatCanReview(Report $report): bool
    {
        $user = request()?->user();

        return $this->isManagedReportingWorkflowType($report->report_type ?? '')
            && $user
            && $this->hasReportingModulePermission($user, (string) $report->report_type)
            ? ($this->isInspectionReport($report)
                ? $this->inspectionPolicy->canTransition($report, $user, 'review')->allowed
                : $this->reportingWorkflowService->canReview($report, $user))
            : false;
    }

    private function formatCanApprove(Report $report): bool
    {
        $user = request()?->user();

        return $this->isManagedReportingWorkflowType($report->report_type ?? '')
            && $user
            && $this->hasReportingModulePermission($user, (string) $report->report_type)
            ? ($this->isInspectionReport($report)
                ? $this->inspectionPolicy->canTransition($report, $user, 'approve')->allowed
                : $this->reportingWorkflowService->canApprove($report, $user))
            : false;
    }

    private function formatCanReject(Report $report): bool
    {
        $user = request()?->user();

        return $this->isManagedReportingWorkflowType($report->report_type ?? '')
            && $user
            && $this->hasReportingModulePermission($user, (string) $report->report_type)
            ? ($this->isInspectionReport($report)
                ? $this->inspectionPolicy->canTransition($report, $user, 'reject')->allowed
                : $this->reportingWorkflowService->canReject($report, $user))
            : false;
    }

    private function formatCanDownloadPdf(Report $report): bool
    {
        $user = request()?->user();

        return $user
            ? $this->reportReadAuthorizationService->canDownloadPdf($user, $report)
            : false;
    }

    private function formatCanEdit(Report $report): bool
    {
        $user = request()?->user();
        if (! $user) {
            return false;
        }
        $reportType = $this->normalizeReportType($report->report_type ?? '');
        if ($this->isManagedReportingWorkflowType($reportType)
            && ! $this->hasReportingModulePermission($user, $reportType)) {
            return false;
        }

        if ($this->isInspectionReport($report)) {
            return $this->inspectionPolicy
                ->canEdit($report, $user, $this->isSystemAdministrator($user))
                ->allowed;
        }

        return (int) $report->owner_user_id === (int) $user->id
            && in_array($report->status, [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_REJECTED], true);
    }

    private function formatCanDelete(Report $report): bool
    {
        $user = request()?->user();
        if (! $user) {
            return false;
        }
        $reportType = $this->normalizeReportType($report->report_type ?? '');
        if ($this->isManagedReportingWorkflowType($reportType)
            && ! $this->hasReportingModulePermission($user, $reportType)) {
            return false;
        }

        if ($this->isInspectionReport($report)) {
            return $this->inspectionPolicy
                ->canDelete($report, $user, $this->isSystemAdministrator($user))
                ->allowed;
        }

        return (int) $report->owner_user_id === (int) $user->id;
    }

    private function formatRecordActions(
        Report $report,
        bool $canEdit,
        bool $canDelete,
        bool $canReview,
        bool $canApprove,
        bool $canReject,
        bool $canDownloadPdf,
    ): array {
        $reportType = $this->normalizeReportType($report->report_type ?? '');
        $status = (string) $report->status;
        $isFitnessExport = $reportType === 'fitness-test'
            && $status !== self::STATUS_DRAFT;
        $canExportFitnessData = $isFitnessExport
            && request()?->user()
            && $this->reportReadAuthorizationService->canViewModule(request()->user(), $reportType);
        $hasPdfExport = in_array($reportType, ['inspection', 'erco', 'drill'], true)
            && $status !== self::STATUS_DRAFT;

        return [
            'view' => $this->formatActionCapability(true, true),
            'download' => $this->formatActionCapability(
                $hasPdfExport || $isFitnessExport,
                $hasPdfExport ? $canDownloadPdf : (bool) $canExportFitnessData,
                ($hasPdfExport && ! $canDownloadPdf) || ($isFitnessExport && ! $canExportFitnessData)
                    ? 'download_forbidden'
                    : null,
                ['format' => $isFitnessExport ? 'json' : 'pdf'],
            ),
            'edit' => $this->formatActionCapability(
                in_array($status, [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_REJECTED], true)
                    || ($this->isInspectionReport($report) && $this->isSystemAdministrator(request()?->user())),
                $canEdit,
                $canEdit ? null : 'edit_forbidden',
            ),
            'review' => $this->formatActionCapability(
                $status === self::STATUS_SUBMITTED,
                $canReview,
                $canReview ? null : 'review_forbidden',
            ),
            'approve' => $this->formatActionCapability(
                $status === self::STATUS_REVIEWED,
                $canApprove,
                $canApprove ? null : 'approve_forbidden',
            ),
            'reject' => $this->formatActionCapability(
                in_array($status, [self::STATUS_SUBMITTED, self::STATUS_REVIEWED], true),
                $canReject,
                $canReject ? null : 'reject_forbidden',
            ),
            'delete' => $this->formatActionCapability(true, $canDelete, $canDelete ? null : 'delete_forbidden'),
        ];
    }

    private function formatActionCapability(
        bool $applicable,
        bool $allowed,
        ?string $reasonCode = null,
        array $extra = [],
    ): array {
        return array_merge([
            'applicable' => $applicable,
            'allowed' => $applicable && $allowed,
            'reasonCode' => $applicable && ! $allowed ? $reasonCode : null,
        ], $extra);
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
        $module = $this->isInspectionReport($report) ? 'inspection' : 'report';
        $targetUserIds = [(int) $report->owner_user_id];
        $targetRoles = [];
        $excludeOwner = false;
        $workflowStage = strtolower($report->status) === 'submitted' ? 'review' : 'done';
        $nextActionRole = null;
        $scopeTeamId = null;

        if ($this->isManagedReportingWorkflowType($report->report_type ?? '')) {
            $workflow = $this->reportingWorkflowService->effectiveWorkflow($report);
            $workflowStage = $workflow['workflow_stage'] ?? $workflowStage;
            $nextActionRole = $workflow['next_action_role'] ?? null;
            $scopeTeamId = $workflow['scope_team_id'] ?? null;
            if ($actionRequired) {
                $targetUserIds = $this->reportingWorkflowService->recipientUserIdsForNextAction($report);
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
        $this->ensureReportingModulePermission($request, 'inspection');
    }

    private function ensureInspectionConductPermission(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! $this->authorizationService->hasPermission($user, 'reports.manage|reports.inspection.conduct')) {
            abort(403, 'Missing permission to conduct inspections.');
        }
    }

    private function ensureReportingModulePermission(Request $request, string $reportType): void
    {
        $user = $request->user();
        if (! $user || ! $this->hasReportingModulePermission($user, $reportType)) {
            abort(403, 'Forbidden');
        }
    }

    private function hasReportingModulePermission(mixed $user, string $reportType): bool
    {
        if (! $user) {
            return false;
        }

        return $this->reportReadAuthorizationService->canViewModule($user, $reportType);
    }

    private function isManagedReportingWorkflowType(?string $reportType): bool
    {
        return $this->reportingWorkflowService->isManagedModule($this->normalizeReportType($reportType));
    }

    private function normalizeReportType(mixed $reportType): string
    {
        return strtolower(trim((string) $reportType));
    }

    private function isSystemAdministrator(mixed $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->authorizationService->hasPermission($user, 'system.admin|*')) {
            return true;
        }

        return $this->authorizationService
            ->getActiveRoleNames($user)
            ->contains(function (string $roleName) {
                $normalized = Str::of($roleName)->trim()->lower()->toString();

                return in_array($normalized, ['system administrator', 'system admin'], true);
            });
    }

    private function inspectionSessionActor(Request $request): string
    {
        $user = $request->user();
        foreach (['name', 'full_name', 'fullName', 'display_name', 'displayName', 'email'] as $field) {
            $value = trim((string) ($user?->{$field} ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function inspectionSessionActorSnapshot(Request $request): array
    {
        $user = $request->user();
        $role = $user ? $this->authorizationService->getPrimaryRoleName($user) : null;

        return [
            'userId' => $user?->id ?? null,
            'name' => $this->inspectionSessionActor($request),
            'email' => trim((string) ($user?->email ?? '')),
            'role' => trim((string) ($role ?? '')),
            'roleCode' => trim((string) (RoleCatalog::abbreviationForRole($role) ?? '')),
        ];
    }

    private function inspectionWorkflowActorMeta(mixed $user): array
    {
        $role = $user ? $this->authorizationService->getPrimaryRoleName($user) : null;

        return [
            'actorRole' => trim((string) ($role ?? '')),
            'actorRoleCode' => trim((string) (RoleCatalog::abbreviationForRole($role) ?? '')),
        ];
    }

    private function applyInspectionSessionInspector(array $payload, Request $request): array
    {
        $field = $this->inspectionPayloadService->inspectorField($payload);
        $actor = $this->inspectionSessionActorSnapshot($request);
        if ($actor['name'] === '') {
            $fieldPath = $field !== null ? "payload.{$field}" : 'payload.inspectionActor';
            throw ValidationException::withMessages([
                $fieldPath => ['Unable to identify current user. Please sign in again.'],
            ]);
        }

        if ($field !== null) {
            $payload[$field] = $actor['name'];
            unset($payload[Str::snake($field)]);
        }

        $payload['inspectionActor'] = $actor;
        $payload['submittedBy'] = $actor['name'];
        $payload['submittedByRole'] = $actor['role'];
        $payload['submittedByRoleCode'] = $actor['roleCode'];
        unset(
            $payload['inspection_actor'],
            $payload['submitted_by'],
            $payload['submitted_by_role'],
            $payload['submitted_by_role_code'],
        );

        return $payload;
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

    private function inspectionFormId(array $payload): ?string
    {
        $type = trim((string) ($payload['incidentType'] ?? $payload['inspectionType'] ?? ''));

        return $type === '' ? null : Str::slug($type);
    }

    private function dutyContextFields(?array $context): array
    {
        if ($context === null) {
            return [];
        }

        return [
            'duty_context_status' => $context['status'] ?? 'unmatched',
            'duty_context_version' => $context['contextVersion'] ?? null,
            'duty_source_version' => $context['sourceVersion'] ?? null,
            'duty_context_snapshot' => $context,
        ];
    }

    private function submittedAtForReportPayload(array $data, bool $isInspection): Carbon
    {
        if (! $isInspection) {
            return now();
        }

        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $candidates = [
            $data['submitted_at'] ?? null,
            $data['submittedAt'] ?? null,
            $payload['submittedAt'] ?? null,
            $payload['submitted_at'] ?? null,
            $data['inspected_at'] ?? null,
            $data['inspectedAt'] ?? null,
            $payload['inspectedAt'] ?? null,
            $payload['inspected_at'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value === '') {
                continue;
            }
            try {
                return Carbon::parse($value)->setTimezone(config('app.timezone', 'UTC'));
            } catch (\Throwable) {
                throw ValidationException::withMessages([
                    'submitted_at' => ['Submitted timestamp must be a valid date and time.'],
                ]);
            }
        }

        return now();
    }
}
