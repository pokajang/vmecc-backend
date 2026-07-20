<?php

namespace App\Http\Controllers;

use App\Models\InspectionExtinguisherResult;
use App\Models\InspectionFireExtinguisher;
use App\Models\InspectionSession;
use App\Models\InspectionSessionEvent;
use App\Models\Report;
use App\Models\ReportTimelineEntry;
use App\Models\User;
use App\Services\AssignmentAuthorizationService;
use App\Services\InspectionCheckRowSyncService;
use App\Services\InspectionDutyConfirmationService;
use App\Services\InspectionDutyContextResolver;
use App\Services\InspectionExtinguisherOperationService;
use App\Services\InspectionFireExtinguisherSessionProgressService;
use App\Services\InspectionPayloadService;
use App\Services\InspectionPolicy;
use App\Services\InspectionSessionReportPayloadBuilder;
use App\Services\InspectionSessionResolverService;
use App\Services\InspectionWorkflowService;
use App\Services\ReportMediaService;
use App\Services\WorkflowNotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
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
        private readonly InspectionSessionResolverService $sessionResolverService,
        private readonly InspectionSessionReportPayloadBuilder $sessionReportPayloadBuilder,
        private readonly InspectionPayloadService $inspectionPayloadService,
        private readonly InspectionExtinguisherOperationService $extinguisherOperationService,
        private readonly InspectionWorkflowService $inspectionWorkflowService,
        private readonly WorkflowNotificationService $workflowNotificationService,
        private readonly ReportMediaService $reportMediaService,
        private readonly InspectionDutyConfirmationService $dutyConfirmations,
        private readonly InspectionDutyContextResolver $dutyContextResolver,
        private readonly InspectionPolicy $inspectionPolicy,
    ) {}

    public function active(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $this->ensureInspectionPermission($request);

        $scope = $this->scopeFromRequest($request);
        $session = $this->sessionResolverService->findActive($scope, $request->user());

        return response()->json([
            'data' => $session ? $this->formatSessionForActor($session, $request) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureEnabled();
        $this->ensureInspectionConductPermission($request);

        $data = $request->validate([
            'inspectionType' => ['nullable', 'string', 'max:190'],
            'inspection_type' => ['nullable', 'string', 'max:190'],
            'zone' => ['nullable', 'string', 'max:80'],
            'mainLocation' => ['nullable', 'string', 'max:190'],
            'main_location' => ['nullable', 'string', 'max:190'],
            'subLocation' => ['nullable', 'string', 'max:190'],
            'sub_location' => ['nullable', 'string', 'max:190'],
            'scopeVersion' => ['nullable', 'string', 'in:v2'],
            'scope_version' => ['nullable', 'string', 'in:v2'],
            'siteKey' => ['nullable', 'string', 'max:190'],
            'site_key' => ['nullable', 'string', 'max:190'],
            'inspectionDate' => ['nullable', 'date_format:Y-m-d'],
            'inspection_date' => ['nullable', 'date_format:Y-m-d'],
            'shiftKey' => ['nullable', 'string', 'max:80'],
            'shift_key' => ['nullable', 'string', 'max:80'],
            'batchKey' => ['nullable', 'string', 'max:190'],
            'batch_key' => ['nullable', 'string', 'max:190'],
            'teamId' => ['nullable', 'integer', 'min:1'],
            'team_id' => ['nullable', 'integer', 'min:1'],
            'forceNew' => ['nullable', 'boolean'],
            'force_new' => ['nullable', 'boolean'],
        ]);
        $scope = $this->scopeFromArray($data);
        $forceNew = (bool) ($data['forceNew'] ?? $data['force_new'] ?? false);
        $sessionScope = $this->sessionResolverService->resolveScope($scope, $request->user());
        $dutyContext = $this->dutyContextResolver->resolve($request->user());

        if (! $forceNew) {
            $existing = $this->sessionResolverService->findActive($sessionScope);
            if ($existing) {
                $this->sessionResolverService->logOutcome($existing, 'resumed');
                $this->sessionProgressService->sync($existing, $request->user()?->id);

                return response()->json([
                    'data' => $this->formatHydratedSessionForActor($existing->refresh(), $scope, $request),
                    'created' => false,
                ]);
            }
        }

        try {
            $session = $this->sessionResolverService->create($sessionScope, (int) $request->user()->id, $dutyContext);
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            if (! in_array($sqlState, ['23000', '23505'], true)) {
                throw $exception;
            }
            $existing = $sessionScope['scopeVersion'] === 'v2'
                ? $this->sessionResolverService->findActive($sessionScope)
                : null;
            if (! $existing) {
                throw $exception;
            }
            $this->sessionResolverService->logOutcome($existing, 'concurrent-resume');
            $this->sessionProgressService->sync($existing, $request->user()?->id);

            return response()->json([
                'data' => $this->formatHydratedSessionForActor($existing->refresh(), $scope, $request),
                'created' => false,
            ]);
        }
        $this->sessionResolverService->logOutcome($session, 'created');
        $this->recordEvent($session, null, 'session.created', $request, ['scope' => $sessionScope]);
        $this->sessionProgressService->sync($session, $request->user()?->id);

        return response()->json([
            'data' => $this->formatHydratedSessionForActor($session->refresh(), $scope, $request),
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
            'data' => $this->formatSessionForActor($session->refresh(), $request),
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
        $this->ensureInspectionConductPermission($request);
        $session = $this->findWritableSession($request, $sessionUid);
        $this->dutyConfirmations->consume($request, 'session-write', $session->session_uid, self::FIRE_EXTINGUISHER_TYPE_KEY);
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
        $this->ensureInspectionConductPermission($request);
        $session = $this->findWritableSession($request, $sessionUid);
        $this->dutyConfirmations->consume($request, 'session-write', $session->session_uid, self::FIRE_EXTINGUISHER_TYPE_KEY);
        $data = $request->validate([
            'checkPayload' => ['nullable', 'array'],
            'check_payload' => ['nullable', 'array'],
            'clientResultId' => ['nullable', 'string', 'max:190'],
            'client_result_id' => ['nullable', 'string', 'max:190'],
            'operationId' => ['nullable', 'string', 'max:190'],
            'operation_id' => ['nullable', 'string', 'max:190'],
            'baseVersion' => ['nullable', 'integer', 'min:0'],
            'base_version' => ['nullable', 'integer', 'min:0'],
            'forceRecheck' => ['nullable', 'boolean'],
            'force_recheck' => ['nullable', 'boolean'],
        ]);
        $payload = $this->resultPayloadFromRequest($request);
        $asset = $this->resolveAsset($extinguisherId, $payload);
        $clientResultId = $this->text($data['clientResultId'] ?? $data['client_result_id'] ?? '');
        $operationId = $this->text($data['operationId'] ?? $data['operation_id'] ?? '');
        $baseVersion = (int) ($data['baseVersion'] ?? $data['base_version'] ?? 0);
        $forceRecheck = (bool) ($data['forceRecheck'] ?? $data['force_recheck'] ?? false);
        $this->validateCompletedFireExtinguisherPayload($asset['checkPayload']);

        try {
            $outcome = DB::transaction(function () use ($session, $asset, $clientResultId, $operationId, $baseVersion, $forceRecheck, $request): array {
                $operation = null;
                if ($operationId !== '') {
                    $operationStart = $this->extinguisherOperationService->begin(
                        operationUid: $operationId,
                        session: $session,
                        assetKey: $asset['canonicalAssetKey'],
                        operationType: 'complete',
                        actorUserId: (int) $request->user()->id,
                        baseVersion: $baseVersion,
                        payload: $asset['checkPayload'],
                    );
                    $operation = $operationStart['operation'];
                    if ($operationStart['idReused']) {
                        return [
                            'kind' => 'conflict',
                            'code' => 'inspection_operation_id_reused',
                            'message' => 'This inspection operation ID was already used for different work.',
                            'data' => null,
                            'operationId' => $operationId,
                        ];
                    }
                    if ($operationStart['replayed']) {
                        return [
                            'kind' => $operation->status === 'succeeded' ? 'replay' : 'conflict',
                            'code' => $operation->status === 'succeeded'
                                ? 'inspection_operation_replayed'
                                : (string) $operation->outcome_code,
                            'message' => $operation->status === 'succeeded'
                                ? 'Inspection operation already applied.'
                                : 'Inspection operation previously conflicted.',
                            'data' => $operation->response_payload,
                            'operationId' => $operationId,
                            'resultVersion' => $operation->result_version,
                        ];
                    }
                }

                $existing = $session->extinguisherResults()
                    ->lockForUpdate()
                    ->where('canonical_asset_key', $asset['canonicalAssetKey'])
                    ->first();

                $isExactLegacyReplay = $operationId === ''
                    && $existing
                    && $clientResultId !== ''
                    && $existing->client_result_id === $clientResultId
                    && $baseVersion === 0;
                if ($isExactLegacyReplay) {
                    if ($existing->status === 'completed') {
                        $this->sessionProgressService->sync($session, $request->user()?->id, $asset);
                    }

                    return [
                        'kind' => 'legacy_replay',
                        'data' => $this->formatResult($existing->load('checkedBy')),
                        'resultVersion' => (int) $existing->version,
                    ];
                }

                $isCompletedByAnotherUser = $existing
                    && $existing->status === 'completed'
                    && (int) $existing->checked_by_user_id !== (int) $request->user()->id;

                if ($isCompletedByAnotherUser && ! $forceRecheck) {
                    $existing->load('checkedBy');
                    $data = $this->formatResult($existing);
                    if ($operation) {
                        $this->extinguisherOperationService->conflict(
                            $operation,
                            'inspection_result_completed_by_other_user',
                            (int) $existing->version,
                            $data,
                        );
                    }

                    return [
                        'kind' => 'conflict',
                        'code' => 'inspection_result_completed_by_other_user',
                        'message' => 'This fire extinguisher was already inspected by another user.',
                        'data' => $data,
                        'operationId' => $operationId,
                    ];
                }

                if ($existing && $baseVersion > 0 && (int) $existing->version !== $baseVersion) {
                    $existing->load('checkedBy');
                    $data = $this->formatResult($existing);
                    if ($operation) {
                        $this->extinguisherOperationService->conflict(
                            $operation,
                            'inspection_result_version_conflict',
                            (int) $existing->version,
                            $data,
                        );
                    }

                    return [
                        'kind' => 'conflict',
                        'code' => 'inspection_result_version_conflict',
                        'message' => 'This fire extinguisher result changed since it was loaded.',
                        'data' => $data,
                        'operationId' => $operationId,
                    ];
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
                    $this->reportMediaService->syncPayloadLinks((array) $existing->check_payload, 'inspection_result', (string) $existing->id, (int) $request->user()->id, 'inspection');

                    $data = $this->formatResult($existing->load('checkedBy'));
                    if ($operation) {
                        $this->extinguisherOperationService->succeed(
                            $operation,
                            (int) $existing->version,
                            $data,
                        );
                    }
                    $session->increment('version');

                    return [
                        'kind' => 'applied',
                        'data' => $data,
                        'resultVersion' => (int) $existing->version,
                        'operationId' => $operationId,
                    ];
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
                $this->reportMediaService->syncPayloadLinks((array) $created->check_payload, 'inspection_result', (string) $created->id, (int) $request->user()->id, 'inspection');

                $created->refresh()->load('checkedBy');
                $data = $this->formatResult($created);
                if ($operation) {
                    $this->extinguisherOperationService->succeed(
                        $operation,
                        (int) $created->version,
                        $data,
                    );
                }
                $session->increment('version');

                return [
                    'kind' => 'applied',
                    'data' => $data,
                    'resultVersion' => (int) $created->version,
                    'operationId' => $operationId,
                ];
            });
        } catch (ValidationException $exception) {
            $existing = $session->extinguisherResults()
                ->with('checkedBy')
                ->where('canonical_asset_key', $asset['canonicalAssetKey'])
                ->first();

            return response()->json([
                'message' => $exception->getMessage() ?: 'The inspection result is invalid.',
                'code' => 'inspection_payload_invalid',
                'errors' => $exception->errors(),
                'data' => $existing ? $this->formatResult($existing) : null,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (QueryException $exception) {
            Log::warning('inspection_extinguisher_result_write_conflict', [
                'session_uid' => $session->session_uid,
                'asset_key' => $asset['canonicalAssetKey'],
                'operation_id' => $operationId ?: null,
                'exception' => $exception->getCode(),
            ]);
            $existing = $session->extinguisherResults()
                ->with('checkedBy')
                ->where('canonical_asset_key', $asset['canonicalAssetKey'])
                ->first();

            return response()->json([
                'message' => 'The inspection result could not be written because it changed concurrently.',
                'code' => 'inspection_result_write_conflict',
                'data' => $existing ? $this->formatResult($existing) : null,
            ], Response::HTTP_CONFLICT);
        }

        if (($outcome['kind'] ?? '') === 'conflict') {
            return response()->json([
                'message' => $outcome['message'] ?? 'Inspection result conflict.',
                'code' => $outcome['code'] ?? 'inspection_extinguisher_result_conflict',
                'data' => $outcome['data'] ?? null,
                'operation' => $this->operationMeta($outcome, false),
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'data' => $outcome['data'] ?? null,
            'meta' => $this->sessionProgressService->progress($session->refresh()),
            'operation' => $this->operationMeta(
                $outcome,
                ($outcome['kind'] ?? '') === 'replay',
            ),
        ]);
    }

    public function reset(Request $request, string $sessionUid, string $extinguisherId): JsonResponse
    {
        $this->ensureEnabled();
        $this->ensureInspectionConductPermission($request);
        $session = $this->findWritableSession($request, $sessionUid);
        $this->dutyConfirmations->consume($request, 'session-write', $session->session_uid, self::FIRE_EXTINGUISHER_TYPE_KEY);
        $data = $request->validate([
            'checkPayload' => ['nullable', 'array'],
            'check_payload' => ['nullable', 'array'],
            'operationId' => ['nullable', 'string', 'max:190'],
            'operation_id' => ['nullable', 'string', 'max:190'],
            'baseVersion' => ['nullable', 'integer', 'min:0'],
            'base_version' => ['nullable', 'integer', 'min:0'],
        ]);
        $payload = $this->resultPayloadFromRequest($request);
        $asset = $this->resolveAsset($extinguisherId, $payload);
        $operationId = $this->text($data['operationId'] ?? $data['operation_id'] ?? '');
        $baseVersion = (int) ($data['baseVersion'] ?? $data['base_version'] ?? 0);

        try {
            $outcome = DB::transaction(function () use ($session, $asset, $operationId, $baseVersion, $request): array {
                $operation = null;
                if ($operationId !== '') {
                    $operationStart = $this->extinguisherOperationService->begin(
                        operationUid: $operationId,
                        session: $session,
                        assetKey: $asset['canonicalAssetKey'],
                        operationType: 'reset',
                        actorUserId: (int) $request->user()->id,
                        baseVersion: $baseVersion,
                        payload: $asset['checkPayload'],
                    );
                    $operation = $operationStart['operation'];
                    if ($operationStart['idReused']) {
                        return [
                            'kind' => 'conflict',
                            'code' => 'inspection_operation_id_reused',
                            'message' => 'This inspection operation ID was already used for different work.',
                            'data' => null,
                            'operationId' => $operationId,
                        ];
                    }
                    if ($operationStart['replayed']) {
                        return [
                            'kind' => $operation->status === 'succeeded' ? 'replay' : 'conflict',
                            'code' => $operation->status === 'succeeded'
                                ? 'inspection_operation_replayed'
                                : (string) $operation->outcome_code,
                            'message' => $operation->status === 'succeeded'
                                ? 'Inspection operation already applied.'
                                : 'Inspection operation previously conflicted.',
                            'data' => $operation->response_payload,
                            'operationId' => $operationId,
                            'resultVersion' => $operation->result_version,
                        ];
                    }
                }

                $existing = $session->extinguisherResults()
                    ->lockForUpdate()
                    ->where('canonical_asset_key', $asset['canonicalAssetKey'])
                    ->first();

                if (! $existing) {
                    $this->sessionProgressService->sync($session, $request->user()?->id, $asset);
                    if ($operation) {
                        $this->extinguisherOperationService->succeed($operation, null, null);
                    }
                    $session->increment('version');

                    return [
                        'kind' => 'applied',
                        'data' => null,
                        'resultVersion' => null,
                        'operationId' => $operationId,
                    ];
                }

                if ($baseVersion > 0 && (int) $existing->version !== $baseVersion) {
                    $data = $this->formatResult($existing->load('checkedBy'));
                    if ($operation) {
                        $this->extinguisherOperationService->conflict(
                            $operation,
                            'inspection_result_version_conflict',
                            (int) $existing->version,
                            $data,
                        );
                    }

                    return [
                        'kind' => 'conflict',
                        'code' => 'inspection_result_version_conflict',
                        'message' => 'This fire extinguisher result changed since it was loaded.',
                        'data' => $data,
                        'operationId' => $operationId,
                    ];
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
                $this->reportMediaService->syncPayloadLinks(
                    (array) $existing->check_payload,
                    'inspection_result',
                    (string) $existing->id,
                    (int) $request->user()->id,
                    'inspection',
                );

                $data = $this->formatResult($existing->load('checkedBy'));
                if ($operation) {
                    $this->extinguisherOperationService->succeed(
                        $operation,
                        (int) $existing->version,
                        $data,
                    );
                }
                $session->increment('version');

                return [
                    'kind' => 'applied',
                    'data' => $data,
                    'resultVersion' => (int) $existing->version,
                    'operationId' => $operationId,
                ];
            });
        } catch (ValidationException $exception) {
            $existing = $session->extinguisherResults()
                ->with('checkedBy')
                ->where('canonical_asset_key', $asset['canonicalAssetKey'])
                ->first();

            return response()->json([
                'message' => $exception->getMessage() ?: 'The inspection reset is invalid.',
                'code' => 'inspection_payload_invalid',
                'errors' => $exception->errors(),
                'data' => $existing ? $this->formatResult($existing) : null,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (($outcome['kind'] ?? '') === 'conflict') {
            return response()->json([
                'message' => $outcome['message'] ?? 'Inspection result conflict.',
                'code' => $outcome['code'] ?? 'inspection_extinguisher_result_conflict',
                'data' => $outcome['data'] ?? null,
                'operation' => $this->operationMeta($outcome, false),
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'data' => $outcome['data'] ?? null,
            'meta' => $this->sessionProgressService->progress($session->refresh()),
            'operation' => $this->operationMeta(
                $outcome,
                ($outcome['kind'] ?? '') === 'replay',
            ),
        ]);
    }

    public function submit(Request $request, string $sessionUid): JsonResponse
    {
        $this->ensureEnabled();
        $this->ensureInspectionConductPermission($request);
        $session = $this->findReadableSession($request, $sessionUid);
        $user = $request->user();
        $data = $request->validate([
            'session_version' => ['nullable', 'integer', 'min:1'],
            'sessionVersion' => ['nullable', 'integer', 'min:1'],
            'report_remarks' => ['nullable', 'string', 'max:5000'],
            'reportRemarks' => ['nullable', 'string', 'max:5000'],
            'photos' => ['nullable', 'array', 'max:10'],
        ]);
        $expectedSessionVersion = (int) ($data['session_version'] ?? $data['sessionVersion'] ?? 0);
        $submissionKey = $this->text($request->input('submission_key', ''));
        $submittedAt = $this->submittedAtFromRequest($request);
        $reportRemarks = $this->text($data['report_remarks'] ?? $data['reportRemarks'] ?? '');
        $reportPhotos = is_array($data['photos'] ?? null) ? $data['photos'] : [];

        if ($submissionKey !== '') {
            $existing = Report::query()
                ->where('owner_user_id', $user->id)
                ->where('submission_key', $submissionKey)
                ->first();
            if ($existing) {
                $this->reportMediaService->syncPayloadLinks(
                    is_array($existing->payload) ? $existing->payload : [],
                    'report',
                    (string) $existing->report_uid,
                    (int) $existing->owner_user_id,
                    'inspection',
                );

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

        $submissionDecision = $this->inspectionPolicy->canSubmit($user);
        if (! $submissionDecision->allowed) {
            throw ValidationException::withMessages(['workflow' => [$submissionDecision->message]]);
        }
        $this->ensureCanSubmitSession($request, $session);
        $dutyContext = $this->dutyConfirmations->consume(
            $request,
            'session-submit',
            $session->session_uid,
            self::FIRE_EXTINGUISHER_TYPE_KEY,
        );

        $submission = DB::transaction(function () use ($session, $expectedSessionVersion, $submissionKey, $request, $user, $submittedAt, $dutyContext, $reportRemarks, $reportPhotos): array {
            $lockedSession = InspectionSession::query()->lockForUpdate()->findOrFail($session->id);
            $lockedSession->fill([
                'duty_context_status' => $dutyContext['status'] ?? null,
                'duty_context_version' => $dutyContext['contextVersion'] ?? null,
                'duty_source_version' => $dutyContext['sourceVersion'] ?? null,
                'duty_context_snapshot' => $dutyContext,
            ]);
            if ($submissionKey !== '') {
                $existing = Report::query()
                    ->where('owner_user_id', $user->id)
                    ->where('submission_key', $submissionKey)
                    ->first();
                if ($existing) {
                    return ['report' => $existing, 'replayed' => true];
                }
            }
            if ($lockedSession->status !== self::ACTIVE_STATUS) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Only active inspection sessions can be submitted.',
                    'code' => 'inspection_session_not_active',
                ], Response::HTTP_CONFLICT));
            }
            if ($expectedSessionVersion > 0 && (int) $lockedSession->version !== $expectedSessionVersion) {
                throw new HttpResponseException(response()->json([
                    'message' => 'The inspection session changed before submission. Refresh and review the latest results.',
                    'code' => 'inspection_session_version_conflict',
                    'currentVersion' => (int) $lockedSession->version,
                ], Response::HTTP_CONFLICT));
            }
            if ($lockedSession->extinguisherOperations()->where('status', 'pending')->exists()) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Inspection changes are still being processed. Retry sync before submitting.',
                    'code' => 'inspection_session_operations_pending',
                ], Response::HTTP_CONFLICT));
            }
            $completedResults = $lockedSession->extinguisherResults()
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

            $payload = $this->sessionReportPayloadBuilder->build($lockedSession, $completedResults, $submittedAt);
            $payload['reportRemarks'] = $reportRemarks;
            $payload['photos'] = $reportPhotos;
            $this->inspectionPayloadService->validateForSubmit($payload);
            $payload = $this->inspectionPayloadService->normalize($payload);
            $payload = $this->sessionReportPayloadBuilder->normalizeDerivedFields($payload);
            $storedSubmittedAt = $submittedAt->copy()->setTimezone(config('app.timezone', 'UTC'));
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
                ...(($lockedSession->scope_version ?: 'legacy') === 'v2'
                    ? ['scope_team_id' => ((int) data_get($lockedSession->scope, 'teamId', 0)) ?: null]
                    : []),
                'report_type' => 'inspection',
                'status' => 'Submitted',
                'version' => 1,
                'revision' => 1,
                'payload' => $payload,
                'inspection_checklist_item_ids' => [],
                'inspection_checklist_item_labels' => [],
                'inspection_has_checklist' => true,
                'submitted_at' => $storedSubmittedAt,
                'duty_context_status' => $dutyContext['status'] ?? null,
                'duty_context_version' => $dutyContext['contextVersion'] ?? null,
                'duty_source_version' => $dutyContext['sourceVersion'] ?? null,
                'duty_context_snapshot' => $dutyContext,
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
                'meta' => ['inspectionSessionUid' => $lockedSession->session_uid],
            ]);

            $lockedSession->update([
                'status' => 'submitted',
                'submitted_by_user_id' => $user->id,
                'submitted_report_uid' => $report->report_uid,
                'submitted_at' => $storedSubmittedAt,
                'version' => ((int) $lockedSession->version) + 1,
                'duty_context_status' => $dutyContext['status'] ?? null,
                'duty_context_version' => $dutyContext['contextVersion'] ?? null,
                'duty_source_version' => $dutyContext['sourceVersion'] ?? null,
                'duty_context_snapshot' => $dutyContext,
            ]);
            $lockedSession->scopeClaim()->delete();
            $this->recordEvent($lockedSession, null, 'session.submitted', $request, [
                'reportUid' => $report->report_uid,
            ]);

            $this->inspectionCheckRowSyncService->syncForReport($report->refresh(), (int) $user->id);
            $this->reportMediaService->syncPayloadLinks($payload, 'report', (string) $report->report_uid, (int) $user->id, 'inspection');

            return ['report' => $report->load('timelineEntries'), 'replayed' => false];
        });
        /** @var Report $report */
        $report = $submission['report'];
        $idempotentReplay = $submission['replayed'] === true;

        if ($idempotentReplay) {
            $this->reportMediaService->syncPayloadLinks(
                is_array($report->payload) ? $report->payload : [],
                'report',
                (string) $report->report_uid,
                (int) $report->owner_user_id,
                'inspection',
            );
        }

        if (! $idempotentReplay) {
            try {
                $this->workflowNotificationService->emit(
                    module: 'inspection',
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
                        'module' => 'inspection',
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
        }

        return response()->json([
            'data' => [
                'reportUid' => $report->report_uid,
                'displayId' => $report->display_id,
                'sessionUid' => $session->session_uid,
                'idempotentReplay' => $idempotentReplay,
            ],
        ], $idempotentReplay ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    private function findReadableSession(Request $request, string $sessionUid): InspectionSession
    {
        $session = InspectionSession::query()->where('session_uid', $sessionUid)->firstOrFail();
        $user = $request->user();
        $decision = $user ? $this->inspectionPolicy->canReadSession($session, $user) : null;
        if ($decision?->allowed) {
            return $session;
        }

        throw new HttpResponseException(response()->json([
            'message' => $decision?->message ?? 'This inspection session is not available.',
            'code' => $decision?->reasonCode ?? 'inspection_session_team_forbidden',
        ], Response::HTTP_FORBIDDEN));
    }

    private function findWritableSession(Request $request, string $sessionUid): InspectionSession
    {
        $session = $this->findReadableSession($request, $sessionUid);
        $user = $request->user();
        $decision = $user ? $this->inspectionPolicy->canWriteSession($session, $user) : null;
        if ($decision?->reasonCode === 'inspection_session_closed') {
            throw new HttpResponseException(response()->json([
                'message' => $decision->message,
                'code' => $decision->reasonCode,
                'data' => $this->formatSession($session),
            ], Response::HTTP_CONFLICT));
        }
        if (! $decision?->allowed) {
            throw new HttpResponseException(response()->json([
                'message' => $decision?->message ?? 'This inspection session cannot be changed.',
                'code' => $decision?->reasonCode ?? 'inspection_session_write_forbidden',
            ], Response::HTTP_FORBIDDEN));
        }

        return $session;
    }

    private function resultPayloadFromRequest(Request $request): array
    {
        $payload = $request->input('checkPayload', $request->input('check_payload', []));

        return is_array($payload) ? $payload : [];
    }

    private function operationMeta(array $outcome, bool $replayed): ?array
    {
        $operationId = $this->text($outcome['operationId'] ?? '');
        if ($operationId === '') {
            return null;
        }

        return [
            'operationId' => $operationId,
            'code' => $outcome['code'] ?? ($replayed
                ? 'inspection_operation_replayed'
                : 'inspection_operation_applied'),
            'replayed' => $replayed,
            'resultVersion' => $outcome['resultVersion'] ?? null,
        ];
    }

    private function resolveAsset(string $extinguisherId, array $payload): array
    {
        $catalogId = (int) ($payload['catalogId'] ?? $payload['catalog_id'] ?? 0);
        $routeId = ctype_digit($extinguisherId) ? (int) $extinguisherId : 0;
        $fireExtinguisherId = $catalogId > 0 ? $catalogId : ($routeId > 0 ? $routeId : null);
        $catalog = $fireExtinguisherId
            ? InspectionFireExtinguisher::query()
                ->where('is_active', true)
                ->where('lifecycle_status', 'active')
                ->find($fireExtinguisherId)
            : null;

        if ($fireExtinguisherId && ! $catalog) {
            throw ValidationException::withMessages([
                'extinguisher' => ['This fire extinguisher is not active and cannot be inspected.'],
            ]);
        }

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
            if ($remarks === '') {
                throw ValidationException::withMessages([
                    "checkPayload.{$meta['remarks']}" => ['Fire extinguisher remarks are required for defect or failed statuses.'],
                ]);
            }
        }
    }

    private function isFireExtinguisherDefectStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['not good', 'no', 'not operational'], true);
    }

    private function submittedAtFromRequest(Request $request): Carbon
    {
        $value = $this->text(
            $request->input('submitted_at', $request->input('submittedAt', ''))
                ?: $request->input('inspected_at', $request->input('inspectedAt', ''))
        );

        if ($value !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                throw ValidationException::withMessages([
                    'submitted_at' => ['Submitted timestamp must be a valid date and time.'],
                ]);
            }
        }

        return now();
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

    private function formatHydratedSessionForActor(
        InspectionSession $session,
        array $scope,
        Request $request,
    ): array {
        return array_merge(
            $this->formatHydratedSession($session, $scope),
            ['permissions' => $this->sessionPermissions($session, $request->user())],
        );
    }

    private function formatSessionForActor(InspectionSession $session, Request $request): array
    {
        return array_merge(
            $this->formatSession($session),
            ['permissions' => $this->sessionPermissions($session, $request->user())],
        );
    }

    private function sessionPermissions(InspectionSession $session, ?User $user): array
    {
        if (! $user) {
            return ['canWrite' => false, 'canSubmit' => false];
        }

        return [
            'canWrite' => $this->inspectionPolicy->canWriteSession($session, $user)->allowed,
            'canSubmit' => $this->inspectionPolicy->canSubmitSession($session, $user)->allowed,
        ];
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
            'scopeVersion' => $session->scope_version ?: 'legacy',
            'scopeKey' => $session->scope_key,
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
            'scopeVersion' => strtolower($this->text($data['scopeVersion'] ?? $data['scope_version'] ?? 'legacy')),
            'siteKey' => $this->text($data['siteKey'] ?? $data['site_key'] ?? ''),
            'inspectionDate' => $this->text($data['inspectionDate'] ?? $data['inspection_date'] ?? ''),
            'shiftKey' => $this->text($data['shiftKey'] ?? $data['shift_key'] ?? ''),
            'batchKey' => $this->text($data['batchKey'] ?? $data['batch_key'] ?? ''),
            'teamId' => (int) ($data['teamId'] ?? $data['team_id'] ?? 0),
        ];
    }

    private function ensureInspectionPermission(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! $this->authorizationService->hasPermission($user, 'reports.manage|reports.inspection.view')) {
            abort(403, 'Missing inspection report permission.');
        }
    }

    private function ensureInspectionConductPermission(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! $this->authorizationService->hasPermission($user, 'reports.manage|reports.inspection.conduct')) {
            abort(403, 'Missing permission to conduct inspections.');
        }
    }

    private function ensureCanSubmitSession(Request $request, InspectionSession $session): void
    {
        $user = $request->user();
        $decision = $user ? $this->inspectionPolicy->canSubmitSession($session, $user) : null;
        if ($decision?->allowed) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => $decision?->message ?? 'This inspection session cannot be submitted.',
            'code' => $decision?->reasonCode ?? 'inspection_session_submit_forbidden',
        ], Response::HTTP_FORBIDDEN));
    }

    private function ensureEnabled(): void
    {
        if (! config('inspection.session_fire_extinguisher_enabled', true)) {
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
