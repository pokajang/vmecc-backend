<?php

namespace App\Http\Controllers;

use App\Models\OvertimeRecord;
use App\Models\User;
use App\Models\WorkflowAttachment;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\HolidayGuidanceFeatureGate;
use App\Services\HolidayGuidanceTelemetry;
use App\Services\HolidayResolver;
use App\Services\OvertimeClaimGuardService;
use App\Services\OvertimeDateClassifier;
use App\Services\OvertimeEligibilityService;
use App\Services\OvertimeWorkflowService;
use App\Services\PayrollOvertimeSnapshotIntegrityService;
use App\Services\WorkflowNotificationService;
use App\Services\WorkflowRecipientResolver;
use App\Services\WorkflowSubmissionContextResolver;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OvertimeController extends Controller
{
    public function __construct(
        private readonly OvertimeWorkflowService $workflowService,
        private readonly WorkflowNotificationService $notificationService,
        private readonly WorkflowRecipientResolver $recipientResolver,
        private readonly WorkflowSubmissionContextResolver $submissionContextResolver,
        private readonly AssignmentAuthorizationService $authorizationService,
        private readonly OvertimeEligibilityService $overtimeEligibilityService,
        private readonly OvertimeClaimGuardService $claimGuardService,
        private readonly PayrollOvertimeSnapshotIntegrityService $payrollOvertimeIntegrity,
        private readonly OvertimeDateClassifier $overtimeDateClassifier,
        private readonly HolidayResolver $holidayResolver,
        private readonly HolidayGuidanceFeatureGate $guidanceGate,
        private readonly HolidayGuidanceTelemetry $guidanceTelemetry,
    ) {}

    public function policy(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        try {
            $policy = $this->workflowService->loadApprovalRules();
        } catch (QueryException) {
            $policy = $this->workflowService->normalizeApprovalRules([]);
        }

        return response()->json(['data' => $policy]);
    }

    public function eligibility(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->overtimeEligibilityService->resolveForUser($request->user()),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = OvertimeRecord::query()->where('user_id', $user->id)->with('attachment');

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($term) {
                $builder->where('display_id', 'like', "%{$term}%")
                    ->orWhere('reason', 'like', "%{$term}%")
                    ->orWhere('overtime_type', 'like', "%{$term}%");
            });
        }

        if ($request->filled('month') && preg_match('/^\d{4}-\d{2}$/', (string) $request->input('month'))) {
            [$yearRaw, $monthRaw] = explode('-', (string) $request->input('month'));
            $year = (int) $yearRaw;
            $month = (int) $monthRaw;
            if ($year > 0 && $month >= 1 && $month <= 12) {
                $query->whereYear('claim_date', $year)->whereMonth('claim_date', $month);
            }
        }

        $sort = explode(':', (string) $request->input('sort', 'applied_at:desc'));
        $sortCol = in_array($sort[0] ?? '', ['applied_at', 'claim_date', 'duration_minutes', 'status'], true)
            ? $sort[0]
            : 'applied_at';
        $sortDir = ($sort[1] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $rows = $query->orderBy($sortCol, $sortDir)->orderByDesc('id')->get()
            ->map(fn (OvertimeRecord $row) => self::formatRecord($row));

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $row = OvertimeRecord::query()->where('user_id', $user->id)->with('attachment')->findOrFail($id);

        return response()->json(['data' => self::formatRecord($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validatePayload($request, (int) $user->id, validateWindow: false);
        $submissionKey = trim((string) ($data['submission_key'] ?? ''));
        if ($submissionKey !== '') {
            $existing = OvertimeRecord::query()
                ->where('user_id', $user->id)
                ->where('submission_key', $submissionKey)
                ->with('attachment')
                ->first();
            if ($existing) {
                $this->assertSubmissionReplayMatches($existing, $data);

                return response()->json([
                    'data' => self::formatRecord($existing),
                    'idempotent_replay' => true,
                ]);
            }
        }
        $effectiveAt = $this->workflowEffectiveAt($data['claim_date'] ?? null, $data['start_time'] ?? null);
        $eligibilityResponse = $this->guardIneligibleUser($user, $effectiveAt);
        if ($eligibilityResponse) {
            return $eligibilityResponse;
        }
        try {
            $derivedOvertimeType = $this->overtimeDateClassifier->classify($user, $data['claim_date']);
        } catch (\Throwable $exception) {
            $this->guidanceTelemetry->recordLookupFailure([
                'module' => 'overtime',
                'endpoint' => 'store',
                'user_id' => $user?->id,
                'error' => $exception->getMessage(),
            ]);
            $derivedOvertimeType = (string) ($data['overtime_type'] ?? 'weekday');
        }

        $roles = $this->authorizationService->getActiveRoleNames($user)->all();
        $context = $this->submissionContextResolver->resolve($user, $effectiveAt);
        $workflow = $this->workflowService->buildWorkflowForSubmission(array_values(array_unique(array_filter([
            $context['applicantRole'] ?? null,
            ...$roles,
        ]))));
        $workflow['workflowSnapshot']['workflowContext'] = $context;
        $recipientIds = $this->resolveStageRecipientIds($workflow['nextActionRole'], $context['teamId'] ?? null, now(), (int) $user->id);

        $entry = [
            'id' => (string) Str::uuid(),
            'at' => now()->toIso8601String(),
            'action' => 'Submitted',
            'by' => (string) ($user->name ?? ''),
            'byUserId' => (string) $user->id,
            'remarks' => '',
        ];

        $idempotentReplay = false;
        try {
            $row = DB::transaction(function () use ($data, $user, $workflow, $context, $entry, $recipientIds, $submissionKey, &$idempotentReplay) {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                if ($submissionKey !== '') {
                    $existing = OvertimeRecord::query()
                        ->where('user_id', $user->id)
                        ->where('submission_key', $submissionKey)
                        ->with('attachment')
                        ->first();
                    if ($existing) {
                        $this->assertSubmissionReplayMatches($existing, $data);
                        $idempotentReplay = true;

                        return $existing;
                    }
                }
                $this->claimGuardService->validateWindow($data, (int) $user->id);
                $claimDate = (string) ($data['claim_date'] ?? now()->toDateString());
                $displayId = $this->workflowService->generateDisplayId(
                    (int) $user->id,
                    (int) date('Y', strtotime($claimDate)),
                );
                $row = OvertimeRecord::query()->create([
                    'user_id' => $user->id,
                    'display_id' => $displayId,
                    'submission_key' => $submissionKey !== '' ? $submissionKey : null,
                    'overtime_type' => $data['overtime_type'] ?? 'weekday',
                    'claim_date' => $data['claim_date'] ?? null,
                    'start_time' => $data['start_time'] ?? null,
                    'end_time' => $data['end_time'] ?? null,
                    'is_overnight' => (bool) ($data['is_overnight'] ?? false),
                    'duration_minutes' => (int) ($data['duration_minutes'] ?? 0),
                    'reason' => $data['reason'] ?? '',
                    'status' => 'Pending',
                    'applied_at' => now(),
                    'workflow_stage' => $workflow['workflowStage'],
                    'workflow_snapshot' => $workflow['workflowSnapshot'],
                    'workflow_team_id' => $context['teamId'],
                    'workflow_team_name' => $context['teamName'],
                    'workflow_applicant_role' => $context['applicantRole'],
                    'workflow_routing_source' => $context['routingSource'],
                    'duty_coverage_assignment_id' => $context['dutyCoverageAssignmentId'],
                    'next_action_role' => $workflow['nextActionRole'],
                    'applicant_roles' => $workflow['applicantRoles'],
                    'approval_history' => [$entry],
                    'submitted_by' => (string) ($user->name ?? ''),
                    'attachment_id' => $data['attachment_id'] ?? null,
                    'version' => 1,
                ]);

                $this->emitOvertimeNotification(
                    row: $row,
                    actor: $user,
                    eventType: 'submitted',
                    recipientIds: $recipientIds,
                    actionRequired: (bool) $workflow['nextActionRole'],
                    excludeOwner: true,
                );

                return $row;
            });
        } catch (QueryException $exception) {
            if ($submissionKey !== '') {
                $existing = OvertimeRecord::query()
                    ->where('user_id', $user->id)
                    ->where('submission_key', $submissionKey)
                    ->with('attachment')
                    ->first();
                if ($existing) {
                    $this->assertSubmissionReplayMatches($existing, $data);

                    return response()->json([
                        'data' => self::formatRecord($existing),
                        'idempotent_replay' => true,
                    ]);
                }
            }
            throw $exception;
        }

        if (! $idempotentReplay) {
            AuditLogger::log($request, 'overtime_submitted', $user, [
                'overtime_id' => $row->id,
                'display_id' => $row->display_id,
            ]);
        }

        $row->load('attachment');
        $meta = $this->buildClassificationMeta(
            $user,
            $derivedOvertimeType,
            $data['overtime_type'] ?? null,
            'store',
        );

        return response()->json([
            'data' => self::formatRecord($row),
            'meta' => $meta,
            'idempotent_replay' => $idempotentReplay,
        ], $idempotentReplay ? 200 : 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $row = OvertimeRecord::query()->where('user_id', $user->id)->with('attachment')->findOrFail($id);

        if (! $this->canApplicantEdit($row)) {
            throw ValidationException::withMessages([
                'status' => ['Editing is locked after first approval step. Only draft or pre-review pending overtime can be edited.'],
            ]);
        }

        $data = $this->validatePayload($request, (int) $user->id, (int) $row->id);
        $this->assertExpectedVersion($row, $data['expected_version'] ?? null);
        $effectiveAt = $this->workflowEffectiveAt(
            $data['claim_date'] ?? optional($row->claim_date)->toDateString(),
            $data['start_time'] ?? $row->start_time,
        );
        $eligibilityResponse = $this->guardIneligibleUser($user, $effectiveAt);
        if ($eligibilityResponse) {
            return $eligibilityResponse;
        }
        try {
            $derivedOvertimeType = $this->overtimeDateClassifier->classify($user, $data['claim_date']);
        } catch (\Throwable $exception) {
            $this->guidanceTelemetry->recordLookupFailure([
                'module' => 'overtime',
                'endpoint' => 'update',
                'user_id' => $user?->id,
                'error' => $exception->getMessage(),
            ]);
            $derivedOvertimeType = (string) ($data['overtime_type'] ?? $row->overtime_type ?? 'weekday');
        }
        $entry = [
            'id' => (string) Str::uuid(),
            'at' => now()->toIso8601String(),
            'action' => $row->status === 'Needs Correction' ? 'Resubmitted' : 'Edited',
            'by' => (string) ($user->name ?? ''),
            'byUserId' => (string) $user->id,
            'remarks' => $row->status === 'Needs Correction'
                ? 'Overtime claim corrected and resubmitted.'
                : 'Overtime updated and resubmitted.',
        ];

        $history = collect(is_array($row->approval_history) ? $row->approval_history : [])->push($entry)->take(-20)->values()->all();
        $roles = $this->authorizationService->getActiveRoleNames($user)->all();
        $context = $this->submissionContextResolver->resolve($user, $effectiveAt);
        $workflow = $this->workflowService->buildWorkflowForSubmission(array_values(array_unique(array_filter([
            $context['applicantRole'] ?? null,
            ...$roles,
        ]))));
        $workflow['workflowSnapshot']['workflowContext'] = $context;
        $recipientIds = $this->resolveStageRecipientIds($workflow['nextActionRole'], $context['teamId'] ?? null, now(), (int) $user->id);

        $updates = [
            'overtime_type' => $data['overtime_type'] ?? $row->overtime_type,
            'claim_date' => $data['claim_date'] ?? $row->claim_date,
            'start_time' => $data['start_time'] ?? $row->start_time,
            'end_time' => $data['end_time'] ?? $row->end_time,
            'is_overnight' => (bool) ($data['is_overnight'] ?? $row->is_overnight),
            'duration_minutes' => (int) ($data['duration_minutes'] ?? $row->duration_minutes),
            'reason' => $data['reason'] ?? $row->reason,
            'status' => 'Pending',
            'workflow_stage' => $workflow['workflowStage'],
            'workflow_snapshot' => $workflow['workflowSnapshot'],
            'workflow_team_id' => $context['teamId'],
            'workflow_team_name' => $context['teamName'],
            'workflow_applicant_role' => $context['applicantRole'],
            'workflow_routing_source' => $context['routingSource'],
            'duty_coverage_assignment_id' => $context['dutyCoverageAssignmentId'],
            'next_action_role' => $workflow['nextActionRole'],
            'applicant_roles' => $workflow['applicantRoles'],
            'approval_history' => $history,
            'attachment_id' => $data['attachment_id'] ?? $row->attachment_id,
            'version' => ((int) $row->version) + 1,
        ];
        $row = DB::transaction(function () use ($row, $updates, $data, $user, $recipientIds, $workflow) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->claimGuardService->validateWindow($data, (int) $user->id, (int) $row->id);
            $this->updateWithVersion($row, $updates, $data['expected_version'] ?? null);
            $updatedRow = $row->fresh(['attachment']);
            $this->emitOvertimeNotification(
                row: $updatedRow,
                actor: $user,
                eventType: 'edited',
                recipientIds: $recipientIds,
                actionRequired: (bool) $workflow['nextActionRole'],
                excludeOwner: true,
            );

            return $updatedRow;
        });

        AuditLogger::log($request, 'overtime_edited', $user, [
            'overtime_id' => $row->id,
            'display_id' => $row->display_id,
        ]);

        $meta = $this->buildClassificationMeta(
            $user,
            $derivedOvertimeType,
            $data['overtime_type'] ?? null,
            'update',
        );

        return response()->json(['data' => self::formatRecord($row), 'meta' => $meta]);
    }

    private function guardIneligibleUser($user, ?Carbon $effectiveAt = null): ?JsonResponse
    {
        $eligibility = $this->overtimeEligibilityService->resolveForUser($user, $effectiveAt);
        if ($eligibility['eligible'] === true) {
            return null;
        }

        return response()->json([
            'code' => 'OT_NOT_APPLICABLE',
            'message' => 'Your current role is not eligible to submit overtime claims.',
            'data' => $eligibility,
        ], 403);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $expectedVersion = $this->validateExpectedVersion($request);
        $row = DB::transaction(function () use ($user, $id, $expectedVersion): OvertimeRecord {
            $row = OvertimeRecord::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->findOrFail($id);
            $this->assertExpectedVersion($row, $expectedVersion);

            if (! in_array($row->status, ['Draft', 'Cancelled'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only draft or cancelled overtime records can be deleted.'],
                ]);
            }
            $this->payrollOvertimeIntegrity->assertNotLockedByPaidSalary($row);

            $deleted = OvertimeRecord::query()
                ->whereKey($row->id)
                ->where('version', $expectedVersion)
                ->delete();
            if ($deleted === 0) {
                $this->throwVersionConflict($row);
            }

            return $row;
        });

        AuditLogger::log($request, 'overtime_deleted', $user, [
            'overtime_id' => $row->id,
            'display_id' => $row->display_id,
        ]);

        return response()->json(null, 204);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $payload = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
        $row = DB::transaction(function () use ($id, $payload, $user): OvertimeRecord {
            $row = OvertimeRecord::query()
                ->where('user_id', $user->id)
                ->with('attachment')
                ->lockForUpdate()
                ->findOrFail($id);
            if (! in_array($row->status, ['Pending', 'Approved'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending or approved overtime records can be cancelled.'],
                ]);
            }
            $this->assertExpectedVersion($row, $payload['expected_version'] ?? null);
            $this->payrollOvertimeIntegrity->assertNotLockedByPaidSalary($row);

            $recipientIds = $this->resolveStageRecipientIds(
                $row->next_action_role,
                $row->workflow_team_id ? (int) $row->workflow_team_id : null,
                now(),
                (int) $row->user_id,
                false,
            );
            $updates = $this->workflowService->advanceWorkflow(
                $row,
                'cancel',
                (int) $user->id,
                (string) ($user->name ?? ''),
                $payload['remarks'] ?? null,
            );
            $updates['version'] = ((int) $row->version) + 1;
            $this->updateWithVersion($row, $updates, $payload['expected_version'] ?? null);
            $updatedRow = $row->fresh(['attachment']);
            $this->emitOvertimeNotification(
                row: $updatedRow,
                actor: $user,
                eventType: 'cancelled',
                recipientIds: $recipientIds,
                actionRequired: false,
                remarks: $payload['remarks'] ?? null,
                excludeOwner: true,
            );

            return $updatedRow;
        });

        AuditLogger::log($request, 'overtime_cancelled', $user, [
            'overtime_id' => $row->id,
            'display_id' => $row->display_id,
        ]);

        return response()->json(['data' => self::formatRecord($row)]);
    }

    private function validatePayload(
        Request $request,
        int $userId,
        ?int $excludingRecordId = null,
        bool $validateWindow = true,
    ): array {
        $payload = $request->all();
        $payload['start_time'] = $this->normalizeClockTime($payload['start_time'] ?? null);
        $payload['end_time'] = $this->normalizeClockTime($payload['end_time'] ?? null);

        $validated = Validator::make($payload, [
            'overtime_type' => ['required', 'in:weekday,weekend,publicHoliday'],
            'claim_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'is_overnight' => ['nullable', 'boolean'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:3000'],
            'attachment_id' => ['nullable', 'integer', 'exists:workflow_attachments,id'],
            'expected_version' => $excludingRecordId
                ? ['required', 'integer', 'min:1']
                : ['nullable', 'integer', 'min:1'],
            'submission_key' => ['nullable', 'string', 'max:64'],
        ])->validate();
        if ($validateWindow) {
            $this->claimGuardService->validateWindow($validated, $userId, $excludingRecordId);
        }

        if (! empty($validated['attachment_id']) && ! WorkflowAttachment::query()
            ->whereKey($validated['attachment_id'])
            ->where('owner_user_id', $userId)
            ->exists()) {
            throw ValidationException::withMessages([
                'attachment_id' => ['The selected attachment is unavailable for this overtime claim.'],
            ]);
        }

        return $validated;
    }

    public function classifyDate(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'claim_date' => ['required', 'date'],
        ]);

        try {
            $derivedOvertimeType = $this->overtimeDateClassifier->classify($user, $data['claim_date']);
        } catch (\Throwable $exception) {
            $this->guidanceTelemetry->recordLookupFailure([
                'module' => 'overtime',
                'endpoint' => 'classify-date',
                'user_id' => $user?->id,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
        $meta = $this->buildClassificationMeta($user, $derivedOvertimeType, null, 'classify-date');

        return response()->json([
            'data' => [
                'claim_date' => $data['claim_date'],
                'overtime_type' => $derivedOvertimeType,
            ],
            'meta' => $meta,
        ]);
    }

    private function normalizeClockTime(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return $value;
        }

        $raw = trim($value);
        if ($raw === '') {
            return '';
        }

        $formats = [
            'H:i',
            'G:i',
            'H:i:s',
            'G:i:s',
            'h:i A',
            'h:iA',
            'h:i a',
            'h:ia',
            'g:i A',
            'g:iA',
            'g:i a',
            'g:ia',
        ];

        foreach ($formats as $format) {
            $parsed = \DateTime::createFromFormat('!'.$format, $raw);
            $errors = \DateTime::getLastErrors();
            $hasErrors = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);
            if ($parsed !== false && ! $hasErrors) {
                return $parsed->format('H:i');
            }
        }

        return $value;
    }

    private function canApplicantEdit(OvertimeRecord $row): bool
    {
        if ($row->status === 'Draft') {
            return true;
        }

        if ($row->status === 'Needs Correction') {
            return true;
        }

        if ($row->status !== 'Pending') {
            return false;
        }

        return ! $this->hasReachedFirstApprovalStep($row);
    }

    private function hasReachedFirstApprovalStep(OvertimeRecord $row): bool
    {
        $stage = strtolower(trim((string) ($row->workflow_stage ?? '')));
        if ($stage !== '' && $stage !== 'review') {
            return true;
        }

        $history = is_array($row->approval_history) ? $row->approval_history : [];
        foreach ($history as $entry) {
            $action = strtolower(trim((string) ($entry['action'] ?? '')));
            if (in_array($action, ['reviewed', 'recommended', 'approved', 'rejected'], true)) {
                return true;
            }
        }

        return false;
    }

    public static function formatRecord(OvertimeRecord $row): array
    {
        $attachment = $row->relationLoaded('attachment') ? $row->attachment : null;

        return [
            'id' => $row->id,
            'public_id' => $row->public_id,
            'display_id' => $row->display_id,
            'user_id' => $row->user_id,
            'overtime_type' => $row->overtime_type,
            'claim_date' => optional($row->claim_date)->toDateString(),
            'start_time' => self::normalizeTimeForResponse($row->start_time),
            'end_time' => self::normalizeTimeForResponse($row->end_time),
            'is_overnight' => (bool) $row->is_overnight,
            'duration_minutes' => (int) $row->duration_minutes,
            'reason' => $row->reason,
            'status' => $row->status,
            'applied_at' => optional($row->applied_at)->toIso8601String(),
            'workflow_stage' => $row->workflow_stage,
            'workflow_snapshot' => $row->workflow_snapshot ?? [],
            'workflow_team_id' => $row->workflow_team_id,
            'workflow_team_name' => $row->workflow_team_name,
            'workflow_applicant_role' => $row->workflow_applicant_role,
            'workflow_routing_source' => $row->workflow_routing_source,
            'duty_coverage_assignment_id' => $row->duty_coverage_assignment_id,
            'next_action_role' => $row->next_action_role,
            'applicant_roles' => $row->applicant_roles ?? [],
            'approval_history' => $row->approval_history ?? [],
            'submitted_by' => $row->submitted_by,
            'attachment' => $attachment ? [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
            ] : null,
            'created_at' => optional($row->created_at)->toIso8601String(),
            'updated_at' => optional($row->updated_at)->toIso8601String(),
            'version' => (int) ($row->version ?: 1),
        ];
    }

    private function workflowEffectiveAt(mixed $claimDate, mixed $startTime): Carbon
    {
        $date = trim((string) $claimDate) ?: now()->toDateString();
        $time = trim((string) $startTime);
        $time = $time !== '' ? substr($time, 0, 8) : '00:00:00';

        return Carbon::parse("{$date} {$time}");
    }

    private function resolveStageRecipientIds(
        mixed $role,
        ?int $teamId,
        Carbon $effectiveAt,
        int $excludeUserId,
        bool $required = true,
    ): array {
        $role = trim((string) $role);
        if ($role === '') {
            return [];
        }

        $ids = $this->recipientResolver
            ->resolveForWorkflowRole($role, $teamId, $effectiveAt, $excludeUserId)
            ->pluck('userId')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($required && $ids === []) {
            throw ValidationException::withMessages([
                'workflow' => ["No active recipient is available for the '{$role}' workflow stage."],
            ]);
        }

        return $ids;
    }

    private function validateExpectedVersion(Request $request): int
    {
        $payload = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        return (int) $payload['expected_version'];
    }

    private function assertExpectedVersion(OvertimeRecord $row, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && $expectedVersion !== (int) $row->version) {
            $this->throwVersionConflict($row);
        }
    }

    private function updateWithVersion(OvertimeRecord $row, array $updates, ?int $expectedVersion): void
    {
        $currentVersion = (int) $row->version;
        $updated = OvertimeRecord::query()
            ->whereKey($row->id)
            ->where('version', $expectedVersion ?? $currentVersion)
            ->update($updates);
        if ($updated === 0) {
            $this->throwVersionConflict($row);
        }
    }

    private function throwVersionConflict(OvertimeRecord $row): never
    {
        throw new HttpResponseException(response()->json([
            'code' => 'OT_VERSION_CONFLICT',
            'message' => 'This overtime claim changed. Reload the latest record before trying again.',
            'currentVersion' => (int) $row->version,
            'currentRecord' => self::formatRecord($row),
        ], 409));
    }

    private function emitOvertimeNotification(
        OvertimeRecord $row,
        User $actor,
        string $eventType,
        array $recipientIds,
        bool $actionRequired,
        ?string $remarks = null,
        bool $excludeOwner = false,
    ): void {
        $this->notificationService->emit(
            module: 'overtime',
            eventType: $eventType,
            recordType: 'overtime',
            recordId: $row->id,
            recordDisplayId: $row->display_id,
            ownerUserId: (int) $row->user_id,
            actor: ['userId' => $actor->id, 'name' => $actor->name, 'email' => $actor->email],
            targetUserIds: $recipientIds,
            actionRequired: $actionRequired,
            remarks: $remarks,
            metadata: [
                'module' => 'overtime',
                'status' => $row->status,
                'workflowStage' => $row->workflow_stage,
                'nextActionRole' => $row->next_action_role,
                'workflowTeamId' => $row->workflow_team_id,
                'workflowTeamName' => $row->workflow_team_name,
                'workflowRoutingSource' => $row->workflow_routing_source,
                'detailRouteKey' => (string) $row->public_id,
            ],
            excludeOwner: $excludeOwner,
        );
    }

    private function assertSubmissionReplayMatches(OvertimeRecord $record, array $payload): void
    {
        $stored = [
            'overtime_type' => (string) $record->overtime_type,
            'claim_date' => optional($record->claim_date)->toDateString(),
            'start_time' => self::normalizeTimeForResponse($record->start_time),
            'end_time' => self::normalizeTimeForResponse($record->end_time),
            'is_overnight' => (bool) $record->is_overnight,
            'duration_minutes' => (int) $record->duration_minutes,
            'reason' => (string) $record->reason,
            'attachment_id' => $record->attachment_id ? (int) $record->attachment_id : null,
        ];
        $requested = [
            'overtime_type' => (string) ($payload['overtime_type'] ?? ''),
            'claim_date' => (string) ($payload['claim_date'] ?? ''),
            'start_time' => (string) ($payload['start_time'] ?? ''),
            'end_time' => (string) ($payload['end_time'] ?? ''),
            'is_overnight' => (bool) ($payload['is_overnight'] ?? false),
            'duration_minutes' => (int) ($payload['duration_minutes'] ?? 0),
            'reason' => (string) ($payload['reason'] ?? ''),
            'attachment_id' => ! empty($payload['attachment_id'])
                ? (int) $payload['attachment_id']
                : null,
        ];

        if ($stored === $requested) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'code' => 'OT_SUBMISSION_KEY_REUSED',
            'message' => 'This submission key was already used for different overtime data.',
        ], 409));
    }

    private static function normalizeTimeForResponse(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }

        if (! is_string($value)) {
            return null;
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        $formats = [
            'H:i',
            'G:i',
            'H:i:s',
            'G:i:s',
            'h:i A',
            'h:iA',
            'h:i a',
            'h:ia',
            'g:i A',
            'g:iA',
            'g:i a',
            'g:ia',
        ];

        foreach ($formats as $format) {
            $parsed = \DateTime::createFromFormat('!'.$format, $raw);
            $errors = \DateTime::getLastErrors();
            $hasErrors = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);
            if ($parsed !== false && ! $hasErrors) {
                return $parsed->format('H:i');
            }
        }

        return $raw;
    }

    private function buildClassificationMeta(
        $user,
        string $derivedOvertimeType,
        mixed $clientOvertimeType,
        string $endpoint,
    ): array {
        $guidanceEnabled = $this->guidanceGate->overtimeEnabledForUser($user);
        if (! $guidanceEnabled) {
            return [
                'guidance_enabled' => false,
            ];
        }

        $effectiveState = $this->holidayResolver->resolveEmployeeState($user);
        if (! $effectiveState) {
            $context = [
                'module' => 'overtime',
                'endpoint' => $endpoint,
                'user_id' => $user?->id,
            ];
            $this->guidanceTelemetry->recordMissingStateFallback($context);
            Log::warning('Overtime classification used national holidays only because user state is missing.', $context);
        }

        $meta = [
            'guidance_enabled' => true,
            'derived_overtime_type' => $derivedOvertimeType,
            'effective_state' => $effectiveState,
        ];

        if (is_string($clientOvertimeType) && trim($clientOvertimeType) !== '' && trim($clientOvertimeType) !== $derivedOvertimeType) {
            $this->guidanceTelemetry->recordMismatch([
                'module' => 'overtime',
                'endpoint' => $endpoint,
                'user_id' => $user?->id,
                'submitted_overtime_type' => trim($clientOvertimeType),
                'recommended_overtime_type' => $derivedOvertimeType,
                'effective_state' => $effectiveState,
            ]);
            $meta['overtime_type_adjusted_message'] =
                "Recommended overtime type based on claim date/public holiday rules is {$derivedOvertimeType}.";
        }

        return $meta;
    }
}
