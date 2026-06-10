<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\User;
use App\Services\AssignmentAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveManagementController extends Controller
{
    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
    ) {}

    // ── All records (staff view) ──────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = Leave::with(['user', 'attachment'])->orderByDesc('applied_at')->orderByDesc('id');

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

        $rows = $query->get()->map(fn ($leave) => $this->formatLeaveWithOwner($leave));

        return response()->json(['data' => $rows]);
    }

    // ── Single record ─────────────────────────────────────────────────────────

    public function show(Request $request, int $userId, int $leaveId): JsonResponse
    {
        $leave = Leave::where('user_id', $userId)
            ->with(['user', 'attachment'])
            ->findOrFail($leaveId);

        return response()->json(['data' => $this->formatLeaveWithOwner($leave)]);
    }

    // ── Format ────────────────────────────────────────────────────────────────

    private function formatLeaveWithOwner(Leave $leave): array
    {
        $base = LeaveController::formatLeave($leave);
        $user = $leave->relationLoaded('user') ? $leave->user : null;

        $base['employee']      = $user?->name ?? '';
        $base['employee_email']= $user?->email ?? '';
        $base['team']          = $user?->team ?? '';
        $base['owner_user_id'] = $leave->user_id;
        // record_key mirrors frontend convention: "userId::leaveId"
        $base['record_key']    = $leave->user_id . '::' . $leave->id;

        return $base;
    }
}
