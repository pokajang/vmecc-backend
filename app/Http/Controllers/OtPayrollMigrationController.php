<?php

namespace App\Http\Controllers;

use App\Models\OvertimeDraft;
use App\Models\OvertimeRecord;
use App\Models\PayrollClaim;
use App\Models\PayrollClaimDraft;
use App\Models\PayrollClaimItem;
use App\Models\Setting;
use App\Services\AssignmentAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OtPayrollMigrationController extends Controller
{
    public function __construct(private readonly AssignmentAuthorizationService $authorizationService)
    {
    }

    public function import(Request $request): JsonResponse
    {
        if (!config('features.ot_payroll_migration_enabled', true)) {
            return response()->json([
                'message' => 'OT/Payroll migration is disabled by feature flag.',
            ], 403);
        }

        $payload = $request->validate([
            'overtime' => ['nullable', 'array'],
            'overtime.records' => ['nullable', 'array'],
            'overtime.records.*' => ['nullable', 'array'],
            'overtime.draft' => ['nullable', 'array'],
            'payroll' => ['nullable', 'array'],
            'payroll.claims' => ['nullable', 'array'],
            'payroll.claims.*' => ['nullable', 'array'],
            'payroll.drafts' => ['nullable', 'array'],
            'payroll.drafts.*' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $report = [
            'overtime' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'overtimeDraft' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'payrollClaims' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'payrollDrafts' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
            'settings' => ['updated' => 0, 'skipped' => 0],
        ];

        DB::transaction(function () use ($payload, $user, &$report) {
            $this->importOvertimeRecords($user->id, $payload['overtime']['records'] ?? [], $report);
            $this->importOvertimeDraft($user->id, $payload['overtime']['draft'] ?? null, $report);
            $this->importPayrollClaims($user->id, $payload['payroll']['claims'] ?? [], $report);
            $this->importPayrollDrafts($user->id, $payload['payroll']['drafts'] ?? [], $report);

            if (!empty($payload['settings']) && is_array($payload['settings'])) {
                $this->importSettings($user, $payload['settings'], $report);
            }
        });

        return response()->json([
            'message' => 'OT/Payroll migration import completed.',
            'data' => $report,
        ]);
    }

    private function importOvertimeRecords(int $userId, array $rows, array &$report): void
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $report['overtime']['skipped']++;
                continue;
            }

            $displayId = trim((string) ($row['id'] ?? $row['display_id'] ?? ''));
            if ($displayId === '') {
                $report['overtime']['skipped']++;
                continue;
            }

            $updatePayload = [
                'overtime_type' => (string) ($row['overtimeType'] ?? $row['overtime_type'] ?? 'weekday'),
                'claim_date' => $row['claimDate'] ?? $row['claim_date'] ?? null,
                'start_time' => $row['startTime'] ?? $row['start_time'] ?? null,
                'end_time' => $row['endTime'] ?? $row['end_time'] ?? null,
                'is_overnight' => (bool) ($row['isOvernight'] ?? $row['is_overnight'] ?? false),
                'duration_minutes' => (int) ($row['durationMinutes'] ?? $row['duration_minutes'] ?? 0),
                'reason' => (string) ($row['reason'] ?? ''),
                'status' => (string) ($row['status'] ?? 'Pending'),
                'applied_at' => $row['appliedAt'] ?? $row['applied_at'] ?? null,
                'workflow_stage' => $row['workflowStage'] ?? $row['workflow_stage'] ?? null,
                'workflow_snapshot' => is_array($row['workflowSnapshot'] ?? null) ? $row['workflowSnapshot'] : null,
                'next_action_role' => $row['nextActionRole'] ?? $row['next_action_role'] ?? null,
                'applicant_roles' => is_array($row['applicantRoles'] ?? null) ? $row['applicantRoles'] : [],
                'approval_history' => is_array($row['approvalHistory'] ?? null) ? $row['approvalHistory'] : [],
                'submitted_by' => (string) ($row['submittedBy'] ?? $row['submitted_by'] ?? ''),
            ];

            $existing = OvertimeRecord::query()
                ->withTrashed()
                ->where('user_id', $userId)
                ->where('display_id', $displayId)
                ->first();
            if ($existing) {
                $existing->fill($updatePayload)->save();
                if ($existing->trashed()) $existing->restore();
                $report['overtime']['updated']++;
                continue;
            }

            OvertimeRecord::query()->create([
                'user_id' => $userId,
                'display_id' => $displayId,
                ...$updatePayload,
            ]);
            $report['overtime']['created']++;
        }
    }

    private function importOvertimeDraft(int $userId, mixed $draft, array &$report): void
    {
        if (!is_array($draft) || $draft === []) {
            $report['overtimeDraft']['skipped']++;
            return;
        }

        $existing = OvertimeDraft::query()->where('user_id', $userId)->first();
        if ($existing) {
            $existing->update([
                'payload' => $draft,
                'saved_at' => now(),
            ]);
            $report['overtimeDraft']['updated']++;
            return;
        }

        OvertimeDraft::query()->create([
            'user_id' => $userId,
            'payload' => $draft,
            'saved_at' => now(),
        ]);
        $report['overtimeDraft']['created']++;
    }

    private function importPayrollClaims(int $userId, array $rows, array &$report): void
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $report['payrollClaims']['skipped']++;
                continue;
            }
            $displayId = trim((string) ($row['id'] ?? $row['display_id'] ?? ''));
            if ($displayId === '') {
                $report['payrollClaims']['skipped']++;
                continue;
            }

            $claimType = strtolower(trim((string) ($row['type'] ?? $row['claim_type'] ?? 'expense')));
            if ($claimType === 'other') $claimType = 'exceptional';
            if (!in_array($claimType, ['expense', 'salary', 'exceptional'], true)) $claimType = 'expense';

            $updatePayload = [
                'claim_type' => $claimType,
                'category' => (string) ($row['category'] ?? ''),
                'period' => (string) ($row['period'] ?? ''),
                'period_value' => (string) ($row['periodValue'] ?? $row['period_value'] ?? ''),
                'amount' => (float) ($row['amount'] ?? 0),
                'approved_overtime_payout' => (float) ($row['approvedOvertimePayout'] ?? $row['approved_overtime_payout'] ?? 0),
                'status' => (string) ($row['status'] ?? 'Pending'),
                'submitted_at' => $row['submittedAt'] ?? $row['submitted_at'] ?? null,
                'submitted_by' => (string) ($row['submittedBy'] ?? $row['submitted_by'] ?? ''),
                'submitted_by_name' => (string) ($row['submittedByName'] ?? $row['submitted_by_name'] ?? ''),
                'updated_by' => (string) ($row['updatedBy'] ?? $row['updated_by'] ?? ''),
                'updated_by_name' => (string) ($row['updatedByName'] ?? $row['updated_by_name'] ?? ''),
                'workflow_stage' => (string) ($row['workflowStage'] ?? $row['workflow_stage'] ?? ''),
                'workflow_snapshot' => is_array($row['workflowSnapshot'] ?? null) ? $row['workflowSnapshot'] : null,
                'next_action_role' => $row['nextActionRole'] ?? $row['next_action_role'] ?? null,
                'approval_history' => is_array($row['approvalHistory'] ?? null) ? $row['approvalHistory'] : [],
                'payroll_snapshot' => is_array($row['payrollSnapshot'] ?? null) ? $row['payrollSnapshot'] : null,
                'overtime_rows' => is_array($row['overtimeRows'] ?? null) ? $row['overtimeRows'] : [],
                'overtime_rate_snapshot' => is_array($row['overtimeRateSnapshot'] ?? null) ? $row['overtimeRateSnapshot'] : null,
                'notes' => (string) ($row['notes'] ?? ''),
                'attachment_id' => $row['attachmentId'] ?? $row['attachment_id'] ?? null,
            ];

            $items = array_values(array_filter($row['items'] ?? [], fn ($item) => is_array($item)));

            $existing = PayrollClaim::query()
                ->withTrashed()
                ->where('user_id', $userId)
                ->where('display_id', $displayId)
                ->first();

            if ($existing) {
                $existing->fill($updatePayload)->save();
                if ($existing->trashed()) $existing->restore();
                PayrollClaimItem::query()->where('payroll_claim_id', $existing->id)->delete();
                foreach ($items as $item) {
                    PayrollClaimItem::query()->create([
                        'payroll_claim_id' => $existing->id,
                        'item_type' => (string) ($item['claimType'] ?? $item['item_type'] ?? ''),
                        'title' => (string) ($item['title'] ?? ''),
                        'claim_date' => $item['claimDate'] ?? $item['claim_date'] ?? null,
                        'amount' => (float) ($item['amount'] ?? 0),
                        'notes' => (string) ($item['lineNotes'] ?? $item['notes'] ?? ''),
                        'attachment_id' => $item['attachmentId'] ?? $item['attachment_id'] ?? null,
                        'item_meta' => $item,
                    ]);
                }
                $report['payrollClaims']['updated']++;
                continue;
            }

            $created = PayrollClaim::query()->create([
                'user_id' => $userId,
                'display_id' => $displayId,
                ...$updatePayload,
            ]);
            foreach ($items as $item) {
                PayrollClaimItem::query()->create([
                    'payroll_claim_id' => $created->id,
                    'item_type' => (string) ($item['claimType'] ?? $item['item_type'] ?? ''),
                    'title' => (string) ($item['title'] ?? ''),
                    'claim_date' => $item['claimDate'] ?? $item['claim_date'] ?? null,
                    'amount' => (float) ($item['amount'] ?? 0),
                    'notes' => (string) ($item['lineNotes'] ?? $item['notes'] ?? ''),
                    'attachment_id' => $item['attachmentId'] ?? $item['attachment_id'] ?? null,
                    'item_meta' => $item,
                ]);
            }
            $report['payrollClaims']['created']++;
        }
    }

    private function importPayrollDrafts(int $userId, array $rows, array &$report): void
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $report['payrollDrafts']['skipped']++;
                continue;
            }

            $claimType = strtolower(trim((string) ($row['claimType'] ?? $row['claim_type'] ?? 'expense')));
            if ($claimType === 'other') $claimType = 'exceptional';
            if (!in_array($claimType, ['expense', 'salary', 'exceptional'], true)) {
                $claimType = 'expense';
            }
            $draftId = trim((string) ($row['id'] ?? $row['draft_id'] ?? ''));
            if ($draftId === '') {
                $report['payrollDrafts']['skipped']++;
                continue;
            }

            $existing = PayrollClaimDraft::query()
                ->where('user_id', $userId)
                ->where('claim_type', $claimType)
                ->where('draft_id', $draftId)
                ->first();
            if ($existing) {
                $existing->update([
                    'payload' => $row,
                    'saved_at' => now(),
                ]);
                $report['payrollDrafts']['updated']++;
                continue;
            }

            PayrollClaimDraft::query()->create([
                'user_id' => $userId,
                'claim_type' => $claimType,
                'draft_id' => $draftId,
                'payload' => $row,
                'saved_at' => now(),
            ]);
            $report['payrollDrafts']['created']++;
        }
    }

    private function importSettings($user, array $settings, array &$report): void
    {
        $canManageSettings = $this->authorizationService->hasPermission($user, 'settings.manage');
        if (!$canManageSettings) {
            $report['settings']['skipped']++;
            return;
        }

        $map = [
            'overtimeApprovalRules' => 'overtime_approval_rules',
            'overtimeRateSettings' => 'overtime_rate_settings',
            'salaryWorkflowRules' => 'salary_workflow_rules',
        ];

        foreach ($map as $inputKey => $settingKey) {
            $value = $settings[$inputKey] ?? null;
            if (!is_array($value)) {
                $report['settings']['skipped']++;
                continue;
            }
            Setting::query()->updateOrCreate(
                ['key' => $settingKey],
                ['value' => $value],
            );
            $report['settings']['updated']++;
        }
    }
}
