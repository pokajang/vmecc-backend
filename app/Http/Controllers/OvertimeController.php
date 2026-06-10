<?php

namespace App\Http\Controllers;

use App\Models\OvertimeRecord;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\HolidayGuidanceFeatureGate;
use App\Services\HolidayGuidanceTelemetry;
use App\Services\HolidayResolver;
use App\Services\OvertimeDateClassifier;
use App\Services\OvertimeEligibilityService;
use App\Services\OvertimeWorkflowService;
use App\Services\WorkflowNotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OvertimeController extends Controller
{
    public function __construct(
        private readonly OvertimeWorkflowService $workflowService,
        private readonly WorkflowNotificationService $notificationService,
        private readonly AssignmentAuthorizationService $authorizationService,
        private readonly OvertimeEligibilityService $overtimeEligibilityService,
        private readonly OvertimeDateClassifier $overtimeDateClassifier,
        private readonly HolidayResolver $holidayResolver,
        private readonly HolidayGuidanceFeatureGate $guidanceGate,
        private readonly HolidayGuidanceTelemetry $guidanceTelemetry,
    ) {
    }

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
        $eligibilityResponse = $this->guardIneligibleUser($user);
        if ($eligibilityResponse) {
            return $eligibilityResponse;
        }
        $data = $this->validatePayload($request);
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

        $claimDate = (string) ($data['claim_date'] ?? now()->toDateString());
        $displayId = $this->workflowService->generateDisplayId($user->id, (int) date('Y', strtotime($claimDate)));
        $roles = $this->authorizationService->getActiveRoleNames($user)->all();
        $workflow = $this->workflowService->buildWorkflowForSubmission($roles);

        $entry = [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'at' => now()->toIso8601String(),
            'action' => 'Submitted',
            'by' => (string) ($user->name ?? ''),
            'byUserId' => (string) $user->id,
            'remarks' => '',
        ];

        $row = DB::transaction(function () use ($data, $user, $displayId, $workflow, $entry, $derivedOvertimeType) {
            return OvertimeRecord::query()->create([
                'user_id' => $user->id,
                'display_id' => $displayId,
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
                'next_action_role' => $workflow['nextActionRole'],
                'applicant_roles' => $workflow['applicantRoles'],
                'approval_history' => [$entry],
                'submitted_by' => (string) ($user->name ?? ''),
                'attachment_id' => $data['attachment_id'] ?? null,
            ]);
        });

        $this->notificationService->emit(
            module: 'overtime',
            eventType: 'submitted',
            recordType: 'overtime',
            recordId: $row->id,
            recordDisplayId: $row->display_id,
            ownerUserId: (int) $row->user_id,
            actor: ['userId' => $user->id, 'name' => $user->name, 'email' => $user->email],
            targetRoles: $workflow['nextActionRole'] ? [$workflow['nextActionRole']] : [],
            actionRequired: (bool) $workflow['nextActionRole'],
            metadata: [
                'module' => 'overtime',
                'status' => $row->status,
                'workflowStage' => $row->workflow_stage,
                'nextActionRole' => $row->next_action_role,
            ],
            excludeOwner: true,
        );

        AuditLogger::log($request, 'overtime_submitted', $user, [
            'overtime_id' => $row->id,
            'display_id' => $row->display_id,
        ]);

        $row->load('attachment');
        $meta = $this->buildClassificationMeta(
            $user,
            $derivedOvertimeType,
            $data['overtime_type'] ?? null,
            'store',
        );
        return response()->json(['data' => self::formatRecord($row), 'meta' => $meta], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $eligibilityResponse = $this->guardIneligibleUser($user);
        if ($eligibilityResponse) {
            return $eligibilityResponse;
        }
        $row = OvertimeRecord::query()->where('user_id', $user->id)->with('attachment')->findOrFail($id);

        if (! $this->canApplicantEdit($row)) {
            throw ValidationException::withMessages([
                'status' => ['Editing is locked after first approval step. Only draft or pre-review pending overtime can be edited.'],
            ]);
        }

        $data = $this->validatePayload($request);
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
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'at' => now()->toIso8601String(),
            'action' => 'Edited',
            'by' => (string) ($user->name ?? ''),
            'byUserId' => (string) $user->id,
            'remarks' => 'Overtime updated and resubmitted.',
        ];

        $history = collect(is_array($row->approval_history) ? $row->approval_history : [])->push($entry)->take(-20)->values()->all();
        $roles = $this->authorizationService->getActiveRoleNames($user)->all();
        $workflow = $this->workflowService->buildWorkflowForSubmission($roles);

        $row->update([
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
            'next_action_role' => $workflow['nextActionRole'],
            'applicant_roles' => $workflow['applicantRoles'],
            'approval_history' => $history,
            'attachment_id' => $data['attachment_id'] ?? $row->attachment_id,
        ]);

        $this->notificationService->emit(
            module: 'overtime',
            eventType: 'edited',
            recordType: 'overtime',
            recordId: $row->id,
            recordDisplayId: $row->display_id,
            ownerUserId: (int) $row->user_id,
            actor: ['userId' => $user->id, 'name' => $user->name, 'email' => $user->email],
            targetRoles: $workflow['nextActionRole'] ? [$workflow['nextActionRole']] : [],
            actionRequired: (bool) $workflow['nextActionRole'],
            metadata: [
                'module' => 'overtime',
                'status' => $row->status,
                'workflowStage' => $row->workflow_stage,
                'nextActionRole' => $row->next_action_role,
            ],
            excludeOwner: true,
        );

        AuditLogger::log($request, 'overtime_edited', $user, [
            'overtime_id' => $row->id,
            'display_id' => $row->display_id,
        ]);

        $row->refresh()->load('attachment');
        $meta = $this->buildClassificationMeta(
            $user,
            $derivedOvertimeType,
            $data['overtime_type'] ?? null,
            'update',
        );
        return response()->json(['data' => self::formatRecord($row), 'meta' => $meta]);
    }

    private function guardIneligibleUser($user): ?JsonResponse
    {
        $eligibility = $this->overtimeEligibilityService->resolveForUser($user);
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
        $row = OvertimeRecord::query()->where('user_id', $user->id)->findOrFail($id);

        if (!in_array($row->status, ['Draft', 'Cancelled'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only draft or cancelled overtime records can be deleted.'],
            ]);
        }

        $row->delete();

        AuditLogger::log($request, 'overtime_deleted', $user, [
            'overtime_id' => $row->id,
            'display_id' => $row->display_id,
        ]);

        return response()->json(null, 204);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $row = OvertimeRecord::query()->where('user_id', $user->id)->with('attachment')->findOrFail($id);

        if (!in_array($row->status, ['Pending', 'Approved'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only pending or approved overtime records can be cancelled.'],
            ]);
        }

        $payload = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $nextRole = $row->next_action_role;
        $updates = $this->workflowService->advanceWorkflow(
            $row,
            'cancel',
            (int) $user->id,
            (string) ($user->name ?? ''),
            $payload['remarks'] ?? null,
        );

        $row->update($updates);

        $this->notificationService->emit(
            module: 'overtime',
            eventType: 'cancelled',
            recordType: 'overtime',
            recordId: $row->id,
            recordDisplayId: $row->display_id,
            ownerUserId: (int) $row->user_id,
            actor: ['userId' => $user->id, 'name' => $user->name, 'email' => $user->email],
            targetRoles: $nextRole ? [$nextRole] : [],
            actionRequired: false,
            remarks: $payload['remarks'] ?? null,
            metadata: ['module' => 'overtime', 'status' => 'Cancelled', 'workflowStage' => 'done'],
            excludeOwner: true,
        );

        AuditLogger::log($request, 'overtime_cancelled', $user, [
            'overtime_id' => $row->id,
            'display_id' => $row->display_id,
        ]);

        $row->refresh()->load('attachment');

        return response()->json(['data' => self::formatRecord($row)]);
    }

    private function validatePayload(Request $request): array
    {
        $payload = $request->all();
        $payload['start_time'] = $this->normalizeClockTime($payload['start_time'] ?? null);
        $payload['end_time'] = $this->normalizeClockTime($payload['end_time'] ?? null);

        return Validator::make($payload, [
            'overtime_type' => ['required', 'in:weekday,weekend,publicHoliday'],
            'claim_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'is_overnight' => ['nullable', 'boolean'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:3000'],
            'attachment_id' => ['nullable', 'integer', 'exists:workflow_attachments,id'],
        ])->validate();
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
            $parsed = \DateTime::createFromFormat('!' . $format, $raw);
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
        ];
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
            $parsed = \DateTime::createFromFormat('!' . $format, $raw);
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
    ): array
    {
        $guidanceEnabled = $this->guidanceGate->overtimeEnabledForUser($user);
        if (!$guidanceEnabled) {
            return [
                'guidance_enabled' => false,
            ];
        }

        $effectiveState = $this->holidayResolver->resolveEmployeeState($user);
        if (!$effectiveState) {
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
