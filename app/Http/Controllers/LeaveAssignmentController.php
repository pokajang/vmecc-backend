<?php

namespace App\Http\Controllers;

use App\Models\LeaveAssignment;
use App\Models\LeaveAssignmentHistory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LeaveNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeaveAssignmentController extends Controller
{
    public function __construct(
        private readonly LeaveNotificationService $notificationService,
    ) {}
    private const LEAVE_TYPES = [
        'Annual Leave',
        'Medical Leave',
        'Emergency Leave',
        'Compassionate Leave',
        'Unpaid Leave',
        'Other Leave',
    ];

    // ── Own balance (employee) ────────────────────────────────────────────────

    public function indexForUser(Request $request): JsonResponse
    {
        $user = $request->user();
        $year = (int) ($request->input('year') ?? date('Y'));

        $rows = LeaveAssignment::where('user_id', $user->id)
            ->where('year', $year)
            ->get()
            ->map(fn ($a) => $this->formatAssignment($a, $user));

        return response()->json(['data' => $rows]);
    }

    // ── All assignments (staff) ───────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = LeaveAssignment::with('user');

        if ($request->filled('year')) {
            $query->where('year', (int) $request->input('year'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('leave_type')) {
            $query->where('leave_type', $request->input('leave_type'));
        }

        $rows = $query->orderBy('year', 'desc')
            ->orderBy('user_id')
            ->get()
            ->map(fn ($a) => $this->formatAssignment($a, $a->user));

        return response()->json(['data' => $rows]);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        $data = $request->validate([
            'user_id'     => ['required', 'integer', 'exists:users,id'],
            'year'        => ['required', 'integer', 'min:2000', 'max:2100'],
            'leave_type'  => ['required', 'string', Rule::in(self::LEAVE_TYPES)],
            'entitlement' => ['required', 'numeric', 'min:0', 'max:365'],
            'used'        => ['nullable', 'numeric', 'min:0'],
            'pending'     => ['nullable', 'numeric', 'min:0'],
        ]);

        $assignment = DB::transaction(function () use ($data, $actor) {
            $from = null;

            $assignment = LeaveAssignment::updateOrCreate(
                [
                    'user_id'    => $data['user_id'],
                    'year'       => $data['year'],
                    'leave_type' => $data['leave_type'],
                ],
                [
                    'entitlement' => $data['entitlement'],
                    'used'        => $data['used'] ?? 0,
                    'pending'     => $data['pending'] ?? 0,
                ]
            );

            LeaveAssignmentHistory::create([
                'assignment_id' => $assignment->id,
                'user_id'       => $assignment->user_id,
                'actor_user_id' => $actor->id,
                'action'        => $assignment->wasRecentlyCreated ? 'created' : 'updated',
                'changes'       => [
                    'entitlement' => $assignment->entitlement,
                    'used'        => $assignment->used,
                    'pending'     => $assignment->pending,
                ],
                'created_at'    => now(),
            ]);

            return $assignment;
        });

        AuditLogger::log($request, 'leave_assignment_saved', null, [
            'assignment_id' => $assignment->id,
            'user_id'       => $assignment->user_id,
            'year'          => $assignment->year,
            'leave_type'    => $assignment->leave_type,
        ]);

        $assignment->load('user');

        // Notify the employee their entitlement was set/updated
        // (deduplicated — only one notification fires per user per 60s batch)
        $this->notificationService->emitAllocationUpdated(
            $assignment->user_id,
            $assignment->user?->name ?? '',
            $assignment->year,
            ['userId' => $actor->id, 'name' => $actor->name, 'email' => $actor->email],
        );

        return response()->json(['data' => $this->formatAssignment($assignment, $assignment->user)], 201);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(Request $request, int $id): JsonResponse
    {
        $actor      = $request->user();
        $assignment = LeaveAssignment::with('user')->findOrFail($id);

        $data = $request->validate([
            'entitlement' => ['sometimes', 'required', 'numeric', 'min:0', 'max:365'],
            'used'        => ['sometimes', 'required', 'numeric', 'min:0'],
            'pending'     => ['sometimes', 'required', 'numeric', 'min:0'],
        ]);

        $from = [
            'entitlement' => $assignment->entitlement,
            'used'        => $assignment->used,
            'pending'     => $assignment->pending,
        ];

        DB::transaction(function () use ($assignment, $data, $actor, $from) {
            $assignment->update($data);

            LeaveAssignmentHistory::create([
                'assignment_id' => $assignment->id,
                'user_id'       => $assignment->user_id,
                'actor_user_id' => $actor->id,
                'action'        => 'updated',
                'changes'       => ['from' => $from, 'to' => $data],
                'created_at'    => now(),
            ]);
        });

        AuditLogger::log($request, 'leave_assignment_updated', null, [
            'assignment_id' => $assignment->id,
        ]);

        $this->notificationService->emitAllocationUpdated(
            $assignment->user_id,
            $assignment->user?->name ?? '',
            $assignment->year,
            ['userId' => $actor->id, 'name' => $actor->name, 'email' => $actor->email],
        );

        return response()->json(['data' => $this->formatAssignment($assignment, $assignment->user)]);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor      = $request->user();
        $assignment = LeaveAssignment::with('user')->findOrFail($id);
        $ownerUserId = (int) $assignment->user_id;
        $ownerName = (string) ($assignment->user?->name ?? '');
        $assignmentYear = (int) $assignment->year;
        $leaveType = (string) $assignment->leave_type;

        DB::transaction(function () use ($assignment, $actor) {
            LeaveAssignmentHistory::create([
                'assignment_id' => $assignment->id,
                'user_id'       => $assignment->user_id,
                'actor_user_id' => $actor->id,
                'action'        => 'deleted',
                'changes'       => [
                    'entitlement' => $assignment->entitlement,
                    'used'        => $assignment->used,
                    'pending'     => $assignment->pending,
                ],
                'created_at'    => now(),
            ]);

            $assignment->delete();
        });

        AuditLogger::log($request, 'leave_assignment_deleted', null, [
            'assignment_id' => $id,
        ]);

        $this->notificationService->emitAllocationDeleted(
            $ownerUserId,
            $ownerName,
            $assignmentYear,
            $leaveType,
            ['userId' => $actor->id, 'name' => $actor->name, 'email' => $actor->email],
        );

        return response()->json(['message' => 'Assignment deleted.']);
    }

    // ── Format ────────────────────────────────────────────────────────────────

    private function formatAssignment(LeaveAssignment $a, ?User $user): array
    {
        return [
            'id'          => $a->id,
            'user_id'     => $a->user_id,
            'year'        => $a->year,
            'employee'    => $user?->name ?? '',
            'team'        => $user?->team ?? '',
            'leave_type'  => $a->leave_type,
            'entitlement' => (float) $a->entitlement,
            'used'        => (float) $a->used,
            'pending'     => (float) $a->pending,
        ];
    }
}
