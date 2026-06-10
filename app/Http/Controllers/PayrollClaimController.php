<?php

namespace App\Http\Controllers;

use App\Models\PayrollClaim;
use App\Models\PayrollClaimDraft;
use App\Models\PayrollClaimItem;
use App\Models\WorkflowAttachment;
use App\Services\AuditLogger;
use App\Services\PayrollClaimWorkflowService;
use App\Services\WorkflowNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayrollClaimController extends Controller
{
    public function __construct(
        private readonly PayrollClaimWorkflowService $workflowService,
        private readonly WorkflowNotificationService $notificationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = PayrollClaim::query()
            ->where('user_id', $user->id)
            ->with(['items.attachment', 'attachment', 'paidByUser']);

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('claim_type') && $request->input('claim_type') !== 'All') {
            $query->where('claim_type', $this->normalizeClaimType($request->input('claim_type')));
        }
        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($term) {
                $builder->where('display_id', 'like', "%{$term}%")
                    ->orWhere('category', 'like', "%{$term}%")
                    ->orWhere('notes', 'like', "%{$term}%");
            });
        }
        if ($request->filled('period_value') && preg_match('/^\d{4}-\d{2}$/', (string) $request->input('period_value'))) {
            $query->where('period_value', $request->input('period_value'));
        }

        $sort = explode(':', (string) $request->input('sort', 'submitted_at:desc'));
        $sortCol = in_array($sort[0] ?? '', ['submitted_at', 'amount', 'status', 'period_value'], true)
            ? $sort[0]
            : 'submitted_at';
        $sortDir = ($sort[1] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $rows = $query->orderBy($sortCol, $sortDir)->orderByDesc('id')->get()
            ->map(fn (PayrollClaim $row) => self::formatClaim($row));

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $row = PayrollClaim::query()
            ->where('user_id', $user->id)
            ->with(['items.attachment', 'attachment', 'paidByUser'])
            ->findOrFail($id);

        return response()->json(['data' => self::formatClaim($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validatePayload($request);
        $claimAttachmentId = $this->normalizeAttachmentId($data['attachment_id'] ?? $data['attachmentId'] ?? null);
        $items = $this->sanitizeItems($data['items'] ?? []);
        $this->assertAttachmentOwnership(
            ownerUserId: (int) $user->id,
            attachmentIds: array_merge(
                $claimAttachmentId ? [$claimAttachmentId] : [],
                collect($items)->pluck('attachment_id')->filter()->values()->all(),
            ),
        );

        $claimType = $this->normalizeClaimType($data['claim_type'] ?? 'expense');
        $payrollBaselineConfirmed = filter_var(
            $data['payroll_baseline_confirmed'] ?? $data['payrollBaselineConfirmed'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );
        $payrollSnapshot = $this->normalizePayrollSnapshot(
            $data['payroll_snapshot'] ?? null,
            $claimType === 'salary' ? $payrollBaselineConfirmed : null,
        );
        $sourceDraftId = trim((string) ($data['source_draft_id'] ?? $data['sourceDraftId'] ?? ''));
        $sourceDraftType = $this->normalizeClaimType($data['source_draft_type'] ?? $data['sourceDraftType'] ?? $claimType);
        $submissionKey = $this->normalizeSubmissionKey($data['submission_key'] ?? $data['submissionKey'] ?? '');
        if ($submissionKey !== '') {
            $existingClaim = PayrollClaim::query()
                ->where('user_id', $user->id)
                ->where('submission_key', $submissionKey)
                ->with(['items.attachment', 'attachment'])
                ->first();
            if ($existingClaim instanceof PayrollClaim) {
                return response()->json([
                    'data' => array_merge(
                        self::formatClaim($existingClaim),
                        [
                            'consumed_draft_id' => null,
                            'consumed_draft_type' => null,
                            'idempotent_replay' => true,
                        ],
                    ),
                ]);
            }
        }
        $workflow = $this->workflowService->buildWorkflowForSubmission();
        $periodValue = trim((string) ($data['period_value'] ?? ''));
        $periodLabel = trim((string) ($data['period'] ?? ''));
        $this->assertUniqueSalaryClaimPeriod(
            ownerUserId: (int) $user->id,
            claimType: $claimType,
            periodValue: $periodValue,
        );
        $basicSalary = (float) data_get($payrollSnapshot, 'basic', 0);

        $overtimeSnapshot = $claimType === 'salary' && preg_match('/^\d{4}-\d{2}$/', $periodValue)
            ? $this->workflowService->calculateSalaryOvertimeSnapshot(
                userId: (int) $user->id,
                periodValue: $periodValue,
                assignedBasicSalary: $basicSalary,
                applicantRoles: $user->roles?->pluck('name')->values()->all() ?? [],
            )
            : ['rows' => [], 'totals' => ['allHours' => 0, 'approvedHours' => 0, 'approvedPayout' => 0, 'approvedCount' => 0], 'rateSnapshot' => null];

        $submittedAt = now();
        $displayId = $this->workflowService->generateDisplayId($user->id, (int) $submittedAt->format('Y'));
        $historyEntry = [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'action' => 'Submitted',
            'by' => (string) ($user->name ?? ''),
            'byUserId' => (string) $user->id,
            'at' => $submittedAt->toIso8601String(),
            'remarks' => 'Claim submitted.',
        ];
        $manualAmount = round((float) collect($items)->reject(fn ($item) => $this->isOtFallbackItem($item))->sum(fn ($item) => (float) ($item['amount'] ?? 0)), 2);
        $approvedOtPayout = round((float) data_get($overtimeSnapshot, 'totals.approvedPayout', 0), 2);
        $totalAmount = round($manualAmount + $approvedOtPayout, 2);

        [$adjustmentsTotal, $projectedNetPayout] = $this->computeSalaryTotals(
            claimType: $claimType,
            items: $items,
            approvedOtPayout: $approvedOtPayout,
            snapshotNet: (float) data_get($payrollSnapshot, 'net', 0),
        );

        $consumedDraftId = null;
        try {
            $row = DB::transaction(function () use ($user, $data, $claimType, $displayId, $workflow, $historyEntry, $items, $periodLabel, $periodValue, $payrollSnapshot, $overtimeSnapshot, $approvedOtPayout, $totalAmount, $adjustmentsTotal, $projectedNetPayout, $submittedAt, $claimAttachmentId, $sourceDraftId, $sourceDraftType, $submissionKey, &$consumedDraftId) {
                $claim = PayrollClaim::query()->create([
                    'user_id' => $user->id,
                    'display_id' => $displayId,
                    'submission_key' => $submissionKey !== '' ? $submissionKey : null,
                    'claim_type' => $claimType,
                    'category' => trim((string) ($data['category'] ?? '')),
                    'period' => $periodLabel,
                    'period_value' => $periodValue,
                    'amount' => $totalAmount,
                    'approved_overtime_payout' => $approvedOtPayout,
                    'adjustments_total' => $adjustmentsTotal,
                    'projected_net_payout' => $projectedNetPayout,
                    'status' => 'Pending',
                    'submitted_at' => $submittedAt,
                    'submitted_by' => (string) ($user->name ?? ''),
                    'submitted_by_name' => (string) ($user->name ?? ''),
                    'updated_by' => (string) ($user->name ?? ''),
                    'updated_by_name' => (string) ($user->name ?? ''),
                    'workflow_stage' => $workflow['workflowStage'],
                    'workflow_snapshot' => $workflow['workflowSnapshot'],
                    'next_action_role' => $workflow['nextActionRole'],
                    'approval_history' => [$historyEntry],
                    'payroll_snapshot' => $payrollSnapshot,
                    'overtime_rows' => is_array($overtimeSnapshot['rows'] ?? null) ? $overtimeSnapshot['rows'] : [],
                    'overtime_rate_snapshot' => is_array($overtimeSnapshot['rateSnapshot'] ?? null) ? $overtimeSnapshot['rateSnapshot'] : null,
                    'notes' => trim((string) ($data['notes'] ?? '')),
                    'attachment_id' => $claimAttachmentId,
                ]);

                foreach ($items as $index => $item) {
                    PayrollClaimItem::query()->create([
                        'payroll_claim_id' => $claim->id,
                        'line_no' => $index + 1,
                        'item_type' => $item['item_type'],
                        'title' => $item['title'],
                        'claim_date' => $item['claim_date'],
                        'amount' => (float) ($item['amount'] ?? 0),
                        'notes' => $item['notes'],
                        'item_meta' => $item['item_meta'],
                        'attachment_id' => $item['attachment_id'] ?? null,
                    ]);
                }

                $consumedDraftId = $this->consumeSourceDraft(
                    ownerUserId: (int) $user->id,
                    sourceDraftId: $sourceDraftId,
                    sourceDraftType: $sourceDraftType,
                );

                return $claim;
            });
        } catch (QueryException $exception) {
            if ($submissionKey !== '' && $this->isSubmissionKeyDuplicateException($exception)) {
                $existingClaim = PayrollClaim::query()
                    ->where('user_id', $user->id)
                    ->where('submission_key', $submissionKey)
                    ->with(['items.attachment', 'attachment'])
                    ->first();
                if ($existingClaim instanceof PayrollClaim) {
                    return response()->json([
                        'data' => array_merge(
                            self::formatClaim($existingClaim),
                            [
                                'consumed_draft_id' => null,
                                'consumed_draft_type' => null,
                                'idempotent_replay' => true,
                            ],
                        ),
                    ]);
                }
            }
            throw $exception;
        }

        $module = $claimType === 'salary' ? 'salary' : ($claimType === 'exceptional' ? 'exceptional' : 'expense');
        $nextRole = trim((string) ($workflow['nextActionRole'] ?? ''));

        $this->notificationService->emit(
            module: $module,
            eventType: 'submitted',
            recordType: 'payroll_claim',
            recordId: $row->id,
            recordDisplayId: $row->display_id,
            ownerUserId: (int) $row->user_id,
            actor: ['userId' => $user->id, 'name' => $user->name, 'email' => $user->email],
            targetRoles: $nextRole !== '' ? [$nextRole] : [],
            actionRequired: $nextRole !== '',
            metadata: [
                'module' => $module,
                'status' => 'Pending',
                'workflowStage' => $workflow['workflowStage'],
                'nextActionRole' => $nextRole !== '' ? $nextRole : null,
                'claimType' => $claimType,
            ],
            excludeOwner: true,
        );

        AuditLogger::log($request, 'payroll_claim_submitted', $user, [
            'claim_id' => $row->id,
            'display_id' => $row->display_id,
            'claim_type' => $row->claim_type,
        ]);

        $row->load(['items.attachment', 'attachment']);

        return response()->json([
            'data' => array_merge(
                self::formatClaim($row, $user),
                [
                    'consumed_draft_id' => $consumedDraftId,
                    'consumed_draft_type' => $consumedDraftId ? $sourceDraftType : null,
                    'idempotent_replay' => false,
                ],
            ),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $row = PayrollClaim::query()->where('user_id', $user->id)->with(['items', 'attachment'])->findOrFail($id);

        if (! in_array($row->status, ['Pending', 'Draft'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only pending or draft claims can be edited.'],
            ]);
        }

        $data = $this->validatePayload($request);
        $payrollBaselineConfirmed = filter_var(
            $data['payroll_baseline_confirmed'] ?? $data['payrollBaselineConfirmed'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );
        $claimAttachmentId = $this->normalizeAttachmentId($data['attachment_id'] ?? $data['attachmentId'] ?? null);
        $claimAttachmentProvided =
            array_key_exists('attachment_id', $data) || array_key_exists('attachmentId', $data);
        $items = $this->sanitizeItems($data['items'] ?? []);
        $this->assertAttachmentOwnership(
            ownerUserId: (int) $user->id,
            attachmentIds: array_merge(
                $claimAttachmentId ? [$claimAttachmentId] : [],
                collect($items)->pluck('attachment_id')->filter()->values()->all(),
            ),
        );
        $claimType = $this->normalizeClaimType($data['claim_type'] ?? $row->claim_type);
        $payrollSnapshot = $this->normalizePayrollSnapshot(
            $data['payroll_snapshot'] ?? $row->payroll_snapshot,
            $claimType === 'salary' ? $payrollBaselineConfirmed : null,
        );
        $sourceDraftId = trim((string) ($data['source_draft_id'] ?? $data['sourceDraftId'] ?? ''));
        $sourceDraftType = $this->normalizeClaimType($data['source_draft_type'] ?? $data['sourceDraftType'] ?? $claimType);

        $workflow = $this->workflowService->buildWorkflowForSubmission();
        $periodValue = trim((string) ($data['period_value'] ?? $row->period_value ?? ''));
        $this->assertUniqueSalaryClaimPeriod(
            ownerUserId: (int) $user->id,
            claimType: $claimType,
            periodValue: $periodValue,
            ignoreClaimId: (int) $row->id,
        );
        $basicSalary = (float) data_get($payrollSnapshot, 'basic', data_get($row->payroll_snapshot, 'basic', 0));

        $overtimeSnapshot = $claimType === 'salary' && preg_match('/^\d{4}-\d{2}$/', $periodValue)
            ? $this->workflowService->calculateSalaryOvertimeSnapshot(
                userId: (int) $user->id,
                periodValue: $periodValue,
                assignedBasicSalary: $basicSalary,
                applicantRoles: $user->roles?->pluck('name')->values()->all() ?? [],
            )
            : ['rows' => [], 'totals' => ['allHours' => 0, 'approvedHours' => 0, 'approvedPayout' => 0, 'approvedCount' => 0], 'rateSnapshot' => null];

        $history = collect(is_array($row->approval_history) ? $row->approval_history : [])
            ->push([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'action' => 'Edited',
                'by' => (string) ($user->name ?? ''),
                'byUserId' => (string) $user->id,
                'at' => now()->toIso8601String(),
                'remarks' => 'Claim edited and resubmitted.',
            ])
            ->take(-20)
            ->values()
            ->all();

        $manualAmount = round((float) collect($items)->reject(fn ($item) => $this->isOtFallbackItem($item))->sum(fn ($item) => (float) ($item['amount'] ?? 0)), 2);
        $approvedOtPayout = round((float) data_get($overtimeSnapshot, 'totals.approvedPayout', 0), 2);
        $totalAmount = round($manualAmount + $approvedOtPayout, 2);

        [$adjustmentsTotal, $projectedNetPayout] = $this->computeSalaryTotals(
            claimType: $claimType,
            items: $items,
            approvedOtPayout: $approvedOtPayout,
            snapshotNet: (float) data_get($payrollSnapshot, 'net', data_get($row->payroll_snapshot, 'net', 0)),
        );

        $consumedDraftId = null;
        DB::transaction(function () use ($row, $data, $workflow, $history, $items, $claimType, $periodValue, $payrollSnapshot, $overtimeSnapshot, $approvedOtPayout, $totalAmount, $adjustmentsTotal, $projectedNetPayout, $user, $sourceDraftId, $sourceDraftType, $claimAttachmentProvided, $claimAttachmentId, &$consumedDraftId) {
            $row->update([
                'claim_type' => $claimType,
                'category' => trim((string) ($data['category'] ?? $row->category)),
                'period' => trim((string) ($data['period'] ?? $row->period)),
                'period_value' => $periodValue,
                'amount' => $totalAmount,
                'approved_overtime_payout' => $approvedOtPayout,
                'adjustments_total' => $adjustmentsTotal,
                'projected_net_payout' => $projectedNetPayout,
                'status' => 'Pending',
                'updated_by' => (string) ($user->name ?? ''),
                'updated_by_name' => (string) ($user->name ?? ''),
                'workflow_stage' => $workflow['workflowStage'],
                'workflow_snapshot' => $workflow['workflowSnapshot'],
                'next_action_role' => $workflow['nextActionRole'],
                'approval_history' => $history,
                'payroll_snapshot' => $payrollSnapshot,
                'overtime_rows' => is_array($overtimeSnapshot['rows'] ?? null) ? $overtimeSnapshot['rows'] : [],
                'overtime_rate_snapshot' => is_array($overtimeSnapshot['rateSnapshot'] ?? null) ? $overtimeSnapshot['rateSnapshot'] : null,
                'notes' => trim((string) ($data['notes'] ?? $row->notes)),
                'attachment_id' => $claimAttachmentProvided ? $claimAttachmentId : $row->attachment_id,
            ]);

            PayrollClaimItem::query()->where('payroll_claim_id', $row->id)->delete();
            foreach ($items as $index => $item) {
                PayrollClaimItem::query()->create([
                    'payroll_claim_id' => $row->id,
                    'line_no' => $index + 1,
                    'item_type' => $item['item_type'],
                    'title' => $item['title'],
                    'claim_date' => $item['claim_date'],
                    'amount' => (float) ($item['amount'] ?? 0),
                    'notes' => $item['notes'],
                    'item_meta' => $item['item_meta'],
                    'attachment_id' => $item['attachment_id'] ?? null,
                ]);
            }

            $consumedDraftId = $this->consumeSourceDraft(
                ownerUserId: (int) $user->id,
                sourceDraftId: $sourceDraftId,
                sourceDraftType: $sourceDraftType,
            );
        });

        $row->refresh()->load(['items.attachment', 'attachment']);

        $module = $claimType === 'salary' ? 'salary' : ($claimType === 'exceptional' ? 'exceptional' : 'expense');
        $nextRole = trim((string) ($row->next_action_role ?? ''));
        $this->notificationService->emit(
            module: $module,
            eventType: 'edited',
            recordType: 'payroll_claim',
            recordId: $row->id,
            recordDisplayId: $row->display_id,
            ownerUserId: (int) $row->user_id,
            actor: ['userId' => $user->id, 'name' => $user->name, 'email' => $user->email],
            targetRoles: $nextRole !== '' ? [$nextRole] : [],
            actionRequired: $nextRole !== '',
            metadata: [
                'module' => $module,
                'status' => 'Pending',
                'workflowStage' => $row->workflow_stage,
                'nextActionRole' => $row->next_action_role,
                'claimType' => $claimType,
            ],
            excludeOwner: true,
        );

        AuditLogger::log($request, 'payroll_claim_edited', $user, [
            'claim_id' => $row->id,
            'display_id' => $row->display_id,
            'claim_type' => $row->claim_type,
        ]);

        return response()->json([
            'data' => array_merge(
                self::formatClaim($row, $user),
                [
                    'consumed_draft_id' => $consumedDraftId,
                    'consumed_draft_type' => $consumedDraftId ? $sourceDraftType : null,
                ],
            ),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $row = PayrollClaim::query()->where('user_id', $user->id)->findOrFail($id);

        if (! in_array($row->status, ['Draft', 'Cancelled'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only draft or cancelled claims can be deleted.'],
            ]);
        }

        $row->delete();

        AuditLogger::log($request, 'payroll_claim_deleted', $user, [
            'claim_id' => $row->id,
            'display_id' => $row->display_id,
        ]);

        return response()->json(null, 204);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $row = PayrollClaim::query()->where('user_id', $user->id)->with(['items.attachment', 'attachment'])->findOrFail($id);

        if (! in_array($row->status, ['Pending', 'Approved'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only pending or approved claims can be cancelled.'],
            ]);
        }

        $payload = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $snapshot = is_array($row->workflow_snapshot) ? $row->workflow_snapshot : [];
        $updates = $this->workflowService->advanceWorkflow(
            snapshot: $snapshot,
            history: is_array($row->approval_history) ? $row->approval_history : [],
            action: 'cancel',
            actorUserId: (int) $user->id,
            actorName: (string) ($user->name ?? ''),
            remarks: $payload['remarks'] ?? null,
        );

        $row->update($this->toColumnKeys($updates));
        $row->refresh()->load(['items.attachment', 'attachment']);

        $module = $row->claim_type === 'salary' ? 'salary' : ($row->claim_type === 'exceptional' ? 'exceptional' : 'expense');
        $this->notificationService->emit(
            module: $module,
            eventType: 'cancelled',
            recordType: 'payroll_claim',
            recordId: $row->id,
            recordDisplayId: $row->display_id,
            ownerUserId: (int) $row->user_id,
            actor: ['userId' => $user->id, 'name' => $user->name, 'email' => $user->email],
            remarks: $payload['remarks'] ?? null,
            metadata: [
                'module' => $module,
                'status' => 'Cancelled',
                'workflowStage' => 'done',
                'nextActionRole' => null,
                'claimType' => $row->claim_type,
            ],
            excludeOwner: true,
        );

        AuditLogger::log($request, 'payroll_claim_cancelled', $user, [
            'claim_id' => $row->id,
            'display_id' => $row->display_id,
        ]);

        return response()->json(['data' => self::formatClaim($row, $user)]);
    }

    private function isOtFallbackItem(array $item): bool
    {
        return $item['item_type'] === 'Addition'
            && str_contains(strtolower((string) ($item['notes'] ?? '')), 'approved overtime payout');
    }

    private function computeSalaryTotals(string $claimType, array $items, float $approvedOtPayout, float $snapshotNet): array
    {
        if ($claimType !== 'salary') {
            return [null, null];
        }

        $manualItems = collect($items)->reject(fn ($item) => $this->isOtFallbackItem($item));
        $additionsSum = round((float) $manualItems->where('item_type', 'Addition')->sum('amount'), 2);
        $deductionsSum = round((float) $manualItems->where('item_type', 'Deduction')->sum('amount'), 2);
        $adjustmentsTotal = round($additionsSum - $deductionsSum, 2);
        $projectedNetPayout = round($snapshotNet + $adjustmentsTotal + $approvedOtPayout, 2);

        return [$adjustmentsTotal, $projectedNetPayout];
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'claim_type' => ['required', Rule::in(['expense', 'salary', 'exceptional', 'other'])],
            'category' => ['nullable', 'string', 'max:255'],
            'period' => ['nullable', 'string', 'max:100'],
            'period_value' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'source_draft_id' => ['nullable', 'string', 'max:120'],
            'sourceDraftId' => ['nullable', 'string', 'max:120'],
            'source_draft_type' => ['nullable', Rule::in(['expense', 'salary', 'exceptional', 'other'])],
            'sourceDraftType' => ['nullable', Rule::in(['expense', 'salary', 'exceptional', 'other'])],
            'submission_key' => ['nullable', 'string', 'max:190'],
            'submissionKey' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'payroll_baseline_confirmed' => ['nullable', 'boolean'],
            'payrollBaselineConfirmed' => ['nullable', 'boolean'],
            'attachment_id' => ['nullable', 'integer', 'exists:workflow_attachments,id'],
            'attachmentId' => ['nullable', 'integer', 'exists:workflow_attachments,id'],
            'payroll_snapshot' => ['nullable', 'array'],
            'items' => ['nullable', 'array', 'max:200'],
            'items.*' => ['array'],
            'items.*.item_type' => ['nullable', 'string', 'max:120'],
            'items.*.itemType' => ['nullable', 'string', 'max:120'],
            'items.*.claimType' => ['nullable', 'string', 'max:120'],
            'items.*.category' => ['nullable', 'string', 'max:120'],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.claim_date' => ['nullable', 'date'],
            'items.*.claimDate' => ['nullable', 'date'],
            'items.*.expenseDate' => ['nullable', 'date'],
            'items.*.amount' => ['nullable', 'numeric'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
            'items.*.lineNotes' => ['nullable', 'string', 'max:2000'],
            'items.*.approval_note' => ['nullable', 'string', 'max:255'],
            'items.*.approvalNote' => ['nullable', 'string', 'max:255'],
            'items.*.from_location' => ['nullable', 'string', 'max:255'],
            'items.*.fromLocation' => ['nullable', 'string', 'max:255'],
            'items.*.to_location' => ['nullable', 'string', 'max:255'],
            'items.*.toLocation' => ['nullable', 'string', 'max:255'],
            'items.*.distance_km' => ['nullable', 'string', 'max:32'],
            'items.*.distanceKm' => ['nullable', 'string', 'max:32'],
            'items.*.rate_per_km' => ['nullable', 'string', 'max:32'],
            'items.*.ratePerKm' => ['nullable', 'string', 'max:32'],
            'items.*.destination' => ['nullable', 'string', 'max:255'],
            'items.*.trip_date_from' => ['nullable', 'date'],
            'items.*.tripDateFrom' => ['nullable', 'date'],
            'items.*.trip_date_to' => ['nullable', 'date'],
            'items.*.tripDateTo' => ['nullable', 'date'],
            'items.*.billed_period' => ['nullable', 'string', 'max:120'],
            'items.*.billedPeriod' => ['nullable', 'string', 'max:120'],
            'items.*.claimant' => ['nullable', 'string', 'max:120'],
            'items.*.attachment_id' => ['nullable', 'integer', 'exists:workflow_attachments,id'],
            'items.*.attachmentId' => ['nullable', 'integer', 'exists:workflow_attachments,id'],
            'items.*.attachment_name' => ['nullable', 'string', 'max:255'],
            'items.*.attachmentName' => ['nullable', 'string', 'max:255'],
            'items.*.attachment_mime_type' => ['nullable', 'string', 'max:120'],
            'items.*.attachmentMimeType' => ['nullable', 'string', 'max:120'],
            'items.*.attachment_size_bytes' => ['nullable', 'integer', 'min:0', 'max:52428800'],
            'items.*.attachmentSizeBytes' => ['nullable', 'integer', 'min:0', 'max:52428800'],
            'items.*.upload_state' => ['nullable', Rule::in(['idle', 'uploading', 'uploaded', 'failed'])],
            'items.*.uploadState' => ['nullable', Rule::in(['idle', 'uploading', 'uploaded', 'failed'])],
            'items.*.needs_reattach' => ['nullable', 'boolean'],
            'items.*.needsReattach' => ['nullable', 'boolean'],
        ]);
    }

    private function sanitizeItems(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $itemType = trim((string) ($item['item_type'] ?? $item['itemType'] ?? $item['claimType'] ?? $item['category'] ?? ''));
            $title = trim((string) ($item['title'] ?? ''));
            $claimDate = $this->normalizeClaimDate($item['claim_date'] ?? $item['claimDate'] ?? $item['expenseDate'] ?? null);
            $amount = round((float) ($item['amount'] ?? 0), 2);
            $notes = trim((string) ($item['notes'] ?? $item['lineNotes'] ?? ''));
            $approvalNote = trim((string) ($item['approval_note'] ?? $item['approvalNote'] ?? ''));
            $fromLocation = trim((string) ($item['from_location'] ?? $item['fromLocation'] ?? ''));
            $toLocation = trim((string) ($item['to_location'] ?? $item['toLocation'] ?? ''));
            $distanceKm = trim((string) ($item['distance_km'] ?? $item['distanceKm'] ?? ''));
            $ratePerKm = trim((string) ($item['rate_per_km'] ?? $item['ratePerKm'] ?? ''));
            $destination = trim((string) ($item['destination'] ?? ''));
            $tripDateFrom = $this->normalizeClaimDate($item['trip_date_from'] ?? $item['tripDateFrom'] ?? null);
            $tripDateTo = $this->normalizeClaimDate($item['trip_date_to'] ?? $item['tripDateTo'] ?? null);
            $billedPeriod = trim((string) ($item['billed_period'] ?? $item['billedPeriod'] ?? ''));
            $claimant = trim((string) ($item['claimant'] ?? ''));
            $attachmentId = $this->normalizeAttachmentId($item['attachment_id'] ?? $item['attachmentId'] ?? null);
            $attachmentName = trim((string) ($item['attachment_name'] ?? $item['attachmentName'] ?? ''));
            $attachmentMimeType = trim((string) ($item['attachment_mime_type'] ?? $item['attachmentMimeType'] ?? ''));
            $attachmentSizeBytes = max(0, (int) ($item['attachment_size_bytes'] ?? $item['attachmentSizeBytes'] ?? 0));
            $uploadState = $this->normalizeUploadState($item['upload_state'] ?? $item['uploadState'] ?? null);
            $needsReattachRaw = filter_var(
                $item['needs_reattach'] ?? $item['needsReattach'] ?? false,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            );
            $needsReattach = $needsReattachRaw === null ? false : (bool) $needsReattachRaw;

            $itemMeta = $this->sanitizeItemMeta([
                'claimType' => $itemType,
                'title' => $title,
                'claimDate' => $claimDate,
                'amount' => $amount,
                'lineNotes' => $notes,
                'approvalNote' => $approvalNote,
                'fromLocation' => $fromLocation,
                'toLocation' => $toLocation,
                'distanceKm' => $distanceKm,
                'ratePerKm' => $ratePerKm,
                'destination' => $destination,
                'tripDateFrom' => $tripDateFrom,
                'tripDateTo' => $tripDateTo,
                'billedPeriod' => $billedPeriod,
                'claimant' => $claimant,
                'attachmentId' => $attachmentId,
                'attachmentName' => $attachmentName,
                'attachmentMimeType' => $attachmentMimeType,
                'attachmentSizeBytes' => $attachmentSizeBytes,
                'uploadState' => $uploadState,
                'needsReattach' => $needsReattach,
            ]);

            $rows[] = [
                'item_type' => $itemType,
                'title' => $title,
                'claim_date' => $claimDate,
                'amount' => $amount,
                'notes' => $notes,
                'item_meta' => $itemMeta,
                'attachment_id' => $attachmentId,
            ];
        }

        return $rows;
    }

    private function sanitizeItemMeta(array $meta): array
    {
        return collect($meta)
            ->reject(function ($value) {
                if ($value === null) {
                    return true;
                }

                if (is_string($value) && trim($value) === '') {
                    return true;
                }

                return false;
            })
            ->all();
    }

    private function normalizePayrollSnapshot(mixed $snapshot, ?bool $payrollBaselineConfirmed = null): ?array
    {
        if (! is_array($snapshot)) {
            return null;
        }

        $normalized = $snapshot;
        if ($payrollBaselineConfirmed !== null) {
            $normalized['payrollBaselineConfirmed'] = $payrollBaselineConfirmed;
        }

        return $normalized;
    }

    private function normalizeAttachmentId(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }

    private function normalizeClaimDate(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeUploadState(mixed $value): ?string
    {
        $raw = strtolower(trim((string) ($value ?? '')));
        if ($raw === '') {
            return null;
        }

        return in_array($raw, ['idle', 'uploading', 'uploaded', 'failed'], true) ? $raw : null;
    }

    private function assertAttachmentOwnership(int $ownerUserId, array $attachmentIds): void
    {
        $ids = collect($attachmentIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
        if ($ids === []) {
            return;
        }

        $ownedIds = WorkflowAttachment::query()
            ->whereIn('id', $ids)
            ->where('owner_user_id', $ownerUserId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (count($ids) !== count($ownedIds)) {
            throw ValidationException::withMessages([
                'attachment_id' => ['One or more attachments are invalid or do not belong to the current user.'],
            ]);
        }
    }

    private function normalizeClaimType(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === 'other') {
            return 'exceptional';
        }

        return in_array($normalized, ['expense', 'salary', 'exceptional'], true) ? $normalized : 'expense';
    }

    private function normalizeSubmissionKey(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function assertUniqueSalaryClaimPeriod(
        int $ownerUserId,
        string $claimType,
        string $periodValue,
        ?int $ignoreClaimId = null,
    ): void {
        if ($claimType !== 'salary' || ! preg_match('/^\d{4}-\d{2}$/', $periodValue)) {
            return;
        }

        $query = PayrollClaim::query()
            ->where('user_id', $ownerUserId)
            ->where('claim_type', 'salary')
            ->where('period_value', $periodValue)
            ->where('status', '!=', 'Cancelled');

        if ($ignoreClaimId && $ignoreClaimId > 0) {
            $query->where('id', '!=', $ignoreClaimId);
        }

        if (! $query->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'period_value' => [
                sprintf(
                    'Salary claim for %s already exists.',
                    $this->formatPeriodValueLabel($periodValue),
                ),
            ],
        ]);
    }

    private function formatPeriodValueLabel(string $periodValue): string
    {
        try {
            return \Carbon\Carbon::createFromFormat('Y-m', $periodValue)->format('F Y');
        } catch (\Throwable) {
            return $periodValue;
        }
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

        return str_contains($message, 'payroll_claims_user_submission_unique')
            || (str_contains($message, 'submission_key') && str_contains($message, 'duplicate'));
    }

    private function consumeSourceDraft(int $ownerUserId, string $sourceDraftId, string $sourceDraftType): ?string
    {
        if ($sourceDraftId === '') {
            return null;
        }

        $deleted = PayrollClaimDraft::query()
            ->where('user_id', $ownerUserId)
            ->where('claim_type', $sourceDraftType)
            ->where('draft_id', $sourceDraftId)
            ->delete();

        return $deleted > 0 ? $sourceDraftId : null;
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

    public static function formatClaim(PayrollClaim $row, mixed $actor = null): array
    {
        $isSalaryClaim = $row->claim_type === 'salary';
        $rawPayrollSnapshot = is_array($row->payroll_snapshot) ? $row->payroll_snapshot : null;
        $payrollSnapshot = $isSalaryClaim
            ? ($rawPayrollSnapshot ?? (object) [])
            : $rawPayrollSnapshot;
        $payrollBaselineConfirmed = $isSalaryClaim
            ? (bool) data_get($payrollSnapshot ?? [], 'payrollBaselineConfirmed', false)
            : null;
        $overtimeRows = $isSalaryClaim
            ? (is_array($row->overtime_rows) ? $row->overtime_rows : [])
            : $row->overtime_rows;
        $overtimeRateSnapshot = $isSalaryClaim
            ? (is_array($row->overtime_rate_snapshot) ? $row->overtime_rate_snapshot : (object) [])
            : $row->overtime_rate_snapshot;
        $items = $row->relationLoaded('items')
            ? $row->items->map(function (PayrollClaimItem $item) {
                $itemMeta = is_array($item->item_meta) ? $item->item_meta : [];
                return [
                    'id' => $item->id,
                    'lineNo' => $item->line_no,
                    'itemType' => $item->item_type,
                    'title' => $item->title,
                    'claimDate' => optional($item->claim_date)->toDateString(),
                    'amount' => (float) $item->amount,
                    'notes' => $item->notes,
                    'approval_note' => data_get($itemMeta, 'approvalNote', ''),
                    'from_location' => data_get($itemMeta, 'fromLocation', ''),
                    'to_location' => data_get($itemMeta, 'toLocation', ''),
                    'distance_km' => data_get($itemMeta, 'distanceKm', ''),
                    'rate_per_km' => data_get($itemMeta, 'ratePerKm', ''),
                    'destination' => data_get($itemMeta, 'destination', ''),
                    'trip_date_from' => data_get($itemMeta, 'tripDateFrom', ''),
                    'trip_date_to' => data_get($itemMeta, 'tripDateTo', ''),
                    'billed_period' => data_get($itemMeta, 'billedPeriod', ''),
                    'claimant' => data_get($itemMeta, 'claimant', ''),
                    'itemMeta' => $itemMeta,
                    'attachment' => $item->relationLoaded('attachment') && $item->attachment ? [
                        'id' => $item->attachment->id,
                        'original_name' => $item->attachment->original_name,
                        'mime_type' => $item->attachment->mime_type,
                        'size' => $item->attachment->size,
                    ] : null,
                ];
            })->values()->all()
            : [];

        $attachment = $row->relationLoaded('attachment') ? $row->attachment : null;

        return [
            'id' => $row->id,
            'display_id' => $row->display_id,
            'submission_key' => $row->submission_key,
            'user_id' => $row->user_id,
            'claim_type' => $row->claim_type,
            'category' => $row->category,
            'period' => $row->period,
            'period_value' => $row->period_value,
            'amount' => (float) $row->amount,
            'approved_overtime_payout' => (float) $row->approved_overtime_payout,
            'adjustments_total' => $isSalaryClaim
                ? (float) ($row->adjustments_total ?? 0)
                : ($row->adjustments_total !== null ? (float) $row->adjustments_total : null),
            'projected_net_payout' => $isSalaryClaim
                ? (float) ($row->projected_net_payout ?? 0)
                : ($row->projected_net_payout !== null ? (float) $row->projected_net_payout : null),
            'status' => $row->status,
            'submitted_at' => optional($row->submitted_at)->toIso8601String(),
            'submitted_by' => $row->submitted_by,
            'submitted_by_name' => $row->submitted_by_name,
            'updated_by' => $row->updated_by,
            'updated_by_name' => $row->updated_by_name,
            'workflow_stage' => $row->workflow_stage,
            'workflow_snapshot' => $row->workflow_snapshot ?? [],
            'next_action_role' => $row->next_action_role,
            'approval_history' => $row->approval_history ?? [],
            'payment_date' => optional($row->payment_date)->toDateString(),
            'payment_reference' => trim((string) ($row->payment_reference ?? '')),
            'payment_note' => trim((string) ($row->payment_note ?? '')),
            'paid_at' => optional($row->paid_at)->toIso8601String(),
            'paid_by_user_id' => $row->paid_by_user_id ? (int) $row->paid_by_user_id : null,
            'paid_by' => $row->relationLoaded('paidByUser') && $row->paidByUser
                ? trim((string) ($row->paidByUser->name ?? ''))
                : null,
            'payroll_snapshot' => $payrollSnapshot,
            'payroll_baseline_confirmed' => $payrollBaselineConfirmed,
            'overtime_rows' => $overtimeRows,
            'overtime_rate_snapshot' => $overtimeRateSnapshot,
            'action_permissions' => self::buildActionPermissions($row, $actor),
            'notes' => $row->notes,
            'attachment' => $attachment ? [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
            ] : null,
            'items' => $items,
            'created_at' => optional($row->created_at)->toIso8601String(),
            'updated_at' => optional($row->updated_at)->toIso8601String(),
        ];
    }

    private static function buildActionPermissions(PayrollClaim $row, mixed $actor): array
    {
        $canManageSalaryPay = self::actorCanManageSalaryPay($actor);
        $markPaidBlockedReason = '';
        $unmarkPaidBlockedReason = '';
        $canMarkPaid = false;
        $canUnmarkPaid = false;
        $status = trim((string) ($row->status ?? ''));
        $claimType = trim((string) ($row->claim_type ?? ''));

        if ($claimType !== 'salary') {
            $markPaidBlockedReason = 'Only salary claims can be marked as paid.';
            $unmarkPaidBlockedReason = 'Only salary claims can be unmarked as paid.';
        } elseif (! $canManageSalaryPay) {
            $markPaidBlockedReason = 'Missing required permission: staff.salary.pay.';
            $unmarkPaidBlockedReason = 'Missing required permission: staff.salary.pay.';
        } elseif ($status === 'Approved') {
            $canMarkPaid = true;
            $unmarkPaidBlockedReason = 'Only paid salary claims can be unmarked.';
        } elseif ($status === 'Paid') {
            $canUnmarkPaid = true;
            $markPaidBlockedReason = 'Only approved salary claims can be marked as paid.';
        } else {
            $markPaidBlockedReason = 'Only approved salary claims can be marked as paid.';
            $unmarkPaidBlockedReason = 'Only paid salary claims can be unmarked.';
        }

        return [
            'mark_paid' => [
                'enabled' => $canMarkPaid,
                'blockedReason' => $canMarkPaid ? '' : $markPaidBlockedReason,
            ],
            'unmark_paid' => [
                'enabled' => $canUnmarkPaid,
                'blockedReason' => $canUnmarkPaid ? '' : $unmarkPaidBlockedReason,
            ],
        ];
    }

    private static function actorCanManageSalaryPay(mixed $actor): bool
    {
        if (! is_object($actor)) {
            return false;
        }

        try {
            if (method_exists($actor, 'hasRole') && $actor->hasRole('System Administrator')) {
                return true;
            }
        } catch (\Throwable) {
            // Ignore role check failure and continue.
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
