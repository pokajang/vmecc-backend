<?php

namespace App\Http\Controllers;

use App\Models\PayrollClaim;
use App\Models\PayrollClaimPaymentEvent;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\PayrollClaimWorkflowService;
use App\Services\WorkflowNotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollClaimWorkflowController extends Controller
{
    public function __construct(
        private readonly PayrollClaimWorkflowService $workflowService,
        private readonly WorkflowNotificationService $notificationService,
        private readonly AssignmentAuthorizationService $authorizationService,
    ) {
    }

    public function check(Request $request, int $ownerId, int $claimId): JsonResponse
    {
        return $this->handleAction($request, $ownerId, $claimId, 'check');
    }

    public function review(Request $request, int $ownerId, int $claimId): JsonResponse
    {
        return $this->handleAction($request, $ownerId, $claimId, 'review');
    }

    public function approve(Request $request, int $ownerId, int $claimId): JsonResponse
    {
        return $this->handleAction($request, $ownerId, $claimId, 'approve');
    }

    public function reject(Request $request, int $ownerId, int $claimId): JsonResponse
    {
        return $this->handleAction($request, $ownerId, $claimId, 'reject');
    }

    public function cancel(Request $request, int $ownerId, int $claimId): JsonResponse
    {
        return $this->handleAction($request, $ownerId, $claimId, 'cancel');
    }

    public function markPaid(Request $request, int $ownerId, int $claimId): JsonResponse
    {
        $actor = $request->user();
        $payload = $request->validate([
            'payment_date' => ['required', 'date'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'payment_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $claim = PayrollClaim::query()
            ->where('user_id', $ownerId)
            ->with(['items.attachment', 'attachment', 'paidByUser'])
            ->findOrFail($claimId);

        $this->assertMarkPaidAllowed($claim, $actor);

        DB::transaction(function () use ($claim, $actor, $payload): void {
            $paymentDate = Carbon::parse((string) $payload['payment_date'])->toDateString();
            $now = now();
            $history = is_array($claim->approval_history) ? $claim->approval_history : [];
            $history[] = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'action' => 'Paid',
                'by' => (string) ($actor->name ?? ''),
                'byUserId' => (string) ($actor->id ?? ''),
                'at' => $now->toIso8601String(),
                'remarks' => trim((string) ($payload['payment_note'] ?? '')) ?: 'Salary payment marked as paid.',
            ];
            $history = collect($history)->take(-30)->values()->all();

            $snapshot = is_array($claim->payslip_snapshot) ? $claim->payslip_snapshot : [];
            if ($snapshot !== []) {
                $snapshot['status'] = 'Paid';
                $snapshot['paymentDate'] = $paymentDate;
                $snapshot['paidAt'] = $now->toIso8601String();
                $snapshot['paidBy'] = trim((string) ($actor->name ?? ''));
                $snapshot['paymentReference'] = trim((string) ($payload['payment_reference'] ?? ''));
                $snapshot['paymentNote'] = trim((string) ($payload['payment_note'] ?? ''));
            }

            $claim->update([
                'status' => 'Paid',
                'payment_date' => $paymentDate,
                'paid_at' => $now,
                'paid_by_user_id' => (int) $actor->id,
                'payment_reference' => trim((string) ($payload['payment_reference'] ?? '')) ?: null,
                'payment_note' => trim((string) ($payload['payment_note'] ?? '')) ?: null,
                'updated_by' => (string) ($actor->name ?? ''),
                'updated_by_name' => (string) ($actor->name ?? ''),
                'approval_history' => $history,
                'payslip_snapshot' => $snapshot !== [] ? $snapshot : null,
            ]);

            PayrollClaimPaymentEvent::query()->create([
                'claim_id' => (int) $claim->id,
                'action' => 'mark_paid',
                'payment_date' => $paymentDate,
                'payment_reference' => trim((string) ($payload['payment_reference'] ?? '')) ?: null,
                'note' => trim((string) ($payload['payment_note'] ?? '')) ?: null,
                'reason' => null,
                'acted_by_user_id' => (int) $actor->id,
            ]);
        });

        $claim->refresh()->load(['items.attachment', 'attachment', 'paidByUser']);

        AuditLogger::log($request, 'payroll_claim_mark_paid', $actor, [
            'claim_id' => $claim->id,
            'display_id' => $claim->display_id,
            'owner_id' => $claim->user_id,
            'payment_date' => optional($claim->payment_date)->toDateString(),
        ]);

        return response()->json(['data' => PayrollClaimController::formatClaim($claim, $actor)]);
    }

    public function unmarkPaid(Request $request, int $ownerId, int $claimId): JsonResponse
    {
        $actor = $request->user();
        $payload = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $claim = PayrollClaim::query()
            ->where('user_id', $ownerId)
            ->with(['items.attachment', 'attachment', 'paidByUser'])
            ->findOrFail($claimId);

        $this->assertUnmarkPaidAllowed($claim, $actor);

        DB::transaction(function () use ($claim, $actor, $payload): void {
            $reason = trim((string) ($payload['reason'] ?? ''));
            $now = now();
            $history = is_array($claim->approval_history) ? $claim->approval_history : [];
            $history[] = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'action' => 'Unmarked Paid',
                'by' => (string) ($actor->name ?? ''),
                'byUserId' => (string) ($actor->id ?? ''),
                'at' => $now->toIso8601String(),
                'remarks' => $reason,
            ];
            $history = collect($history)->take(-30)->values()->all();

            $snapshot = is_array($claim->payslip_snapshot) ? $claim->payslip_snapshot : [];
            if ($snapshot !== []) {
                $snapshot['status'] = 'Approved';
                $snapshot['paymentDate'] = '';
                $snapshot['paidAt'] = null;
                $snapshot['paidBy'] = null;
                $snapshot['paymentReference'] = '';
                $snapshot['paymentNote'] = '';
            }

            PayrollClaimPaymentEvent::query()->create([
                'claim_id' => (int) $claim->id,
                'action' => 'unmark_paid',
                'payment_date' => optional($claim->payment_date)->toDateString(),
                'payment_reference' => trim((string) ($claim->payment_reference ?? '')) ?: null,
                'note' => trim((string) ($claim->payment_note ?? '')) ?: null,
                'reason' => $reason,
                'acted_by_user_id' => (int) $actor->id,
            ]);

            $claim->update([
                'status' => 'Approved',
                'payment_date' => null,
                'paid_at' => null,
                'paid_by_user_id' => null,
                'payment_reference' => null,
                'payment_note' => null,
                'updated_by' => (string) ($actor->name ?? ''),
                'updated_by_name' => (string) ($actor->name ?? ''),
                'approval_history' => $history,
                'payslip_snapshot' => $snapshot !== [] ? $snapshot : null,
            ]);
        });

        $claim->refresh()->load(['items.attachment', 'attachment', 'paidByUser']);

        AuditLogger::log($request, 'payroll_claim_unmark_paid', $actor, [
            'claim_id' => $claim->id,
            'display_id' => $claim->display_id,
            'owner_id' => $claim->user_id,
        ]);

        return response()->json(['data' => PayrollClaimController::formatClaim($claim, $actor)]);
    }

    public function markPaidBulk(Request $request): JsonResponse
    {
        $actor = $request->user();
        $payload = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:200'],
            'entries.*.owner_id' => ['required', 'integer'],
            'entries.*.claim_id' => ['required', 'integer'],
            'payment_date' => ['required', 'date'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'payment_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $paymentDate = Carbon::parse((string) $payload['payment_date'])->toDateString();
        $updatedRows = [];
        $skipped = [];

        foreach (($payload['entries'] ?? []) as $entry) {
            $ownerId = (int) ($entry['owner_id'] ?? 0);
            $claimId = (int) ($entry['claim_id'] ?? 0);
            if ($ownerId <= 0 || $claimId <= 0) {
                $skipped[] = ['owner_id' => $ownerId, 'claim_id' => $claimId, 'reason' => 'invalid_entry'];
                continue;
            }

            $claim = PayrollClaim::query()
                ->where('user_id', $ownerId)
                ->with(['items.attachment', 'attachment', 'paidByUser'])
                ->find($claimId);
            if (! $claim instanceof PayrollClaim) {
                $skipped[] = ['owner_id' => $ownerId, 'claim_id' => $claimId, 'reason' => 'record_not_found'];
                continue;
            }

            $blockedReason = $this->getMarkPaidBlockedReason($claim, $actor);
            if ($blockedReason !== '') {
                $skipped[] = [
                    'owner_id' => $ownerId,
                    'claim_id' => $claimId,
                    'reason' => $blockedReason,
                ];
                continue;
            }

            DB::transaction(function () use ($claim, $actor, $payload, $paymentDate): void {
                $now = now();
                $history = is_array($claim->approval_history) ? $claim->approval_history : [];
                $history[] = [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'action' => 'Paid',
                    'by' => (string) ($actor->name ?? ''),
                    'byUserId' => (string) ($actor->id ?? ''),
                    'at' => $now->toIso8601String(),
                    'remarks' => trim((string) ($payload['payment_note'] ?? '')) ?: 'Salary payment marked as paid.',
                ];
                $history = collect($history)->take(-30)->values()->all();

                $snapshot = is_array($claim->payslip_snapshot) ? $claim->payslip_snapshot : [];
                if ($snapshot !== []) {
                    $snapshot['status'] = 'Paid';
                    $snapshot['paymentDate'] = $paymentDate;
                    $snapshot['paidAt'] = $now->toIso8601String();
                    $snapshot['paidBy'] = trim((string) ($actor->name ?? ''));
                    $snapshot['paymentReference'] = trim((string) ($payload['payment_reference'] ?? ''));
                    $snapshot['paymentNote'] = trim((string) ($payload['payment_note'] ?? ''));
                }

                $claim->update([
                    'status' => 'Paid',
                    'payment_date' => $paymentDate,
                    'paid_at' => $now,
                    'paid_by_user_id' => (int) $actor->id,
                    'payment_reference' => trim((string) ($payload['payment_reference'] ?? '')) ?: null,
                    'payment_note' => trim((string) ($payload['payment_note'] ?? '')) ?: null,
                    'updated_by' => (string) ($actor->name ?? ''),
                    'updated_by_name' => (string) ($actor->name ?? ''),
                    'approval_history' => $history,
                    'payslip_snapshot' => $snapshot !== [] ? $snapshot : null,
                ]);

                PayrollClaimPaymentEvent::query()->create([
                    'claim_id' => (int) $claim->id,
                    'action' => 'mark_paid',
                    'payment_date' => $paymentDate,
                    'payment_reference' => trim((string) ($payload['payment_reference'] ?? '')) ?: null,
                    'note' => trim((string) ($payload['payment_note'] ?? '')) ?: null,
                    'reason' => null,
                    'acted_by_user_id' => (int) $actor->id,
                ]);
            });

            $claim->refresh()->load(['items.attachment', 'attachment', 'paidByUser']);
            $updatedRows[] = PayrollClaimController::formatClaim($claim, $actor);
        }

        AuditLogger::log($request, 'payroll_claim_mark_paid_bulk', $actor, [
            'updated_count' => count($updatedRows),
            'skipped_count' => count($skipped),
            'payment_date' => $paymentDate,
        ]);

        return response()->json([
            'data' => [
                'updated_rows' => $updatedRows,
                'skipped' => $skipped,
            ],
        ]);
    }

    public function unmarkPaidBulk(Request $request): JsonResponse
    {
        $actor = $request->user();
        $payload = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:200'],
            'entries.*.owner_id' => ['required', 'integer'],
            'entries.*.claim_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $reason = trim((string) ($payload['reason'] ?? ''));
        $updatedRows = [];
        $skipped = [];

        foreach (($payload['entries'] ?? []) as $entry) {
            $ownerId = (int) ($entry['owner_id'] ?? 0);
            $claimId = (int) ($entry['claim_id'] ?? 0);
            if ($ownerId <= 0 || $claimId <= 0) {
                $skipped[] = ['owner_id' => $ownerId, 'claim_id' => $claimId, 'reason' => 'invalid_entry'];
                continue;
            }

            $claim = PayrollClaim::query()
                ->where('user_id', $ownerId)
                ->with(['items.attachment', 'attachment', 'paidByUser'])
                ->find($claimId);
            if (! $claim instanceof PayrollClaim) {
                $skipped[] = ['owner_id' => $ownerId, 'claim_id' => $claimId, 'reason' => 'record_not_found'];
                continue;
            }

            $blockedReason = $this->getUnmarkPaidBlockedReason($claim, $actor);
            if ($blockedReason !== '') {
                $skipped[] = [
                    'owner_id' => $ownerId,
                    'claim_id' => $claimId,
                    'reason' => $blockedReason,
                ];
                continue;
            }

            DB::transaction(function () use ($claim, $actor, $reason): void {
                $now = now();
                $history = is_array($claim->approval_history) ? $claim->approval_history : [];
                $history[] = [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'action' => 'Unmarked Paid',
                    'by' => (string) ($actor->name ?? ''),
                    'byUserId' => (string) ($actor->id ?? ''),
                    'at' => $now->toIso8601String(),
                    'remarks' => $reason,
                ];
                $history = collect($history)->take(-30)->values()->all();

                $snapshot = is_array($claim->payslip_snapshot) ? $claim->payslip_snapshot : [];
                if ($snapshot !== []) {
                    $snapshot['status'] = 'Approved';
                    $snapshot['paymentDate'] = '';
                    $snapshot['paidAt'] = null;
                    $snapshot['paidBy'] = null;
                    $snapshot['paymentReference'] = '';
                    $snapshot['paymentNote'] = '';
                }

                PayrollClaimPaymentEvent::query()->create([
                    'claim_id' => (int) $claim->id,
                    'action' => 'unmark_paid',
                    'payment_date' => optional($claim->payment_date)->toDateString(),
                    'payment_reference' => trim((string) ($claim->payment_reference ?? '')) ?: null,
                    'note' => trim((string) ($claim->payment_note ?? '')) ?: null,
                    'reason' => $reason,
                    'acted_by_user_id' => (int) $actor->id,
                ]);

                $claim->update([
                    'status' => 'Approved',
                    'payment_date' => null,
                    'paid_at' => null,
                    'paid_by_user_id' => null,
                    'payment_reference' => null,
                    'payment_note' => null,
                    'updated_by' => (string) ($actor->name ?? ''),
                    'updated_by_name' => (string) ($actor->name ?? ''),
                    'approval_history' => $history,
                    'payslip_snapshot' => $snapshot !== [] ? $snapshot : null,
                ]);
            });

            $claim->refresh()->load(['items.attachment', 'attachment', 'paidByUser']);
            $updatedRows[] = PayrollClaimController::formatClaim($claim, $actor);
        }

        AuditLogger::log($request, 'payroll_claim_unmark_paid_bulk', $actor, [
            'updated_count' => count($updatedRows),
            'skipped_count' => count($skipped),
        ]);

        return response()->json([
            'data' => [
                'updated_rows' => $updatedRows,
                'skipped' => $skipped,
            ],
        ]);
    }

    private function handleAction(Request $request, int $ownerId, int $claimId, string $action): JsonResponse
    {
        $actor = $request->user();
        $payload = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $claim = PayrollClaim::query()->where('user_id', $ownerId)->with(['items.attachment', 'attachment', 'paidByUser'])->findOrFail($claimId);

        $this->assertActionAllowed($claim, $action, $actor);

        $snapshot = is_array($claim->workflow_snapshot) ? $claim->workflow_snapshot : [];
        $history = is_array($claim->approval_history) ? $claim->approval_history : [];
        $updates = $this->workflowService->advanceWorkflow(
            snapshot: $snapshot,
            history: $history,
            action: $action,
            actorUserId: (int) $actor->id,
            actorName: (string) ($actor->name ?? ''),
            remarks: $payload['remarks'] ?? null,
        );

        $claim->update($this->toColumnKeys($updates));
        if ($action === 'approve' && (string) ($claim->status ?? '') === 'Approved') {
            $approvedAt = $this->resolveLatestWorkflowActionTimestamp($claim->approval_history, 'approved');
            $claim->update([
                'payslip_snapshot' => $this->buildApprovedPayslipSnapshot($claim, $approvedAt),
            ]);
        }
        $claim->refresh()->load(['items.attachment', 'attachment', 'paidByUser']);

        $eventType = match ($action) {
            'check' => 'checked',
            'review' => 'reviewed',
            'approve' => 'approved',
            'reject' => 'rejected',
            'cancel' => 'cancelled',
            default => $action,
        };

        $module = $claim->claim_type === 'salary' ? 'salary' : ($claim->claim_type === 'exceptional' ? 'exceptional' : 'expense');
        $nextRole = trim((string) ($claim->next_action_role ?? ''));

        $this->notificationService->emit(
            module: $module,
            eventType: $eventType,
            recordType: 'payroll_claim',
            recordId: $claim->id,
            recordDisplayId: $claim->display_id,
            ownerUserId: (int) $claim->user_id,
            actor: ['userId' => $actor->id, 'name' => $actor->name, 'email' => $actor->email],
            targetRoles: $nextRole !== '' ? [$nextRole] : [],
            targetUserIds: [(int) $ownerId],
            actionRequired: $nextRole !== '',
            remarks: $payload['remarks'] ?? null,
            metadata: [
                'module' => $module,
                'status' => $claim->status,
                'workflowStage' => $claim->workflow_stage,
                'nextActionRole' => $claim->next_action_role,
                'claimType' => $claim->claim_type,
            ],
        );

        AuditLogger::log($request, "payroll_claim_{$action}", $actor, [
            'claim_id' => $claim->id,
            'display_id' => $claim->display_id,
            'owner_id' => $claim->user_id,
        ]);

        return response()->json(['data' => PayrollClaimController::formatClaim($claim, $actor)]);
    }

    private function assertActionAllowed(PayrollClaim $claim, string $action, $actor): void
    {
        if ($action !== 'cancel' && $claim->status !== 'Pending') {
            throw ValidationException::withMessages([
                'status' => ['Only pending claims can be processed.'],
            ]);
        }

        if ($action === 'cancel' && !in_array($claim->status, ['Pending', 'Approved'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only pending or approved claims can be cancelled.'],
            ]);
        }

        $roles = $this->authorizationService->getActiveRoleNames($actor)->all();
        if (in_array('System Administrator', $roles, true)) {
            return;
        }

        if (in_array($action, ['reject', 'cancel'], true)) {
            return;
        }

        $requiredRole = trim((string) ($claim->next_action_role ?? ''));
        if ($requiredRole !== '' && !in_array($requiredRole, $roles, true)) {
            throw ValidationException::withMessages([
                'role' => ["This action requires the '{$requiredRole}' role."],
            ]);
        }

        $requiredStage = match ($action) {
            'check' => 'check',
            'review' => 'review',
            'approve' => 'approve',
            default => '',
        };

        if ($requiredStage !== '' && $claim->workflow_stage !== $requiredStage) {
            throw ValidationException::withMessages([
                'stage' => ["Current workflow stage is '{$claim->workflow_stage}', not '{$requiredStage}'."],
            ]);
        }
    }

    private function toColumnKeys(array $updates): array
    {
        $mapped = [];
        foreach ($updates as $key => $value) {
            $mapped[match ($key) {
                'workflowStage' => 'workflow_stage',
                'nextActionRole' => 'next_action_role',
                'approvalHistory' => 'approval_history',
                default => $key,
            }] = $value;
        }
        return $mapped;
    }

    private function resolveLatestWorkflowActionTimestamp(array|null $history, string $action): ?\Carbon\Carbon
    {
        $rows = is_array($history) ? $history : [];
        $match = collect($rows)
            ->reverse()
            ->first(function ($entry) use ($action) {
                if (! is_array($entry)) return false;
                $currentAction = strtolower(trim((string) ($entry['action'] ?? '')));
                return $currentAction === strtolower(trim($action));
            });
        $at = is_array($match) ? trim((string) ($match['at'] ?? '')) : '';
        if ($at === '') return null;
        try {
            return \Carbon\Carbon::parse($at);
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildApprovedPayslipSnapshot(PayrollClaim $claim, ?\Carbon\Carbon $approvedAt): array
    {
        $periodValue = trim((string) ($claim->period_value ?? ''));
        $periodLabel = trim((string) ($claim->period ?? ''));
        $displayPeriod = $periodLabel !== '' ? $periodLabel : $periodValue;
        $snapshot = is_array($claim->payroll_snapshot) ? $claim->payroll_snapshot : [];
        $items = $claim->relationLoaded('items') ? $claim->items : collect();
        $adjustments = $items->map(function ($item) {
            $rawAmount = round((float) ($item->amount ?? 0), 2);
            $itemType = strtolower(trim((string) ($item->item_type ?? '')));
            $isDeduction = in_array($itemType, ['deduction', 'deduct', 'minus'], true) || $rawAmount < 0;
            $amount = round(abs($rawAmount), 2);
            return [
                'lineNo' => (int) ($item->line_no ?? 0),
                'itemType' => trim((string) ($item->item_type ?? '')),
                'title' => trim((string) ($item->title ?? '')),
                'claimDate' => optional($item->claim_date)->toDateString(),
                'amount' => $amount,
                'direction' => $isDeduction ? 'deduction' : 'addition',
                'signedAmount' => $isDeduction ? -$amount : $amount,
                'notes' => trim((string) ($item->notes ?? '')),
            ];
        })->values()->all();

        return [
            'payslipId' => $claim->id,
            'reference' => $claim->display_id,
            'employeeId' => $claim->user_id,
            'period' => [
                'label' => $displayPeriod,
                'value' => $periodValue,
            ],
            'issuedAt' => optional($approvedAt)->toIso8601String(),
            'paymentDate' => optional($claim->payment_date)->toDateString() ?: '',
            'status' => (string) ($claim->status ?? ''),
            'baselineSource' => 'claim_snapshot',
            'salaryRecord' => null,
            'payrollSnapshot' => $snapshot,
            'baseline' => [
                'basicSalary' => (float) ($snapshot['basic'] ?? $snapshot['basicSalary'] ?? 0),
                'allowanceTotal' => (float) ($snapshot['allowance'] ?? $snapshot['allowanceTotal'] ?? 0),
                'grossSalary' => (float) ($snapshot['gross'] ?? $snapshot['grossSalary'] ?? 0),
                'employeeDeductionsTotal' => (float) ($snapshot['totalDeductions'] ?? $snapshot['employeeDeductionsTotal'] ?? 0),
                'netSalary' => (float) ($snapshot['net'] ?? $snapshot['netSalary'] ?? 0),
                'allowanceItems' => is_array($snapshot['allowanceItems'] ?? null) ? $snapshot['allowanceItems'] : [],
                'deductionItems' => is_array($snapshot['deductionItems'] ?? null) ? $snapshot['deductionItems'] : [],
                'employeeContributions' => is_array($snapshot['employeeContributions'] ?? null) ? $snapshot['employeeContributions'] : [],
                'employerContributions' => is_array($snapshot['employerContributions'] ?? null) ? $snapshot['employerContributions'] : [],
            ],
            'adjustments' => $adjustments,
            'adjustmentsTotal' => (float) ($claim->adjustments_total ?? 0),
            'overtime' => [
                'rows' => is_array($claim->overtime_rows) ? $claim->overtime_rows : [],
                'approvedPayout' => (float) ($claim->approved_overtime_payout ?? 0),
            ],
            'totals' => [
                'baselineNetSalary' => (float) ($snapshot['net'] ?? $snapshot['netSalary'] ?? 0),
                'adjustmentsTotal' => (float) ($claim->adjustments_total ?? 0),
                'approvedOvertimePayout' => (float) ($claim->approved_overtime_payout ?? 0),
                'netPayable' => (float) ($claim->projected_net_payout ?? 0),
                'claimedTotal' => (float) ($claim->amount ?? 0),
            ],
            'issuedSnapshotAt' => now()->toIso8601String(),
        ];
    }

    private function assertMarkPaidAllowed(PayrollClaim $claim, mixed $actor): void
    {
        $blockedReason = $this->getMarkPaidBlockedReason($claim, $actor);
        if ($blockedReason !== '') {
            throw ValidationException::withMessages([
                'status' => [$blockedReason],
            ]);
        }
    }

    private function assertUnmarkPaidAllowed(PayrollClaim $claim, mixed $actor): void
    {
        $blockedReason = $this->getUnmarkPaidBlockedReason($claim, $actor);
        if ($blockedReason !== '') {
            throw ValidationException::withMessages([
                'status' => [$blockedReason],
            ]);
        }
    }

    private function getMarkPaidBlockedReason(PayrollClaim $claim, mixed $actor): string
    {
        if (trim((string) ($claim->claim_type ?? '')) !== 'salary') {
            return 'Only salary claims can be marked as paid.';
        }

        if (trim((string) ($claim->status ?? '')) !== 'Approved') {
            return 'Only approved salary claims can be marked as paid.';
        }

        if (! $this->canManageSalaryPay($actor)) {
            return 'Missing required permission: staff.salary.pay.';
        }

        return '';
    }

    private function getUnmarkPaidBlockedReason(PayrollClaim $claim, mixed $actor): string
    {
        if (trim((string) ($claim->claim_type ?? '')) !== 'salary') {
            return 'Only salary claims can be unmarked as paid.';
        }

        if (trim((string) ($claim->status ?? '')) !== 'Paid') {
            return 'Only paid salary claims can be unmarked.';
        }

        if (! $this->canManageSalaryPay($actor)) {
            return 'Missing required permission: staff.salary.pay.';
        }

        return '';
    }

    private function canManageSalaryPay(mixed $actor): bool
    {
        if (! is_object($actor)) {
            return false;
        }

        try {
            $roles = $this->authorizationService->getActiveRoleNames($actor)->all();
            if (in_array('System Administrator', $roles, true)) {
                return true;
            }
        } catch (\Throwable) {
            // Ignore role-read failures and continue.
        }

        try {
            if (method_exists($actor, 'can') && $actor->can('staff.salary.pay')) {
                return true;
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
}
