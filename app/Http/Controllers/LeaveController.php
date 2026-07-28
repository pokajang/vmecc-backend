<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\LeaveAttachment;
use App\Models\User;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\HolidayGuidanceFeatureGate;
use App\Services\HolidayGuidanceTelemetry;
use App\Services\HolidayResolver;
use App\Services\LeaveClaimGuardService;
use App\Services\LeaveNotificationService;
use App\Services\LeaveRosterImpactService;
use App\Services\LeaveWorkflowService;
use App\Services\WorkflowRecipientResolver;
use App\Services\WorkflowSubmissionContextResolver;
use App\Services\WorkingDayCalculator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeaveController extends Controller
{
    public function __construct(
        private readonly LeaveWorkflowService $workflowService,
        private readonly LeaveClaimGuardService $claimGuardService,
        private readonly LeaveRosterImpactService $rosterImpactService,
        private readonly LeaveNotificationService $notificationService,
        private readonly WorkflowRecipientResolver $recipientResolver,
        private readonly WorkflowSubmissionContextResolver $submissionContextResolver,
        private readonly AssignmentAuthorizationService $authorizationService,
        private readonly WorkingDayCalculator $workingDayCalculator,
        private readonly HolidayResolver $holidayResolver,
        private readonly HolidayGuidanceFeatureGate $guidanceGate,
        private readonly HolidayGuidanceTelemetry $guidanceTelemetry,
    ) {}

    // ── List own leaves ───────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Leave::where('user_id', $user->id)->with('attachment');

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('leave_type') && $request->input('leave_type') !== 'All') {
            $query->where('leave_type', $request->input('leave_type'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('display_id', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhere('leave_type', 'like', "%{$search}%");
            });
        }
        if ($request->filled('year')) {
            $year = (int) $request->input('year');
            $query->whereYear('start_date', $year);
        }

        $sort = $request->input('sort', 'applied_at:desc');
        [$col, $dir] = array_pad(explode(':', $sort), 2, 'desc');
        $allowedSorts = ['applied_at', 'start_date', 'end_date', 'leave_type', 'status', 'days'];
        $col = in_array($col, $allowedSorts, true) ? $col : 'applied_at';
        $dir = $dir === 'asc' ? 'asc' : 'desc';
        $query->orderBy($col, $dir)->orderByDesc('id');

        $rows = $query->get()->map(fn ($leave) => $this->formatLeave($leave));

        return response()->json(['data' => $rows]);
    }

    // ── Show single own leave ─────────────────────────────────────────────────

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $leave = Leave::where('user_id', $user->id)->with('attachment')->findOrFail($id);

        return response()->json(['data' => $this->formatLeave($leave)]);
    }

    public function computeDays(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'gte:start_date'],
            'start_time_slot' => ['nullable', 'string'],
            'end_time_slot' => ['nullable', 'string'],
            'submitted_days' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $computedDays = $this->workingDayCalculator->computeLeaveDays(
                $user,
                $data['start_date'],
                $data['end_date'],
                $data['start_time_slot'] ?? null,
                $data['end_time_slot'] ?? null,
            );
        } catch (\Throwable $exception) {
            $this->guidanceTelemetry->recordLookupFailure([
                'module' => 'leave',
                'endpoint' => 'compute-days',
                'user_id' => $user?->id,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }

        $meta = $this->buildComputationMeta(
            $user,
            (float) $computedDays,
            $data['submitted_days'] ?? null,
            'compute-days',
        );

        return response()->json([
            'data' => [
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'computed_days' => (float) $computedDays,
            ],
            'meta' => $meta,
        ]);
    }

    public function rosterImpact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'gte:start_date'],
            'work_shift' => ['nullable', 'string', 'max:50'],
            'start_time_slot' => ['nullable', 'string', 'max:50'],
            'end_time_slot' => ['nullable', 'string', 'max:50'],
        ]);
        if (Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) > 366) {
            throw ValidationException::withMessages([
                'end_date' => ['Roster duty guidance is limited to 366 days.'],
            ]);
        }

        return response()->json([
            'data' => $this->rosterImpactService->forLeave($request->user(), $data, true),
        ]);
    }

    // ── Submit a leave ────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $payload = $this->validatePayload($request);
        $submittedDays = $payload['days'] ?? null;

        [$leave, $workflow, $recipientIds] = DB::transaction(function () use ($user, $payload) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $data = $this->claimGuardService->validate($payload, $lockedUser);
            $effectiveAt = $this->workflowEffectiveAt($data);
            $actorRoles = $this->authorizationService->getActiveRoleNames($lockedUser)->all();
            $context = $this->submissionContextResolver->resolve($lockedUser, $effectiveAt);
            $workflow = $this->workflowService->buildWorkflowForSubmission(array_values(array_unique(array_filter([
                $context['applicantRole'] ?? null,
                ...$actorRoles,
            ]))));
            $workflow['workflowSnapshot']['workflowContext'] = $context;
            $year = (int) date('Y', strtotime($data['start_date']));
            $historyEntry = [
                'id' => (string) Str::uuid(),
                'at' => now()->toIso8601String(),
                'action' => 'Submitted',
                'by' => $lockedUser->name,
                'byUserId' => (string) $lockedUser->id,
                'remarks' => '',
            ];

            $rosterImpactSnapshot = $this->rosterImpactService->snapshotForLeave($lockedUser, $data);
            $leave = Leave::query()->create([
                'user_id' => $lockedUser->id,
                'display_id' => $this->workflowService->generateDisplayId($lockedUser->id, $data['leave_type'], $year),
                'leave_type' => $data['leave_type'],
                'status' => 'Pending',
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'days' => $data['days'],
                'work_shift' => $data['work_shift'],
                'start_time_slot' => $data['start_time_slot'],
                'end_time_slot' => $data['end_time_slot'],
                'reason' => $data['reason'],
                'cover_by' => $data['cover_by'] ?? null,
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
                'approval_history' => [$historyEntry],
                'roster_impact_snapshot' => $rosterImpactSnapshot,
                'submitted_by' => $lockedUser->name,
                'version' => 1,
            ]);
            if (! empty($data['attachment_id'])) {
                LeaveAttachment::query()->whereKey($data['attachment_id'])->update(['leave_id' => $leave->id]);
            }
            $this->workflowService->onLeaveSubmitted($leave);
            $recipientIds = $this->resolveStageRecipientIds(
                $workflow['nextActionRole'],
                $context['teamId'] ?? null,
                now(),
                (int) $lockedUser->id,
            );

            return [$leave->fresh(['attachment']), $workflow, $recipientIds];
        });

        $this->emitNotificationSafely(
            'submitted',
            $leave,
            ['userId' => $user->id, 'name' => $user->name, 'email' => $user->email],
            [],
            $recipientIds,
            true,
            null,
            $this->workflowContextMetadata($leave),
            true,
        );

        AuditLogger::log($request, 'leave_submitted', $user, [
            'leave_id' => $leave->id,
            'display_id' => $leave->display_id,
            'leave_type' => $leave->leave_type,
        ]);

        $meta = $this->buildComputationMeta($user, (float) $leave->days, $submittedDays, 'store');

        return response()->json(['data' => $this->formatLeave($leave), 'meta' => $meta], 201);
    }

    // ── Update (edit) a pending leave ────────────────────────────────────────

    public function update(Request $request, int $id): JsonResponse
    {
        return $this->updateVersionedLeave($request, $id);

        /* Legacy update path retained only for source-history compatibility. */
        /*
        // Notify the reviewer that the leave has changed and needs re-review
        $freshLeave = $leave->fresh(['attachment']);
        $nextRole   = $freshLeave->next_action_role;
        $this->notificationService->emit(
            'edited',
            $freshLeave,
            ['userId' => $user->id, 'name' => $user->name, 'email' => $user->email],
            $nextRole ? [$nextRole] : [],
            [],
            (bool) $nextRole, // action_required — reviewer must re-evaluate
            null,
            [],
            true, // excludeOwner — employee edited it themselves
        );

        AuditLogger::log($request, 'leave_edited', $user, [
            'leave_id'   => $leave->id,
            'display_id' => $leave->display_id,
        ]);
        $meta = $this->buildComputationMeta($user, $computedDays, $data['days'] ?? null, 'update');
        return response()->json(['data' => $this->formatLeave($freshLeave), 'meta' => $meta]);
        */
    }

    private function updateVersionedLeave(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $payload = $this->validatePayload($request, true);
        $submittedDays = $payload['days'] ?? null;

        [$leave, $workflow, $eventType, $recipientIds] = DB::transaction(function () use ($user, $id, $payload) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $leave = Leave::query()->where('user_id', $lockedUser->id)->with('attachment')->lockForUpdate()->findOrFail($id);
            if (! $this->canApplicantEdit($leave)) {
                throw ValidationException::withMessages([
                    'status' => ['Editing is locked after the first manager workflow action. Request correction to amend this leave.'],
                ]);
            }
            $this->assertExpectedVersion($leave, $payload['expected_version'] ?? null);
            $data = $this->claimGuardService->validate($payload, $lockedUser, $leave);
            $effectiveAt = $this->workflowEffectiveAt($data);
            $actorRoles = $this->authorizationService->getActiveRoleNames($lockedUser)->all();
            $context = $this->submissionContextResolver->resolve($lockedUser, $effectiveAt);
            $workflow = $this->workflowService->buildWorkflowForSubmission(array_values(array_unique(array_filter([
                $context['applicantRole'] ?? null,
                ...$actorRoles,
            ]))));
            $workflow['workflowSnapshot']['workflowContext'] = $context;
            $recipientIds = $this->resolveStageRecipientIds(
                $workflow['nextActionRole'],
                $context['teamId'] ?? null,
                now(),
                (int) $lockedUser->id,
            );
            $isResubmission = $leave->status === 'Needs Correction';
            $history = is_array($leave->approval_history) ? $leave->approval_history : [];
            $history[] = [
                'id' => (string) Str::uuid(),
                'at' => now()->toIso8601String(),
                'action' => $isResubmission ? 'Resubmitted' : 'Edited',
                'by' => $lockedUser->name,
                'byUserId' => (string) $lockedUser->id,
                'remarks' => $isResubmission ? 'Leave request corrected and resubmitted.' : 'Leave request updated before review.',
            ];
            $history = collect($history)->take(-30)->values()->all();
            $oldLeave = clone $leave;

            $leave->update([
                'leave_type' => $data['leave_type'],
                'status' => 'Pending',
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'days' => $data['days'],
                'work_shift' => $data['work_shift'],
                'start_time_slot' => $data['start_time_slot'],
                'end_time_slot' => $data['end_time_slot'],
                'reason' => $data['reason'],
                'cover_by' => $data['cover_by'] ?? null,
                'workflow_stage' => $workflow['workflowStage'],
                'workflow_snapshot' => $workflow['workflowSnapshot'],
                'workflow_team_id' => $context['teamId'],
                'workflow_team_name' => $context['teamName'],
                'workflow_applicant_role' => $context['applicantRole'],
                'workflow_routing_source' => $context['routingSource'],
                'duty_coverage_assignment_id' => $context['dutyCoverageAssignmentId'],
                'next_action_role' => $workflow['nextActionRole'],
                'applicant_roles' => $workflow['applicantRoles'],
                'approval_history' => array_slice($history, -20),
                'roster_impact_snapshot' => $this->rosterImpactService->snapshotForLeave($lockedUser, $data),
                'version' => ((int) $leave->version) + 1,
            ]);

            if ($leave->attachment && $leave->attachment->id !== (int) ($data['attachment_id'] ?? 0)) {
                $leave->attachment->update(['leave_id' => null]);
            }
            if (! empty($data['attachment_id'])) {
                LeaveAttachment::query()->whereKey($data['attachment_id'])->update(['leave_id' => $leave->id]);
            }

            $this->workflowService->onLeaveDeclined($oldLeave);
            $this->workflowService->onLeaveSubmitted($leave->fresh());

            return [$leave->fresh(['attachment']), $workflow, $isResubmission ? 'resubmitted' : 'edited', $recipientIds];
        });

        $this->emitNotificationSafely(
            $eventType,
            $leave,
            ['userId' => $user->id, 'name' => $user->name, 'email' => $user->email],
            [],
            $recipientIds,
            (bool) $workflow['nextActionRole'],
            null,
            $this->workflowContextMetadata($leave),
            true,
        );

        AuditLogger::log($request, 'leave_edited', $user, [
            'leave_id' => $leave->id,
            'display_id' => $leave->display_id,
        ]);

        return response()->json([
            'data' => $this->formatLeave($leave),
            'meta' => $this->buildComputationMeta($user, (float) $leave->days, $submittedDays, 'update'),
        ]);
    }

    // ── Delete (draft only) ───────────────────────────────────────────────────

    public function destroy(Request $request, int $id): JsonResponse
    {
        $payload = $request->validate([
            'expected_version' => ['nullable', 'integer', 'min:1'],
        ]);
        $user = $request->user();
        DB::transaction(function () use ($user, $id, $payload) {
            $leave = Leave::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($id);
            if ($leave->status !== 'Draft') {
                throw ValidationException::withMessages([
                    'status' => ['Only draft leaves can be deleted.'],
                ]);
            }
            $this->assertExpectedVersion($leave, $payload['expected_version'] ?? null);
            $leave->delete();
        });

        AuditLogger::log($request, 'leave_deleted', $user, ['leave_id' => $id]);

        return response()->json(null, 204);
    }

    // ── Cancel own leave ──────────────────────────────────────────────────────

    public function cancel(Request $request, int $id): JsonResponse
    {
        return $this->cancelVersionedLeave($request, $id);

        /*
        // Capture the current reviewer before the workflow advances (it will become null after cancel)

        // Notify whoever was about to act on this leave (not the employee — they cancelled it)
        $this->notificationService->emit(
            'cancelled',
            $leave,
            ['userId' => $user->id, 'name' => $user->name, 'email' => $user->email],
            $nextRole ? [$nextRole] : [],
            [],
            false,
            $data['remarks'] ?? null,
            [],
            true, // excludeOwner — employee cancelled it themselves
        );

        AuditLogger::log($request, 'leave_cancelled', $user, [
            'leave_id'   => $leave->id,
            'display_id' => $leave->display_id,
        ]);

        $leave->load('attachment');

        return response()->json(['data' => $this->formatLeave($leave)]);
        */
    }

    private function cancelVersionedLeave(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $payload = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
        ]);

        [$leave, $nextRole] = DB::transaction(function () use ($user, $id, $payload) {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $leave = Leave::query()->where('user_id', $user->id)->with('attachment')->lockForUpdate()->findOrFail($id);
            if ($leave->status !== 'Pending') {
                throw ValidationException::withMessages([
                    'status' => ['Only pending leaves can be cancelled by the applicant.'],
                ]);
            }
            $this->assertExpectedVersion($leave, $payload['expected_version'] ?? null);
            $nextRole = $leave->next_action_role;
            $updates = $this->workflowService->advanceWorkflow(
                $leave,
                'cancel',
                (int) $user->id,
                (string) $user->name,
                $payload['remarks'] ?? null,
            );
            $updates['version'] = ((int) $leave->version) + 1;
            $leave->update($updates);
            $this->workflowService->onLeaveDeclined($leave->fresh());

            return [$leave->fresh(['attachment']), $nextRole];
        });

        $this->emitNotificationSafely(
            'cancelled',
            $leave,
            ['userId' => $user->id, 'name' => $user->name, 'email' => $user->email],
            $nextRole ? [$nextRole] : [],
            [],
            false,
            $payload['remarks'] ?? null,
            [],
            true,
        );
        AuditLogger::log($request, 'leave_cancelled', $user, [
            'leave_id' => $leave->id,
            'display_id' => $leave->display_id,
        ]);

        return response()->json(['data' => $this->formatLeave($leave)]);
    }

    // ── Format ────────────────────────────────────────────────────────────────

    public static function formatLeave(Leave $leave): array
    {
        $attachment = $leave->relationLoaded('attachment') ? $leave->attachment : null;

        return [
            'id' => $leave->id,
            'display_id' => $leave->display_id,
            'user_id' => $leave->user_id,
            'leave_type' => $leave->leave_type,
            'status' => $leave->status,
            'start_date' => optional($leave->start_date)->toDateString(),
            'end_date' => optional($leave->end_date)->toDateString(),
            'days' => (float) $leave->days,
            'work_shift' => $leave->work_shift,
            'start_time_slot' => $leave->start_time_slot,
            'end_time_slot' => $leave->end_time_slot,
            'reason' => $leave->reason,
            'cover_by' => $leave->cover_by,
            'applied_at' => optional($leave->applied_at)->toIso8601String(),
            'workflow_stage' => $leave->workflow_stage,
            'workflow_snapshot' => $leave->workflow_snapshot,
            'workflow_team_id' => $leave->workflow_team_id,
            'workflow_team_name' => $leave->workflow_team_name,
            'workflow_applicant_role' => $leave->workflow_applicant_role,
            'workflow_routing_source' => $leave->workflow_routing_source,
            'duty_coverage_assignment_id' => $leave->duty_coverage_assignment_id,
            'next_action_role' => $leave->next_action_role,
            'applicant_roles' => $leave->applicant_roles ?? [],
            'approval_history' => $leave->approval_history ?? [],
            'roster_impact_snapshot' => $leave->roster_impact_snapshot,
            'submitted_by' => $leave->submitted_by,
            'version' => (int) $leave->version,
            'attachment' => $attachment ? [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
                'original_size' => $attachment->original_size,
                'was_compressed' => $attachment->was_compressed,
            ] : null,
            'created_at' => optional($leave->created_at)->toIso8601String(),
            'updated_at' => optional($leave->updated_at)->toIso8601String(),
        ];
    }

    private function validatePayload(Request $request, bool $includeVersion = false): array
    {
        $rules = [
            'leave_type' => ['required', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'gte:start_date'],
            'days' => ['nullable', 'numeric', 'min:0'],
            'work_shift' => ['nullable', 'string', 'max:50'],
            'start_time_slot' => ['nullable', 'string', 'max:50'],
            'end_time_slot' => ['nullable', 'string', 'max:50'],
            'reason' => ['required', 'string', 'max:2000'],
            'cover_by' => ['nullable', 'string', 'max:255'],
            'attachment_id' => ['nullable', 'integer'],
        ];
        if ($includeVersion) {
            $rules['expected_version'] = ['nullable', 'integer', 'min:1'];
        }

        return $request->validate($rules);
    }

    private function canApplicantEdit(Leave $leave): bool
    {
        if (in_array($leave->status, ['Draft', 'Needs Correction'], true)) {
            return true;
        }
        if ($leave->status !== 'Pending' || $leave->workflow_stage !== 'review') {
            return false;
        }

        $history = is_array($leave->approval_history) ? $leave->approval_history : [];

        return ! collect($history)->contains(function ($entry) {
            return in_array((string) ($entry['action'] ?? ''), ['Reviewed', 'Recommended', 'Approved'], true);
        });
    }

    private function assertExpectedVersion(Leave $leave, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && $expectedVersion !== (int) $leave->version) {
            $this->throwVersionConflict($leave);
        }
    }

    private function throwVersionConflict(Leave $leave): never
    {
        throw new HttpResponseException(response()->json([
            'code' => 'LEAVE_VERSION_CONFLICT',
            'message' => 'This leave request changed. Reload the latest record before trying again.',
            'currentVersion' => (int) $leave->version,
            'currentRecord' => self::formatLeave($leave),
        ], 409));
    }

    private function emitNotificationSafely(
        string $eventType,
        Leave $leave,
        array $actor,
        array $targetRoles = [],
        array $targetUserIds = [],
        bool $actionRequired = false,
        ?string $remarks = null,
        array $metadata = [],
        bool $excludeOwner = false,
    ): void {
        try {
            $this->notificationService->emit(
                $eventType,
                $leave,
                $actor,
                $targetRoles,
                $targetUserIds,
                $actionRequired,
                $remarks,
                $metadata,
                $excludeOwner,
            );
        } catch (\Throwable $exception) {
            Log::warning('Leave notification could not be sent after a committed mutation.', [
                'leave_id' => $leave->id,
                'event_type' => $eventType,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveStageRecipientIds(
        mixed $role,
        ?int $teamId,
        Carbon $effectiveAt,
        int $excludeUserId,
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

        if ($ids === []) {
            throw ValidationException::withMessages([
                'workflow' => ["No active recipient is available for the '{$role}' workflow stage."],
            ]);
        }

        return $ids;
    }

    private function workflowEffectiveAt(array $data): Carbon
    {
        $shift = strtolower(trim((string) ($data['work_shift'] ?? 'normal')));
        $hour = str_contains($shift, 'night') ? 20 : 8;

        return Carbon::parse((string) $data['start_date'])->setTime($hour, 0);
    }

    private function workflowContextMetadata(Leave $leave): array
    {
        return [
            'workflowTeamId' => $leave->workflow_team_id,
            'workflowTeamName' => $leave->workflow_team_name,
            'workflowRoutingSource' => $leave->workflow_routing_source,
        ];
    }

    private function buildComputationMeta($user, float $computedDays, mixed $clientDays, string $endpoint): array
    {
        $guidanceEnabled = $this->guidanceGate->leaveEnabledForUser($user);
        if (! $guidanceEnabled) {
            return [
                'guidance_enabled' => false,
            ];
        }

        $effectiveState = $this->holidayResolver->resolveEmployeeState($user);
        if (! $effectiveState) {
            $context = [
                'module' => 'leave',
                'endpoint' => $endpoint,
                'user_id' => $user?->id,
            ];
            $this->guidanceTelemetry->recordMissingStateFallback($context);
            Log::warning('Leave calculation used national holidays only because user state is missing.', $context);
        }

        $meta = [
            'guidance_enabled' => true,
            'computed_days' => $computedDays,
            'effective_state' => $effectiveState,
        ];

        if ($clientDays !== null) {
            $client = (float) $clientDays;
            if (abs($client - $computedDays) > 0.0001) {
                $this->guidanceTelemetry->recordMismatch([
                    'module' => 'leave',
                    'endpoint' => $endpoint,
                    'user_id' => $user?->id,
                    'submitted_days' => $client,
                    'recommended_days' => $computedDays,
                    'effective_state' => $effectiveState,
                ]);
                $meta['day_adjusted_message'] =
                    "Recommended leave days based on weekends/public holidays is {$computedDays}.";
            }
        }

        return $meta;
    }
}
