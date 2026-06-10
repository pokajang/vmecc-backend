<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\LeaveAttachment;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\HolidayGuidanceFeatureGate;
use App\Services\HolidayGuidanceTelemetry;
use App\Services\HolidayResolver;
use App\Services\LeaveNotificationService;
use App\Services\LeaveWorkflowService;
use App\Services\WorkingDayCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LeaveController extends Controller
{
    public function __construct(
        private readonly LeaveWorkflowService        $workflowService,
        private readonly LeaveNotificationService    $notificationService,
        private readonly AssignmentAuthorizationService $authorizationService,
        private readonly WorkingDayCalculator        $workingDayCalculator,
        private readonly HolidayResolver             $holidayResolver,
        private readonly HolidayGuidanceFeatureGate  $guidanceGate,
        private readonly HolidayGuidanceTelemetry    $guidanceTelemetry,
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
        $user  = $request->user();
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

    // ── Submit a leave ────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'leave_type'      => ['required', 'string'],
            'start_date'      => ['required', 'date'],
            'end_date'        => ['required', 'date', 'gte:start_date'],
            'days'            => ['required', 'numeric', 'min:0.5'],
            'work_shift'      => ['nullable', 'string'],
            'start_time_slot' => ['nullable', 'string'],
            'end_time_slot'   => ['nullable', 'string'],
            'reason'          => ['required', 'string', 'max:2000'],
            'cover_by'        => ['nullable', 'string', 'max:255'],
            'attachment_id'   => ['nullable', 'integer', 'exists:leave_attachments,id'],
        ]);

        $actorRoles = $this->authorizationService->getActiveRoleNames($user)->all();
        $year       = (int) date('Y', strtotime($data['start_date']));
        $displayId  = $this->workflowService->generateDisplayId($user->id, $data['leave_type'], $year);
        $workflow   = $this->workflowService->buildWorkflowForSubmission($actorRoles);
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
                'endpoint' => 'store',
                'user_id' => $user?->id,
                'error' => $exception->getMessage(),
            ]);
            $computedDays = (float) ($data['days'] ?? 0);
        }

        $historyEntry = [
            'id'       => (string) \Illuminate\Support\Str::uuid(),
            'at'       => now()->toIso8601String(),
            'action'   => 'Submitted',
            'by'       => $user->name,
            'byUserId' => (string) $user->id,
            'remarks'  => '',
        ];

        $leave = DB::transaction(function () use ($user, $data, $displayId, $workflow, $historyEntry, $computedDays) {
            $leave = Leave::create([
                'user_id'          => $user->id,
                'display_id'       => $displayId,
                'leave_type'       => $data['leave_type'],
                'status'           => 'Pending',
                'start_date'       => $data['start_date'],
                'end_date'         => $data['end_date'],
                'days'             => $data['days'],
                'work_shift'       => $data['work_shift'],
                'start_time_slot'  => $data['start_time_slot'] ?? null,
                'end_time_slot'    => $data['end_time_slot'] ?? null,
                'reason'           => $data['reason'],
                'cover_by'         => $data['cover_by'] ?? null,
                'applied_at'       => now(),
                'workflow_stage'   => $workflow['workflowStage'],
                'workflow_snapshot'=> $workflow['workflowSnapshot'],
                'next_action_role' => $workflow['nextActionRole'],
                'applicant_roles'  => $workflow['applicantRoles'],
                'approval_history' => [$historyEntry],
                'submitted_by'     => $user->name,
            ]);

            // Link uploaded attachment if provided
            if (!empty($data['attachment_id'])) {
                LeaveAttachment::where('id', $data['attachment_id'])
                    ->where('user_id', $user->id)
                    ->whereNull('leave_id')
                    ->update(['leave_id' => $leave->id]);
            }

            return $leave;
        });

        // Update pending balance
        $this->workflowService->onLeaveSubmitted($leave);

        // Emit notification to reviewer role
        $snapshot     = $workflow['workflowSnapshot'];
        $reviewerRole = $snapshot['reviewRole'] ?? null;
        $this->notificationService->emit(
            'submitted',
            $leave,
            ['userId' => $user->id, 'name' => $user->name, 'email' => $user->email],
            $reviewerRole ? [$reviewerRole] : [],
            [],
            true,
        );

        AuditLogger::log($request, 'leave_submitted', $user, [
            'leave_id'   => $leave->id,
            'display_id' => $leave->display_id,
            'leave_type' => $leave->leave_type,
        ]);

        $leave->load('attachment');
        $meta = $this->buildComputationMeta($user, $computedDays, $data['days'] ?? null, 'store');

        return response()->json(['data' => $this->formatLeave($leave), 'meta' => $meta], 201);
    }

    // ── Update (edit) a pending leave ────────────────────────────────────────

    public function update(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $leave = Leave::where('user_id', $user->id)->with('attachment')->findOrFail($id);

        if (!in_array($leave->status, ['Pending', 'Draft'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only pending or draft leaves can be edited.'],
            ]);
        }

        $data = $request->validate([
            'leave_type'      => ['required', 'string'],
            'start_date'      => ['required', 'date'],
            'end_date'        => ['required', 'date', 'gte:start_date'],
            'days'            => ['required', 'numeric', 'min:0.5'],
            'work_shift'      => ['nullable', 'string'],
            'start_time_slot' => ['nullable', 'string'],
            'end_time_slot'   => ['nullable', 'string'],
            'reason'          => ['required', 'string', 'max:2000'],
            'cover_by'        => ['nullable', 'string', 'max:255'],
            'attachment_id'   => ['nullable', 'integer', 'exists:leave_attachments,id'],
        ]);

        $actorRoles = $this->authorizationService->getActiveRoleNames($user)->all();
        $workflow   = $this->workflowService->buildWorkflowForSubmission($actorRoles);
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
                'endpoint' => 'update',
                'user_id' => $user?->id,
                'error' => $exception->getMessage(),
            ]);
            $computedDays = (float) ($data['days'] ?? 0);
        }

        $editHistoryEntry = [
            'id'       => (string) \Illuminate\Support\Str::uuid(),
            'at'       => now()->toIso8601String(),
            'action'   => 'Edited',
            'by'       => $user->name,
            'byUserId' => (string) $user->id,
            'remarks'  => 'Leave request updated.',
        ];

        $prevDays   = (float) $leave->days;
        $prevStatus = $leave->status;

        DB::transaction(function () use ($user, $data, $leave, $workflow, $editHistoryEntry) {
            $baseHistory = is_array($leave->approval_history) ? $leave->approval_history : [];

            $leave->update([
                'leave_type'       => $data['leave_type'],
                'start_date'       => $data['start_date'],
                'end_date'         => $data['end_date'],
                'days'             => $data['days'],
                'work_shift'       => $data['work_shift'] ?? $leave->work_shift,
                'start_time_slot'  => $data['start_time_slot'] ?? null,
                'end_time_slot'    => $data['end_time_slot'] ?? null,
                'reason'           => $data['reason'],
                'cover_by'         => $data['cover_by'] ?? null,
                'workflow_stage'   => $workflow['workflowStage'],
                'workflow_snapshot'=> $workflow['workflowSnapshot'],
                'next_action_role' => $workflow['nextActionRole'],
                'applicant_roles'  => $workflow['applicantRoles'],
                'approval_history' => array_slice(array_merge([$editHistoryEntry], $baseHistory), 0, 20),
            ]);

            // Update attachment link
            if (!empty($data['attachment_id'])) {
                // Detach old attachment if different
                if ($leave->attachment && $leave->attachment->id !== (int) $data['attachment_id']) {
                    $leave->attachment->update(['leave_id' => null]);
                }
                LeaveAttachment::where('id', $data['attachment_id'])
                    ->where('user_id', $user->id)
                    ->update(['leave_id' => $leave->id]);
            } elseif (array_key_exists('attachment_id', $data) && $data['attachment_id'] === null) {
                // Detach any existing attachment
                LeaveAttachment::where('leave_id', $leave->id)
                    ->where('user_id', $user->id)
                    ->update(['leave_id' => null]);
            }
        });

        // Adjust pending balance: remove old days allocation, add updated days
        if ($prevStatus === 'Pending') {
            // Use a temporary object to represent the old days for the deduction
            $oldLeave = clone $leave;
            $oldLeave->days = $prevDays;
            $this->workflowService->onLeaveDeclined($oldLeave);
            $leave->refresh();
            $this->workflowService->onLeaveSubmitted($leave);
        }

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
    }

    // ── Delete (draft only) ───────────────────────────────────────────────────

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $leave = Leave::where('user_id', $user->id)->findOrFail($id);

        if ($leave->status !== 'Draft') {
            throw ValidationException::withMessages([
                'status' => ['Only draft leaves can be deleted.'],
            ]);
        }

        $leave->delete();

        AuditLogger::log($request, 'leave_deleted', $user, [
            'leave_id'   => $leave->id,
            'display_id' => $leave->display_id,
        ]);

        return response()->json(null, 204);
    }

    // ── Cancel own leave ──────────────────────────────────────────────────────

    public function cancel(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $leave = Leave::where('user_id', $user->id)->findOrFail($id);

        if (!in_array($leave->status, ['Pending'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only pending leaves can be cancelled by the applicant.'],
            ]);
        }

        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        // Capture the current reviewer before the workflow advances (it will become null after cancel)
        $nextRole = $leave->next_action_role;

        $updates = $this->workflowService->advanceWorkflow(
            $leave,
            'cancel',
            $user->id,
            $user->name,
            $data['remarks'] ?? null,
        );

        $leave->update($updates);

        $this->workflowService->onLeaveDeclined($leave);

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
    }

    // ── Format ────────────────────────────────────────────────────────────────

    public static function formatLeave(Leave $leave): array
    {
        $attachment = $leave->relationLoaded('attachment') ? $leave->attachment : null;

        return [
            'id'               => $leave->id,
            'display_id'       => $leave->display_id,
            'user_id'          => $leave->user_id,
            'leave_type'       => $leave->leave_type,
            'status'           => $leave->status,
            'start_date'       => optional($leave->start_date)->toDateString(),
            'end_date'         => optional($leave->end_date)->toDateString(),
            'days'             => (float) $leave->days,
            'work_shift'       => $leave->work_shift,
            'start_time_slot'  => $leave->start_time_slot,
            'end_time_slot'    => $leave->end_time_slot,
            'reason'           => $leave->reason,
            'cover_by'         => $leave->cover_by,
            'applied_at'       => optional($leave->applied_at)->toIso8601String(),
            'workflow_stage'   => $leave->workflow_stage,
            'workflow_snapshot'=> $leave->workflow_snapshot,
            'next_action_role' => $leave->next_action_role,
            'applicant_roles'  => $leave->applicant_roles ?? [],
            'approval_history' => $leave->approval_history ?? [],
            'submitted_by'     => $leave->submitted_by,
            'attachment'       => $attachment ? [
                'id'            => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type'     => $attachment->mime_type,
                'size'          => $attachment->size,
                'original_size' => $attachment->original_size,
                'was_compressed'=> $attachment->was_compressed,
            ] : null,
            'created_at'       => optional($leave->created_at)->toIso8601String(),
            'updated_at'       => optional($leave->updated_at)->toIso8601String(),
        ];
    }

    private function buildComputationMeta($user, float $computedDays, mixed $clientDays, string $endpoint): array
    {
        $guidanceEnabled = $this->guidanceGate->leaveEnabledForUser($user);
        if (!$guidanceEnabled) {
            return [
                'guidance_enabled' => false,
            ];
        }

        $effectiveState = $this->holidayResolver->resolveEmployeeState($user);
        if (!$effectiveState) {
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
