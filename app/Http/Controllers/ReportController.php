<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\ReportTimelineEntry;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\WorkflowNotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function __construct(
        private readonly WorkflowNotificationService $notificationService,
        private readonly AssignmentAuthorizationService $authorizationService,
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

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $reportTypeFilter = trim((string) $request->input('reportType', ''));
        if (strtolower($reportTypeFilter) === 'inspection') {
            $this->ensureInspectionPermission($request);
        }
        $query = Report::query()->where('owner_user_id', $user->id)->with('timelineEntries');

        if ($request->filled('reportType') && $request->input('reportType') !== 'All') {
            $query->where('report_type', trim((string) $request->input('reportType')));
        }
        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', trim((string) $request->input('status')));
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
        $report = $this->findOwnedReport($request, $reportUid);

        return response()->json(['data' => $this->formatReport($report)]);
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
        if (strtolower(trim((string) ($data['report_type'] ?? ''))) === 'inspection') {
            $this->ensureInspectionPermission($request);
            $this->validateInspectionPayload((array) $data['payload']);
        }
        $action = $status === self::STATUS_DRAFT ? 'DraftSaved' : 'Submitted';
        $submissionKey = trim((string) ($data['submission_key'] ?? ''));

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
            $report = DB::transaction(function () use ($data, $status, $action, $submissionKey, $user) {
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
                    'submitted_at' => $status === self::STATUS_SUBMITTED ? now() : null,
                ]);

                $this->appendTimeline(
                    report: $report,
                    action: $action,
                    fromStatus: null,
                    toStatus: $status,
                    userId: (int) $user->id,
                    byName: (string) $user->name,
                    remarks: (string) ($data['remarks'] ?? ''),
                );

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
            actionRequired: false,
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
            ], 409);
        }

        $targetStatus = (string) ($data['status'] ?? self::STATUS_SUBMITTED);
        if (strtolower(trim((string) ($report->report_type ?? ''))) === 'inspection') {
            $this->ensureInspectionPermission($request);
            $this->validateInspectionPayload((array) $data['payload']);
        }
        if (!in_array($report->status, [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Report cannot be edited in its current status.'],
            ]);
        }

        $nextRevision = (int) $report->revision + 1;
        $nextVersion = (int) $report->version + 1;

        DB::transaction(function () use ($report, $data, $targetStatus, $nextRevision, $nextVersion, $user) {
            $fromStatus = $report->status;
            $report->update([
                'payload' => $data['payload'],
                'status' => $targetStatus,
                'revision' => $nextRevision,
                'version' => $nextVersion,
                'submitted_at' => $targetStatus === self::STATUS_SUBMITTED ? now() : $report->submitted_at,
                'reviewed_at' => null,
                'approved_at' => null,
            ]);

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
            eventType: 'edited',
            report: $report,
            actor: $user,
            actionRequired: false,
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
            allowedFrom: [self::STATUS_REVIEWED],
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
            ->where('owner_user_id', $user->id)
            ->firstOrFail();
        if (strtolower(trim((string) ($report->report_type ?? ''))) === 'inspection') {
            $this->ensureInspectionPermission($request);
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

        DB::transaction(function () use ($report, $toStatus, $action, $payload, $user) {
            $fromStatus = $report->status;
            $nextVersion = (int) $report->version + 1;
            $update = [
                'status' => $toStatus,
                'version' => $nextVersion,
            ];
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
            actionRequired: false,
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
            'status' => $report->status,
            'version' => (int) $report->version,
            'revision' => (int) $report->revision,
            'timeline' => $history,
            'createdAt' => optional($report->created_at)->toIso8601String(),
            'updatedAt' => optional($report->updated_at)->toIso8601String(),
        ]);
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
            targetUserIds: [(int) $report->owner_user_id],
            actionRequired: $actionRequired,
            remarks: $remarks,
            metadata: [
                'module' => $module,
                'status' => $report->status,
                'workflowStage' => strtolower($report->status) === 'submitted' ? 'review' : 'done',
                'nextActionRole' => null,
                'reportType' => $report->report_type,
                'reportUid' => $report->report_uid,
                'detailRouteKey' => $report->report_uid,
            ],
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

    private function validateInspectionPayload(array $payload): void
    {
        $payloadJson = json_encode($payload);
        if ($payloadJson !== false && strlen($payloadJson) > self::INSPECTION_MAX_TOTAL_PHOTO_BYTES * 2) {
            throw ValidationException::withMessages([
                'payload' => ['Inspection payload is too large. Please reduce photo count/size.'],
            ]);
        }

        $photos = is_array($payload['photos'] ?? null) ? $payload['photos'] : [];
        if (count($photos) > self::INSPECTION_MAX_PHOTO_COUNT) {
            throw ValidationException::withMessages([
                'payload.photos' => ['Maximum 10 photos are allowed for inspection reports.'],
            ]);
        }

        $totalPhotoBytes = 0;
        foreach ($photos as $index => $photo) {
            if (!is_array($photo)) {
                throw ValidationException::withMessages([
                    "payload.photos.{$index}" => ['Invalid photo payload.'],
                ]);
            }

            $url = trim((string) ($photo['url'] ?? ''));
            if ($url === '') {
                throw ValidationException::withMessages([
                    "payload.photos.{$index}.url" => ['Photo URL is required.'],
                ]);
            }

            if (!preg_match('/^data:image\/([a-z0-9.+-]+);base64,([a-z0-9+\/=\r\n]+)$/i', $url, $match)) {
                throw ValidationException::withMessages([
                    "payload.photos.{$index}.url" => [
                        'Photo must be an inline base64 data URL image.',
                    ],
                ]);
            }

            $imageMime = strtolower(trim((string) ($match[1] ?? '')));
            if (!in_array($imageMime, self::INSPECTION_ALLOWED_IMAGE_MIMES, true)) {
                throw ValidationException::withMessages([
                    "payload.photos.{$index}.url" => [
                        'Only jpeg, png, and webp images are allowed.',
                    ],
                ]);
            }

            $base64Data = preg_replace('/\s+/u', '', (string) ($match[2] ?? ''));
            $decoded = base64_decode($base64Data, true);
            if ($decoded === false) {
                throw ValidationException::withMessages([
                    "payload.photos.{$index}.url" => ['Invalid base64 image data.'],
                ]);
            }

            $photoBytes = strlen($decoded);
            if ($photoBytes > self::INSPECTION_MAX_PHOTO_BYTES) {
                throw ValidationException::withMessages([
                    "payload.photos.{$index}.url" => ['Each photo must be 1.5 MB or smaller.'],
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
}
