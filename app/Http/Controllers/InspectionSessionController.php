<?php

namespace App\Http\Controllers;

use App\Models\InspectionExtinguisherResult;
use App\Models\InspectionFireExtinguisher;
use App\Models\InspectionSession;
use App\Models\InspectionSessionEvent;
use App\Models\Report;
use App\Models\ReportTimelineEntry;
use App\Services\AssignmentAuthorizationService;
use App\Services\InspectionFireExtinguisherSessionProgressService;
use App\Services\InspectionCheckRowSyncService;
use App\Services\InspectionWorkflowService;
use App\Services\WorkflowNotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InspectionSessionController extends Controller
{
    private const FIRE_EXTINGUISHER_TYPE = 'Fire Extinguisher Inspection';

    private const FIRE_EXTINGUISHER_TYPE_KEY = 'fire-extinguisher-inspection';

    private const ACTIVE_STATUS = 'active';

    private const FIRE_EXTINGUISHER_STATUS_FIELDS = [
        'physicalCondition',
        'signageCondition',
        'boxKeyAvailability',
        'boxGlassAvailability',
        'operationalCondition',
    ];

    private const FIRE_EXTINGUISHER_EVIDENCE_FIELDS = [
        'physicalCondition' => ['remarks' => 'physicalConditionRemarks', 'photos' => 'physicalConditionPhotos'],
        'signageCondition' => ['remarks' => 'signageConditionRemarks', 'photos' => 'signageConditionPhotos'],
        'boxKeyAvailability' => ['remarks' => 'boxKeyAvailabilityRemarks', 'photos' => 'boxKeyAvailabilityPhotos'],
        'boxGlassAvailability' => ['remarks' => 'boxGlassAvailabilityRemarks', 'photos' => 'boxGlassAvailabilityPhotos'],
        'operationalCondition' => ['remarks' => 'operationalConditionRemarks', 'photos' => 'operationalConditionPhotos'],
    ];

    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
        private readonly InspectionCheckRowSyncService $inspectionCheckRowSyncService,
        private readonly InspectionFireExtinguisherSessionProgressService $sessionProgressService,
        private readonly InspectionWorkflowService $inspectionWorkflowService,
        private readonly WorkflowNotificationService $workflowNotificationService,
    ) {
    }

    public function active(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $this->ensureInspectionPermission($request);

        $scope = $this->scopeFromRequest($request);
        $session = $this->findActiveSession($scope);

        return response()->json([
            'data' => $session ? $this->formatSession($session) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $this->ensureInspectionPermission($request);

        $data = $request->validate([
            'inspectionType' => ['nullable', 'string', 'max:190'],
            'inspection_type' => ['nullable', 'string', 'max:190'],
            'zone' => ['nullable', 'string', 'max:80'],
            'mainLocation' => ['nullable', 'string', 'max:190'],
            'main_location' => ['nullable', 'string', 'max:190'],
            'subLocation' => ['nullable', 'string', 'max:190'],
            'sub_location' => ['nullable', 'string', 'max:190'],
            'forceNew' => ['nullable', 'boolean'],
            'force_new' => ['nullable', 'boolean'],
        ]);
        $scope = $this->scopeFromArray($data);
        $forceNew = (bool) ($data['forceNew'] ?? $data['force_new'] ?? false);
        $sessionScope = $this->sessionScope($scope);

        if (! $forceNew) {
            $existing = $this->findActiveSession($sessionScope);
            if ($existing) {
                $this->sessionProgressService->sync($existing, $request->user()?->id);

                return response()->json([
                    'data' => $this->formatHydratedSession($existing->refresh(), $scope),
                    'created' => false,
                ]);
            }
        }

        $session = InspectionSession::query()->create([
            'session_uid' => 'inspection-session-'.Str::uuid()->toString(),
            'inspection_type' => self::FIRE_EXTINGUISHER_TYPE,
            'inspection_type_key' => self::FIRE_EXTINGUISHER_TYPE_KEY,
            'status' => self::ACTIVE_STATUS,
            'scope_zone' => $sessionScope['zone'],
            'scope_main_location' => $sessionScope['mainLocation'],
            'scope' => $sessionScope,
            'started_by_user_id' => $request->user()->id,
        ]);
        $this->recordEvent($session, null, 'session.created', $request, ['scope' => $sessionScope]);
        $this->sessionProgressService->sync($session, $request->user()?->id);

        return response()->json([
            'data' => $this->formatHydratedSession($session->refresh(), $scope),
            'created' => true,
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, string $sessionUid): JsonResponse
    {
        $this->ensureEnabled();
        $this->ensureInspectionPermission($request);
        $session = $this->findReadableSession($request, $sessionUid);
        $this->sessionProgressService->sync($session, $request->user()?->id);

        return response()->json([
            'data' => $this->formatSession($session->refresh()),
        ]);
    }

    public function locationResults(Request $request, string $sessionUid): JsonResponse
    {
        $this->ensureEnabled();
        $this->ensureInspectionPermission($request);
        $session = $this->findReadableSession($request, $sessionUid);

        $zone = $this->text($request->query('zone', ''));
        $mainLocation = $this->text($request->query('mainLocation', $request->query('main_location', '')));
        $subLocation = $this->text($request->query('subLocation', $request->query('sub_location', '')));

        $this->sessionProgressService->sync($session, $request->user()?->id, [
            'zone' => $zone,
            'mainLocation' => $mainLocation,
            'subLocation' => $subLocation,
        ]);

        return response()->json([
            'data' => $this->formatResultsForLocation($session, $zone, $mainLocation, $subLocation),
            'meta' => $this->sessionProgressService->progress($session->refresh()),
        ]);
    }

    public function claim(Request $request, string $sessionUid, string $extinguisherId): JsonResponse
    {
        $this->ensureEnabled();
        $this->ensureInspectionPermission($request);
        $session = $this->findWritableSession($request, $sessionUid);
        $payload = $this->resultPayloadFromRequest($request);
        $asset = $this->resolveAsset($extinguisherId, $payload);
        $existing = $session->extinguisherResults()
            ->with('checkedBy')
            ->where('canonical_asset_key', $asset['canonicalAssetKey'])
            ->first();

        if ($existing && $existing->status === 'completed') {
            return response()->json([
                'message' => 'This fire extinguisher has already been inspected in this session.',
                'code' => 'inspection_extinguisher_already_completed',
                'data' => $this->formatResult($existing),
            ], Response::HTTP_CONFLICT);
        }

        if ($existing) {
            $existing->update([
                'lock_owner_user_id' => $request->user()->id,
                'lock_expires_at' => now()->addMinutes(2),
            ]);
            $this->recordEvent($session, $existing, 'extinguisher.claimed', $request);

            return response()->json(['data' => $this->formatResult($existing->refresh()->load('checkedBy'))]);
        }

        $result = $session->extinguisherResults()->create([
            'canonical_asset_key' => $asset['canonicalAssetKey'],
            'fire_extinguisher_id' => $asset['fireExtinguisherId'],
            'zone' => $asset['zone'],
            'main_location' => $asset['mainLocation'],
            'sub_location' => $asset['subLocation'],
            'id_loc_no' => $asset['idLocNo'],
            'barcode_no' => $asset['barcodeNo'],
            'status' => 'in_progress',
            'check_payload' => $asset['checkPayload'],
            'checked_by_user_id' => $request->user()->id,
            'lock_owner_user_id' => $request->user()->id,
            'lock_expires_at' => now()->addMinutes(2),
        ]);
        $this->recordEvent($session, $result, 'extinguisher.claimed', $request);

        return response()->json(['data' => $this->formatResult($result->load('checkedBy'))], Response::HTTP_CREATED);
    }

    public function complete(Request $request, string $sessionUid, string $extinguisherId): JsonResponse
    {
        $this->ensureEnabled();
        $this->ensureInspectionPermission($request);
        $session = $this->findWritableSession($request, $sessionUid);
        $data = $request->validate([
            'checkPayload' => ['nullable', 'array'],
            'check_payload' => ['nullable', 'array'],
            'clientResultId' => ['nullable', 'string', 'max:190'],
            'client_result_id' => ['nullable', 'string', 'max:190'],
            'baseVersion' => ['nullable', 'integer', 'min:0'],
            'base_version' => ['nullable', 'integer', 'min:0'],
            'forceRecheck' => ['nullable', 'boolean'],
            'force_recheck' => ['nullable', 'boolean'],
        ]);
        $payload = $this->resultPayloadFromRequest($request);
        $asset = $this->resolveAsset($extinguisherId, $payload);
        $clientResultId = $this->text($data['clientResultId'] ?? $data['client_result_id'] ?? '');
        $baseVersion = (int) ($data['baseVersion'] ?? $data['base_version'] ?? 0);
        $forceRecheck = (bool) ($data['forceRecheck'] ?? $data['force_recheck'] ?? false);
        $this->validateCompletedFireExtinguisherPayload($asset['checkPayload']);

        try {
            $result = DB::transaction(function () use ($session, $asset, $clientResultId, $baseVersion, $forceRecheck, $request): InspectionExtinguisherResult {
                $existing = $session->extinguisherResults()
                    ->lockForUpdate()
                    ->where('canonical_asset_key', $asset['canonicalAssetKey'])
                    ->first();

                if ($existing && $clientResultId !== '' && $existing->client_result_id === $clientResultId) {
                    if ($existing->status === 'completed') {
                        $this->sessionProgressService->sync($session, $request->user()?->id, $asset);
                    }
                    return $existing;
                }

                $isCompletedByAnotherUser = $existing
                    && $existing->status === 'completed'
                    && (int) $existing->checked_by_user_id !== (int) $request->user()->id;

                if ($isCompletedByAnotherUser && ! $forceRecheck) {
                    $existing->load('checkedBy');
                    throw ValidationException::withMessages([
                        'extinguisher' => 'This fire extinguisher has already been inspected in this session.',
                    ])->status(Response::HTTP_CONFLICT);
                }

                if ($existing && $baseVersion > 0 && (int) $existing->version !== $baseVersion) {
                    $existing->load('checkedBy');
                    throw ValidationException::withMessages([
                        'version' => 'This fire extinguisher result changed since it was loaded.',
                    ])->status(Response::HTTP_CONFLICT);
                }

                if ($existing) {
                    $existing->fill([
                        'fire_extinguisher_id' => $asset['fireExtinguisherId'],
                        'zone' => $asset['zone'],
                        'main_location' => $asset['mainLocation'],
                        'sub_location' => $asset['subLocation'],
                        'id_loc_no' => $asset['idLocNo'],
                        'barcode_no' => $asset['barcodeNo'],
                        'status' => 'completed',
                        'check_payload' => $asset['checkPayload'],
                        'client_result_id' => $clientResultId ?: $existing->client_result_id,
                        'checked_by_user_id' => $request->user()->id,
                        'checked_at' => now(),
                        'lock_owner_user_id' => null,
                        'lock_expires_at' => null,
                        'version' => ((int) $existing->version) + 1,
                    ])->save();
                    $this->recordEvent($session, $existing, $forceRecheck ? 'extinguisher.rechecked' : 'extinguisher.completed', $request);
                    $this->sessionProgressService->sync($session, $request->user()?->id, $asset);
                    return $existing;
                }

                $created = $session->extinguisherResults()->create([
                    'canonical_asset_key' => $asset['canonicalAssetKey'],
                    'fire_extinguisher_id' => $asset['fireExtinguisherId'],
                    'zone' => $asset['zone'],
                    'main_location' => $asset['mainLocation'],
                    'sub_location' => $asset['subLocation'],
                    'id_loc_no' => $asset['idLocNo'],
                    'barcode_no' => $asset['barcodeNo'],
                    'status' => 'completed',
                    'check_payload' => $asset['checkPayload'],
                    'client_result_id' => $clientResultId ?: null,
                    'checked_by_user_id' => $request->user()->id,
                    'checked_at' => now(),
                ]);
                $this->recordEvent($session, $created, 'extinguisher.completed', $request);
                $this->sessionProgressService->sync($session, $request->user()?->id, $asset);

                return $created;
            });
        } catch (ValidationException $exception) {
            $existing = $session->extinguisherResults()
                ->with('checkedBy')
                ->where('canonical_asset_key', $asset['canonicalAssetKey'])
                ->first();

            return response()->json([
                'message' => $exception->getMessage() ?: 'Inspection result conflict.',
                'code' => 'inspection_extinguisher_result_conflict',
                'errors' => $exception->errors(),
                'data' => $existing ? $this->formatResult($existing) : null,
            ], Response::HTTP_CONFLICT);
        } catch (QueryException $exception) {
            $existing = $session->extinguisherResults()
                ->with('checkedBy')
                ->where('canonical_asset_key', $asset['canonicalAssetKey'])
                ->first();

            return response()->json([
                'message' => 'This fire extinguisher has already been inspected in this session.',
                'code' => 'inspection_extinguisher_result_duplicate',
                'data' => $existing ? $this->formatResult($existing) : null,
            ], Response::HTTP_CONFLICT);
        }

        $session->increment('version');

        return response()->json([
            'data' => $this->formatResult($result->refresh()->load('checkedBy')),
            'meta' => $this->sessionProgressService->progress($session->refresh()),
        ]);
    }

    public function reset(Request $request, string $sessionUid, string $extinguisherId): JsonResponse
    {
        $this->ensureEnabled();
        $this->ensureInspectionPermission($request);
        $session = $this->findWritableSession($request, $sessionUid);
        $request->validate([
            'checkPayload' => ['nullable', 'array'],
            'check_payload' => ['nullable', 'array'],
        ]);
        $payload = $this->resultPayloadFromRequest($request);
        $asset = $this->resolveAsset($extinguisherId, $payload);

        try {
            $result = DB::transaction(function () use ($session, $asset, $request): ?InspectionExtinguisherResult {
                $existing = $session->extinguisherResults()
                    ->lockForUpdate()
                    ->where('canonical_asset_key', $asset['canonicalAssetKey'])
                    ->first();

                if (! $existing) {
                    $this->sessionProgressService->sync($session, $request->user()?->id, $asset);

                    return null;
                }

                $isCompletedByAnotherUser = $existing->status === 'completed'
                    && (int) $existing->checked_by_user_id !== (int) $request->user()->id;

                if ($isCompletedByAnotherUser) {
                    $existing->load('checkedBy');
                    throw ValidationException::withMessages([
                        'extinguisher' => 'This fire extinguisher was inspected by another user and cannot be reset by you.',
                    ])->status(Response::HTTP_CONFLICT);
                }

                $existing->fill([
                    'fire_extinguisher_id' => $asset['fireExtinguisherId'],
                    'zone' => $asset['zone'],
                    'main_location' => $asset['mainLocation'],
                    'sub_location' => $asset['subLocation'],
                    'id_loc_no' => $asset['idLocNo'],
                    'barcode_no' => $asset['barcodeNo'],
                    'status' => 'in_progress',
                    'check_payload' => $asset['checkPayload'],
                    'client_result_id' => null,
                    'checked_by_user_id' => null,
                    'checked_at' => null,
                    'lock_owner_user_id' => null,
                    'lock_expires_at' => null,
                    'version' => ((int) $existing->version) + 1,
                ])->save();

                $this->recordEvent($session, $existing, 'extinguisher.reset', $request);
                $this->sessionProgressService->sync($session, $request->user()?->id, $asset);

                return $existing;
            });
        } catch (ValidationException $exception) {
            $existing = $session->extinguisherResults()
                ->with('checkedBy')
                ->where('canonical_asset_key', $asset['canonicalAssetKey'])
                ->first();

            return response()->json([
                'message' => $exception->getMessage() ?: 'Inspection result conflict.',
                'code' => 'inspection_extinguisher_result_conflict',
                'errors' => $exception->errors(),
                'data' => $existing ? $this->formatResult($existing) : null,
            ], Response::HTTP_CONFLICT);
        }

        $session->increment('version');

        return response()->json([
            'data' => $result ? $this->formatResult($result->refresh()->load('checkedBy')) : null,
            'meta' => $this->sessionProgressService->progress($session->refresh()),
        ]);
    }

    public function submit(Request $request, string $sessionUid): JsonResponse
    {
        $this->ensureEnabled();
        $this->ensureInspectionPermission($request);
        $session = $this->findReadableSession($request, $sessionUid);
        $user = $request->user();
        $submissionKey = $this->text($request->input('submission_key', ''));

        if ($submissionKey !== '') {
            $existing = Report::query()
                ->where('owner_user_id', $user->id)
                ->where('submission_key', $submissionKey)
                ->first();
            if ($existing) {
                return response()->json([
                    'data' => [
                        'reportUid' => $existing->report_uid,
                        'displayId' => $existing->display_id,
                        'sessionUid' => $session->session_uid,
                        'idempotentReplay' => true,
                    ],
                ]);
            }
        }

        if ($session->status !== self::ACTIVE_STATUS) {
            abort(Response::HTTP_CONFLICT, 'Only active inspection sessions can be submitted.');
        }

        if (($blockReason = $this->inspectionWorkflowService->submissionBlockReason($user)) !== null) {
            throw ValidationException::withMessages(['workflow' => [$blockReason]]);
        }

        $completedResults = $session->extinguisherResults()
            ->where('status', 'completed')
            ->orderBy('zone')
            ->orderBy('main_location')
            ->orderBy('sub_location')
            ->orderBy('id_loc_no')
            ->get();

        if ($completedResults->isEmpty()) {
            throw ValidationException::withMessages([
                'session' => ['At least one completed fire extinguisher result is required before submitting.'],
            ]);
        }

        $report = DB::transaction(function () use ($session, $completedResults, $request, $user): Report {
            $payload = $this->compileSessionReportPayload($session, $completedResults);
            $workflowFields = $this->inspectionWorkflowService->appendSubmissionHistory(
                $this->inspectionWorkflowService->buildWorkflowForSubmission($user),
                $user,
                'Submitted',
                $this->text($request->input('remarks', '')),
            );
            $report = Report::query()->create([
                'report_uid' => 'report-ins-session-'.Str::uuid()->toString(),
                'display_id' => $this->text($request->input('display_id', '')) ?: 'INS-FE-'.now()->format('Ymd-His'),
                'submission_key' => $this->text($request->input('submission_key', '')) ?: null,
                'owner_user_id' => $user->id,
                'report_type' => 'inspection',
                'status' => 'Submitted',
                'version' => 1,
                'revision' => 1,
                'payload' => $payload,
                'inspection_checklist_item_ids' => [],
                'inspection_checklist_item_labels' => [],
                'inspection_has_checklist' => true,
                'submitted_at' => now(),
            ] + $workflowFields);

            ReportTimelineEntry::query()->create([
                'report_id' => $report->id,
                'revision' => $report->revision,
                'action' => 'Submitted',
                'from_status' => null,
                'to_status' => 'Submitted',
                'by_user_id' => $user->id,
                'by_name_snapshot' => (string) $user->name,
                'remarks' => $this->text($request->input('remarks', '')),
                'meta' => ['inspectionSessionUid' => $session->session_uid],
            ]);

            $session->update([
                'status' => 'submitted',
                'submitted_by_user_id' => $user->id,
                'submitted_report_uid' => $report->report_uid,
                'submitted_at' => now(),
                'version' => ((int) $session->version) + 1,
            ]);
            $this->recordEvent($session, null, 'session.submitted', $request, [
                'reportUid' => $report->report_uid,
            ]);

            $this->inspectionCheckRowSyncService->syncForReport($report->refresh(), (int) $user->id);

            return $report->load('timelineEntries');
        });

        try {
            $this->workflowNotificationService->emit(
                module: 'report',
                eventType: 'submitted',
                recordType: 'report',
                recordId: (int) $report->id,
                recordDisplayId: (string) $report->display_id,
                ownerUserId: (int) $report->owner_user_id,
                actor: [
                    'userId' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email ?? '',
                ],
                targetUserIds: $this->inspectionWorkflowService->recipientUserIdsForNextAction($report),
                actionRequired: true,
                remarks: $this->text($request->input('remarks', '')),
                metadata: [
                    'module' => 'report',
                    'status' => $report->status,
                    'workflowStage' => $report->workflow_stage,
                    'nextActionRole' => $report->next_action_role,
                    'scopeTeamId' => $report->scope_team_id,
                    'reportType' => $report->report_type,
                    'reportUid' => $report->report_uid,
                    'detailRouteKey' => $report->report_uid,
                ],
                excludeOwner: true,
            );
        } catch (\Throwable $exception) {
            Log::warning('Inspection session workflow notification dispatch failed.', [
                'session_uid' => $session->session_uid,
                'report_uid' => $report->report_uid,
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'data' => [
                'reportUid' => $report->report_uid,
                'displayId' => $report->display_id,
                'sessionUid' => $session->session_uid,
            ],
        ], Response::HTTP_CREATED);
    }

    private function findActiveSession(array $scope): ?InspectionSession
    {
        $sessionScope = $this->sessionScope($scope);

        return InspectionSession::query()
            ->where('inspection_type_key', self::FIRE_EXTINGUISHER_TYPE_KEY)
            ->where('status', self::ACTIVE_STATUS)
            ->where('scope_zone', $sessionScope['zone'])
            ->where('scope_main_location', $sessionScope['mainLocation'])
            ->orderByDesc('updated_at')
            ->first();
    }

    private function findReadableSession(Request $request, string $sessionUid): InspectionSession
    {
        return InspectionSession::query()->where('session_uid', $sessionUid)->firstOrFail();
    }

    private function findWritableSession(Request $request, string $sessionUid): InspectionSession
    {
        $session = $this->findReadableSession($request, $sessionUid);
        if ($session->status !== self::ACTIVE_STATUS) {
            abort(Response::HTTP_CONFLICT, 'Only active inspection sessions can be changed.');
        }

        return $session;
    }

    private function resultPayloadFromRequest(Request $request): array
    {
        $payload = $request->input('checkPayload', $request->input('check_payload', []));

        return is_array($payload) ? $payload : [];
    }

    private function resolveAsset(string $extinguisherId, array $payload): array
    {
        $catalogId = (int) ($payload['catalogId'] ?? $payload['catalog_id'] ?? 0);
        $routeId = ctype_digit($extinguisherId) ? (int) $extinguisherId : 0;
        $fireExtinguisherId = $catalogId > 0 ? $catalogId : ($routeId > 0 ? $routeId : null);
        $catalog = $fireExtinguisherId
            ? InspectionFireExtinguisher::query()->where('is_active', true)->find($fireExtinguisherId)
            : null;

        $zone = $this->text($catalog?->zone ?? $payload['zone'] ?? '');
        $mainLocation = $this->text($catalog?->main_location_name ?? $payload['mainLocation'] ?? $payload['main_location'] ?? $payload['location'] ?? '');
        $subLocation = $this->text($catalog?->sub_location_name ?? $payload['subLocation'] ?? $payload['sub_location'] ?? '');
        $idLocNo = $this->text($payload['idLocNo'] ?? $payload['id_loc_no'] ?? $catalog?->id_loc_no ?? '');
        $barcodeNo = $this->text($payload['barcodeNo'] ?? $payload['barcode_no'] ?? $catalog?->barcode_no ?? '');
        $activeIdentityKey = $this->text($payload['activeIdentityKey'] ?? $payload['active_identity_key'] ?? $catalog?->active_identity_key ?? '');
        $canonicalAssetKey = $this->canonicalAssetKey(
            fireExtinguisherId: $fireExtinguisherId,
            activeIdentityKey: $activeIdentityKey,
            barcodeNo: $barcodeNo,
            idLocNo: $idLocNo,
            zone: $zone,
            mainLocation: $mainLocation,
            subLocation: $subLocation,
        );

        if ($canonicalAssetKey === '') {
            throw ValidationException::withMessages([
                'extinguisher' => ['A stable fire extinguisher identity is required.'],
            ]);
        }

        return [
            'canonicalAssetKey' => $canonicalAssetKey,
            'fireExtinguisherId' => $fireExtinguisherId,
            'zone' => $zone,
            'mainLocation' => $mainLocation,
            'subLocation' => $subLocation,
            'idLocNo' => $idLocNo,
            'barcodeNo' => $barcodeNo,
            'checkPayload' => array_merge($payload, [
                'catalogId' => $fireExtinguisherId ?: ($payload['catalogId'] ?? $payload['catalog_id'] ?? ''),
                'canonicalAssetKey' => $canonicalAssetKey,
                'activeIdentityKey' => $activeIdentityKey,
                'zone' => $zone,
                'mainLocation' => $mainLocation,
                'location' => $mainLocation,
                'subLocation' => $subLocation,
                'idLocNo' => $idLocNo,
                'barcodeNo' => $barcodeNo,
            ]),
        ];
    }

    private function canonicalAssetKey(
        ?int $fireExtinguisherId,
        string $activeIdentityKey,
        string $barcodeNo,
        string $idLocNo,
        string $zone,
        string $mainLocation,
        string $subLocation,
    ): string {
        if ($fireExtinguisherId) {
            return 'catalog:'.$fireExtinguisherId;
        }
        if ($activeIdentityKey !== '') {
            return 'identity:'.$activeIdentityKey;
        }
        if ($barcodeNo !== '') {
            return 'barcode:'.$this->identityPart($barcodeNo);
        }
        if ($idLocNo !== '' && $mainLocation !== '') {
            return 'location:'.hash('sha256', implode('|', [
                $this->identityPart($zone),
                $this->identityPart($mainLocation),
                $this->identityPart($subLocation),
                $this->identityPart($idLocNo),
            ]));
        }

        return '';
    }

    private function validateCompletedFireExtinguisherPayload(array $payload): void
    {
        foreach (self::FIRE_EXTINGUISHER_STATUS_FIELDS as $field) {
            $status = $this->text($payload[$field] ?? $payload[Str::snake($field)] ?? '');
            if ($status === '') {
                throw ValidationException::withMessages([
                    "checkPayload.{$field}" => ['Fire extinguisher check status is required before completion.'],
                ]);
            }

            if (! $this->isFireExtinguisherDefectStatus($status)) {
                continue;
            }

            $meta = self::FIRE_EXTINGUISHER_EVIDENCE_FIELDS[$field];
            $remarks = $this->text($payload[$meta['remarks']] ?? $payload[Str::snake($meta['remarks'])] ?? '');
            $photos = $payload[$meta['photos']] ?? $payload[Str::snake($meta['photos'])] ?? [];
            if ($remarks === '') {
                throw ValidationException::withMessages([
                    "checkPayload.{$meta['remarks']}" => ['Fire extinguisher remarks are required for defect or failed statuses.'],
                ]);
            }
            if (! is_array($photos) || collect($photos)->filter()->isEmpty()) {
                throw ValidationException::withMessages([
                    "checkPayload.{$meta['photos']}" => ['Fire extinguisher defect photo is required for defect or failed statuses.'],
                ]);
            }
        }
    }

    private function isFireExtinguisherDefectStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['not good', 'no', 'not operational'], true);
    }

    private function compileSessionReportPayload(InspectionSession $session, $completedResults): array
    {
        $checks = $completedResults
            ->map(fn (InspectionExtinguisherResult $result): array => array_merge($result->check_payload, [
                'inspectionSessionUid' => $session->session_uid,
                'inspectionResultId' => $result->id,
                'checkedBy' => $result->checkedBy?->name ?? '',
                'checkedAt' => $result->checked_at?->toIso8601String() ?? '',
            ]))
            ->values()
            ->all();
        $scope = is_array($session->scope) ? $session->scope : [];
        $zone = $this->text($scope['zone'] ?? '');
        $mainLocation = $this->text($scope['mainLocation'] ?? '');
        $location = implode(' > ', array_filter([
            $zone !== '' ? 'Zone '.$zone : '',
            $mainLocation,
        ]));

        return [
            'inspectionSessionUid' => $session->session_uid,
            'compiledAt' => now()->toIso8601String(),
            'incidentType' => self::FIRE_EXTINGUISHER_TYPE,
            'inspectionType' => self::FIRE_EXTINGUISHER_TYPE,
            'location' => $location,
            'selectedLocation' => $location,
            'zone' => $zone,
            'mainLocation' => $mainLocation,
            'subLocation' => '',
            'fireExtinguisherInspectedBy' => $session->startedBy?->name ?? '',
            'fireExtinguisherInspectionDate' => now()->toDateString(),
            'description' => sprintf(
                'Fire extinguisher inspection session %s. %d extinguisher(s) checked.',
                $session->session_uid,
                count($checks),
            ),
            'photos' => [],
            'fireExtinguisherChecks' => $checks,
            'checklist' => $this->compiledChecklist($checks),
            'summary' => [
                'checkedCount' => count($checks),
                'scope' => $scope,
            ],
        ];
    }

    private function compiledChecklist(array $checks): array
    {
        $fields = [
            'physicalCondition' => 'Physical Condition',
            'signageCondition' => 'Signage Condition',
            'boxKeyAvailability' => 'Box Key Availability',
            'boxGlassAvailability' => 'Box Glass Availability',
            'operationalCondition' => 'Operational Condition',
        ];

        return collect($fields)
            ->map(fn (string $label, string $key): array => [
                'id' => 'fire-extinguisher-'.$this->slug($key),
                'label' => $label,
                'selected' => collect($checks)->contains(fn (array $row): bool => $this->text($row[$key] ?? '') !== ''),
            ])
            ->values()
            ->all();
    }

    private function resultMatchesLocationFilter(
        InspectionExtinguisherResult $result,
        string $zone,
        string $mainLocation,
        string $subLocation,
    ): bool {
        if ($zone !== '' && ! $this->sameZone($result->zone, $zone)) {
            return false;
        }
        if ($mainLocation !== '' && ! $this->sameLocationPart($result->main_location, $mainLocation)) {
            return false;
        }
        if ($subLocation !== '' && ! $this->sameLocationPart($result->sub_location, $subLocation)) {
            return false;
        }

        return true;
    }

    private function sameZone(mixed $left, mixed $right): bool
    {
        return $this->zoneIdentityPart($left) === $this->zoneIdentityPart($right);
    }

    private function sameLocationPart(mixed $left, mixed $right): bool
    {
        return $this->identityPart($left) === $this->identityPart($right);
    }

    private function formatHydratedSession(InspectionSession $session, array $scope): array
    {
        $zone = $this->text($scope['zone'] ?? $session->scope_zone ?? '');
        $mainLocation = $this->text($scope['mainLocation'] ?? $session->scope_main_location ?? '');
        $shouldIncludeResults = $zone !== '' || $mainLocation !== '';

        return array_merge($this->formatSession($session), [
            'results' => $shouldIncludeResults
                ? $this->formatResultsForLocation($session, $zone, $mainLocation)
                : [],
        ]);
    }

    private function formatResultsForLocation(
        InspectionSession $session,
        string $zone,
        string $mainLocation,
        string $subLocation = '',
    ): array {
        return $session->extinguisherResults()
            ->with('checkedBy')
            ->get()
            ->filter(fn (InspectionExtinguisherResult $result): bool => $this->resultMatchesLocationFilter($result, $zone, $mainLocation, $subLocation))
            ->sortBy([
                ['main_location', 'asc'],
                ['sub_location', 'asc'],
                ['id_loc_no', 'asc'],
                ['barcode_no', 'asc'],
            ])
            ->map(fn (InspectionExtinguisherResult $result): array => $this->formatResult($result))
            ->values()
            ->all();
    }

    private function formatSession(InspectionSession $session): array
    {
        return [
            'id' => $session->session_uid,
            'sessionUid' => $session->session_uid,
            'inspectionType' => $session->inspection_type,
            'inspectionTypeKey' => $session->inspection_type_key,
            'status' => $session->status,
            'scope' => $session->scope ?: [],
            'scopeZone' => $session->scope_zone,
            'scopeMainLocation' => $session->scope_main_location,
            'startedByUserId' => $session->started_by_user_id,
            'submittedReportUid' => $session->submitted_report_uid,
            'submittedAt' => $session->submitted_at?->toIso8601String(),
            'version' => $session->version,
            'progress' => $this->sessionProgressService->progress($session),
            'createdAt' => $session->created_at?->toIso8601String(),
            'updatedAt' => $session->updated_at?->toIso8601String(),
        ];
    }

    private function formatResult(InspectionExtinguisherResult $result): array
    {
        return [
            'id' => $result->id,
            'sessionId' => $result->inspection_session_id,
            'canonicalAssetKey' => $result->canonical_asset_key,
            'fireExtinguisherId' => $result->fire_extinguisher_id,
            'catalogId' => $result->fire_extinguisher_id,
            'zone' => $result->zone,
            'mainLocation' => $result->main_location,
            'subLocation' => $result->sub_location,
            'idLocNo' => $result->id_loc_no,
            'barcodeNo' => $result->barcode_no,
            'status' => $result->status,
            'checkPayload' => $result->check_payload,
            'clientResultId' => $result->client_result_id,
            'checkedByUserId' => $result->checked_by_user_id,
            'checkedBy' => $result->checkedBy?->name ?? '',
            'checkedAt' => $result->checked_at?->toIso8601String(),
            'lockOwnerUserId' => $result->lock_owner_user_id,
            'lockExpiresAt' => $result->lock_expires_at?->toIso8601String(),
            'version' => $result->version,
            'updatedAt' => $result->updated_at?->toIso8601String(),
        ];
    }

    private function recordEvent(
        InspectionSession $session,
        ?InspectionExtinguisherResult $result,
        string $eventType,
        Request $request,
        array $payload = [],
    ): void {
        InspectionSessionEvent::query()->create([
            'inspection_session_id' => $session->id,
            'inspection_extinguisher_result_id' => $result?->id,
            'event_type' => $eventType,
            'actor_user_id' => $request->user()?->id,
            'payload' => $payload ?: null,
        ]);
    }

    private function scopeFromRequest(Request $request): array
    {
        return $this->scopeFromArray($request->all() + $request->query());
    }

    private function scopeFromArray(array $data): array
    {
        $inspectionType = $this->text($data['inspectionType'] ?? $data['inspection_type'] ?? self::FIRE_EXTINGUISHER_TYPE);
        if ($this->slug($inspectionType) !== self::FIRE_EXTINGUISHER_TYPE_KEY) {
            throw ValidationException::withMessages([
                'inspectionType' => ['Inspection sessions are currently supported for fire extinguisher inspection only.'],
            ]);
        }

        return [
            'inspectionType' => self::FIRE_EXTINGUISHER_TYPE,
            'zone' => $this->text($data['zone'] ?? ''),
            'mainLocation' => $this->text($data['mainLocation'] ?? $data['main_location'] ?? ''),
            'subLocation' => $this->text($data['subLocation'] ?? $data['sub_location'] ?? ''),
        ];
    }

    private function sessionScope(array $scope): array
    {
        return [
            'inspectionType' => self::FIRE_EXTINGUISHER_TYPE,
            'zone' => '',
            'mainLocation' => '',
            'subLocation' => '',
        ];
    }

    private function ensureInspectionPermission(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! $this->authorizationService->hasPermission($user, 'reports.manage|reports.inspection.view')) {
            abort(403, 'Missing inspection report permission.');
        }
    }

    private function ensureEnabled(): void
    {
        if (! filter_var(env('INSPECTION_SESSION_FIRE_EXTINGUISHER_ENABLED', true), FILTER_VALIDATE_BOOLEAN)) {
            abort(404);
        }
    }

    private function text(mixed $value): string
    {
        return Str::of((string) $value)->squish()->toString();
    }

    private function identityPart(mixed $value): string
    {
        return Str::of(str_replace(["CO\u{00B2}", "CO\u{FFFD}"], 'CO2', (string) $value))
            ->squish()
            ->lower()
            ->toString();
    }

    private function zoneIdentityPart(mixed $value): string
    {
        return Str::of(preg_replace('/^zone\s+/i', '', $this->identityPart($value)) ?? '')
            ->squish()
            ->toString();
    }

    private function slug(mixed $value): string
    {
        return Str::slug($this->identityPart($value));
    }
}
