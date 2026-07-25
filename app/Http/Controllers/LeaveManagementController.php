<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\User;
use App\Services\AssignmentAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LeaveManagementController extends Controller
{
    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
    ) {}

    // ── All records (staff view) ──────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = Leave::with(['user', 'attachment'])->orderByDesc('applied_at')->orderByDesc('id');
        $action = strtolower(trim((string) $request->input('action', '')));
        if ($action !== '' && ! in_array($action, ['review', 'recommend', 'approve'], true)) {
            throw ValidationException::withMessages([
                'action' => ['Action must be review, recommend, or approve.'],
            ]);
        }
        if ($action !== '') {
            $query->where('status', 'Pending')->where('workflow_stage', $action);
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('leave_type') && $request->input('leave_type') !== 'All') {
            $query->where('leave_type', $request->input('leave_type'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('display_id', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$search}%"));
            });
        }
        if ($request->filled('year')) {
            $query->whereYear('start_date', (int) $request->input('year'));
        }
        if ($request->filled('workflow_stage')) {
            $query->where('workflow_stage', $request->input('workflow_stage'));
        }

        $sort = $request->input('sort', 'applied_at:desc');
        [$col, $dir] = array_pad(explode(':', $sort), 2, 'desc');
        $allowedSorts = ['applied_at', 'start_date', 'end_date', 'leave_type', 'status', 'days'];
        $col = in_array($col, $allowedSorts, true) ? $col : 'applied_at';
        $dir = $dir === 'asc' ? 'asc' : 'desc';
        $query->orderBy($col, $dir);

        $rows = $query->get()
            ->map(fn ($leave) => $this->formatLeaveWithOwner($leave, $request->user()))
            ->when($action !== '', fn ($rows) => $rows->filter(
                fn (array $row) => in_array($action, $row['permitted_actions'] ?? [], true),
            )->values());

        return response()->json(['data' => $rows]);
    }

    // ── Single record ─────────────────────────────────────────────────────────

    public function show(Request $request, int $userId, int $leaveId): JsonResponse
    {
        $leave = Leave::where('user_id', $userId)
            ->with(['user', 'attachment'])
            ->findOrFail($leaveId);

        return response()->json(['data' => $this->formatLeaveWithOwner($leave, $request->user())]);
    }

    // ── Format ────────────────────────────────────────────────────────────────

    private function formatLeaveWithOwner(Leave $leave, ?User $actor = null): array
    {
        $base = LeaveController::formatLeave($leave);
        $user = $leave->relationLoaded('user') ? $leave->user : null;

        $base['employee'] = $user?->name ?? '';
        $base['employee_email'] = $user?->email ?? '';
        $base['team'] = $user?->team ?? '';
        $base['owner_user_id'] = $leave->user_id;
        // record_key mirrors frontend convention: "userId::leaveId"
        $base['record_key'] = $leave->user_id.'::'.$leave->id;
        $base['permitted_actions'] = $this->permittedActions($leave, $actor);

        return $base;
    }

    private function permittedActions(Leave $leave, ?User $actor): array
    {
        if (! $actor) {
            return [];
        }
        $roles = $this->authorizationService->getActiveRoleNames($actor)->all();
        if (in_array('System Administrator', $roles, true)) {
            return match ($leave->status) {
                'Pending' => ['review', 'recommend', 'approve', 'reject', 'request_correction', 'cancel'],
                'Approved' => ['cancel'],
                default => [],
            };
        }
        if ($leave->status !== 'Pending') {
            return [];
        }
        $expectedRole = trim((string) $leave->next_action_role);
        if ($expectedRole === '' || ! in_array($expectedRole, $roles, true)) {
            return [];
        }

        $primaryAction = match ($leave->workflow_stage) {
            'review' => 'review',
            'recommend' => 'recommend',
            'approve' => 'approve',
            default => null,
        };
        if (! $primaryAction) {
            return [];
        }

        $snapshot = is_array($leave->workflow_snapshot) ? $leave->workflow_snapshot : [];
        if (($snapshot['enforceDistinctApprovers'] ?? false) === true) {
            $hasActed = collect($leave->approval_history ?: [])->contains(
                fn ($entry) => (string) ($entry['byUserId'] ?? '') === (string) $actor->id
                    && in_array((string) ($entry['action'] ?? ''), ['Reviewed', 'Recommended', 'Approved'], true),
            );
            if ($hasActed) {
                return [];
            }
        }

        return [$primaryAction, 'reject', 'request_correction', 'cancel'];
    }
}
