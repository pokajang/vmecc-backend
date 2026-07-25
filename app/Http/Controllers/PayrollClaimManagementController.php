<?php

namespace App\Http\Controllers;

use App\Models\PayrollClaim;
use App\Services\AssignmentAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PayrollClaimManagementController extends Controller
{
    public function __construct(private readonly AssignmentAuthorizationService $authorizationService) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $query = PayrollClaim::query()->with(['user', 'items.attachment', 'attachment', 'paidByUser'])->orderByDesc('submitted_at')->orderByDesc('id');
        $action = strtolower(trim((string) $request->input('action', '')));
        if ($action !== '' && ! in_array($action, ['check', 'review', 'approve', 'mark_paid'], true)) {
            throw ValidationException::withMessages([
                'action' => ['Action must be check, review, approve, or mark_paid.'],
            ]);
        }
        if ($action === 'mark_paid') {
            if (! $this->authorizationService->hasPermission($actor, 'staff.salary.pay')) {
                abort(403, 'You are not allowed to process salary payments.');
            }
            $query->where('claim_type', 'salary')->where('status', 'Approved')->whereNull('paid_at');
        } elseif ($action !== '') {
            $roles = $this->authorizationService->getActiveRoleNames($actor);
            $query->where('status', 'Pending')->where('workflow_stage', $action);
            if (! $roles->contains('System Administrator')) {
                $query->whereIn('next_action_role', $roles->all());
            }
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('claim_type') && $request->input('claim_type') !== 'All') {
            $query->where('claim_type', strtolower((string) $request->input('claim_type')));
        }
        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($term) {
                $builder->where('display_id', 'like', "%{$term}%")
                    ->orWhere('category', 'like', "%{$term}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$term}%"));
            });
        }

        $rows = $query->get()->map(function (PayrollClaim $row) use ($actor) {
            $data = PayrollClaimController::formatClaim($row, $actor);
            $data['owner_id'] = $row->user_id;
            $data['owner_label'] = $row->user?->name ?? ($row->submitted_by_name ?: "User {$row->user_id}");
            $data['owner_email'] = $row->user?->email ?? '';
            $data['record_key'] = $row->user_id.'::'.$row->id;

            return $data;
        });

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, int $ownerId, int $claimId): JsonResponse
    {
        $row = PayrollClaim::query()
            ->where('user_id', $ownerId)
            ->with(['user', 'items.attachment', 'attachment', 'paidByUser'])
            ->findOrFail($claimId);

        $data = PayrollClaimController::formatClaim($row, $request->user());
        $data['owner_id'] = $row->user_id;
        $data['owner_label'] = $row->user?->name ?? ($row->submitted_by_name ?: "User {$row->user_id}");
        $data['owner_email'] = $row->user?->email ?? '';
        $data['record_key'] = $row->user_id.'::'.$row->id;

        return response()->json(['data' => $data]);
    }
}
