<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\LeaveAssignment;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Str;

class LeaveWorkflowService
{
    private const APPROVAL_RULES_KEY = 'leave_approval_rules';

    private const LEAVE_TYPE_CODES = [
        'Annual Leave'       => 'AL',
        'Medical Leave'      => 'ML',
        'Emergency Leave'    => 'EL',
        'Compassionate Leave'=> 'CL',
        'Unpaid Leave'       => 'UL',
        'Other Leave'        => 'OL',
    ];

    private const WORKFLOW_STAGES = ['review', 'recommend', 'approve', 'done'];

    // ── Approval Rules ────────────────────────────────────────────────────────

    public function loadApprovalRules(): array
    {
        $setting = Setting::where('key', self::APPROVAL_RULES_KEY)->first();
        $value = $setting?->value ?? [];

        return $this->normalizeApprovalRules($value);
    }

    public function saveApprovalRules(array $rules): void
    {
        Setting::updateOrCreate(
            ['key' => self::APPROVAL_RULES_KEY],
            ['value' => $this->normalizeApprovalRules($rules)]
        );
    }

    public function normalizeApprovalRules(mixed $value): array
    {
        $policy = is_array($value) ? $value : [];

        $rules = [];
        foreach ((array) ($policy['rules'] ?? []) as $rule) {
            if (!is_array($rule)) continue;
            $rules[] = [
                'id'            => $rule['id'] ?? (string) Str::uuid(),
                'applicantRole' => trim((string) ($rule['applicantRole'] ?? '')),
                'reviewRole'    => trim((string) ($rule['reviewRole'] ?? '')),
                'recommendRole' => trim((string) ($rule['recommendRole'] ?? '')),
                'approveRole'   => trim((string) ($rule['approveRole'] ?? '')),
                'active'        => ($rule['active'] ?? true) !== false,
            ];
        }

        $fallback = $policy['fallback'] ?? [];
        $options  = $policy['options'] ?? [];

        return [
            'rules'    => $rules,
            'fallback' => [
                'reviewRole'    => trim((string) ($fallback['reviewRole'] ?? '')),
                'recommendRole' => trim((string) ($fallback['recommendRole'] ?? '')),
                'approveRole'   => trim((string) ($fallback['approveRole'] ?? '')),
            ],
            'options' => [
                'requireRecommendation'    => ($options['requireRecommendation'] ?? true) !== false,
                'enforceDistinctApprovers' => ($options['enforceDistinctApprovers'] ?? false) === true,
            ],
        ];
    }

    /**
     * Find the first matching rule for any of the given applicant roles.
     * Falls back to policy fallback or null.
     */
    public function resolveApprovalRule(array $policy, array $applicantRoles): ?array
    {
        $roles = array_values(array_filter(
            array_map(fn ($r) => strtolower(trim((string) $r)), $applicantRoles)
        ));

        $activeRules = array_filter(
            $policy['rules'] ?? [],
            fn ($r) => ($r['active'] ?? true) !== false
        );

        foreach ($roles as $role) {
            foreach ($activeRules as $rule) {
                if (strtolower(trim((string) ($rule['applicantRole'] ?? ''))) === $role) {
                    return $rule;
                }
            }
        }

        return $policy['fallback'] ?? null;
    }

    // ── Workflow Metadata ─────────────────────────────────────────────────────

    /**
     * Build the workflow snapshot at time of leave submission.
     * Mirrors frontend resolveWorkflowMetadataForSubmit().
     */
    public function buildWorkflowForSubmission(array $applicantRoles): array
    {
        $policy      = $this->loadApprovalRules();
        $resolvedRule = $this->resolveApprovalRule($policy, $applicantRoles);

        $requireRecommendation = ($policy['options']['requireRecommendation'] ?? true) !== false;
        $reviewRole    = trim((string) ($resolvedRule['reviewRole'] ?? ''));
        $recommendRole = trim((string) ($resolvedRule['recommendRole'] ?? ''));
        $approveRole   = trim((string) ($resolvedRule['approveRole'] ?? ''));

        $normalizedRoles = array_values(array_unique(array_filter(
            array_map(fn ($r) => trim((string) $r), $applicantRoles)
        )));

        return [
            'workflowSnapshot' => [
                'reviewRole'            => $reviewRole,
                'recommendRole'         => $recommendRole,
                'approveRole'           => $approveRole,
                'requireRecommendation' => $requireRecommendation,
            ],
            'workflowStage'  => 'review',
            'nextActionRole' => $reviewRole ?: null,
            'applicantRoles' => $normalizedRoles,
        ];
    }

    // ── Workflow Advancement ──────────────────────────────────────────────────

    /**
     * Advance the workflow after a staff action.
     * Returns the fields to update on the Leave model.
     */
    public function advanceWorkflow(Leave $leave, string $action, int $actorUserId, string $actorName, ?string $remarks = ''): array
    {
        $snapshot              = $leave->workflow_snapshot ?? [];
        $requireRecommendation = ($snapshot['requireRecommendation'] ?? true) !== false;
        $approveRole           = $snapshot['approveRole'] ?? null;
        $recommendRole         = $snapshot['recommendRole'] ?? null;

        $historyEntry = [
            'id'        => (string) Str::uuid(),
            'at'        => now()->toIso8601String(),
            'action'    => $this->actionLabel($action),
            'by'        => $actorName,
            'byUserId'  => (string) $actorUserId,
            'remarks'   => $remarks ?? '',
        ];

        $history = array_merge($leave->approval_history ?? [], [$historyEntry]);

        if ($action === 'reject') {
            return [
                'status'            => 'Rejected',
                'workflow_stage'    => 'done',
                'next_action_role'  => null,
                'approval_history'  => $history,
            ];
        }

        if ($action === 'cancel') {
            return [
                'status'            => 'Cancelled',
                'workflow_stage'    => 'done',
                'next_action_role'  => null,
                'approval_history'  => $history,
            ];
        }

        $currentStage = $leave->workflow_stage;

        if ($action === 'review') {
            if ($requireRecommendation) {
                return [
                    'workflow_stage'  => 'recommend',
                    'next_action_role'=> $recommendRole ?: null,
                    'approval_history'=> $history,
                ];
            }
            return [
                'workflow_stage'  => 'approve',
                'next_action_role'=> $approveRole ?: null,
                'approval_history'=> $history,
            ];
        }

        if ($action === 'recommend') {
            return [
                'workflow_stage'  => 'approve',
                'next_action_role'=> $approveRole ?: null,
                'approval_history'=> $history,
            ];
        }

        if ($action === 'approve') {
            return [
                'status'          => 'Approved',
                'workflow_stage'  => 'done',
                'next_action_role'=> null,
                'approval_history'=> $history,
            ];
        }

        return ['approval_history' => $history];
    }

    // ── Balance Management ────────────────────────────────────────────────────

    /**
     * Called when a leave is submitted (status: Draft → Pending).
     * Increments pending days on the assignment.
     */
    public function onLeaveSubmitted(Leave $leave): void
    {
        $this->adjustBalance($leave->user_id, $leave->leave_type, $leave->start_date->year, [
            'pending' => $leave->days,
        ]);
    }

    /**
     * Called when a leave is approved. Moves days from pending → used.
     */
    public function onLeaveApproved(Leave $leave): void
    {
        $this->adjustBalance($leave->user_id, $leave->leave_type, $leave->start_date->year, [
            'pending' => -$leave->days,
            'used'    => $leave->days,
        ]);
    }

    /**
     * Called when a leave is rejected or cancelled.
     * Decrements pending days.
     */
    public function onLeaveDeclined(Leave $leave): void
    {
        $this->adjustBalance($leave->user_id, $leave->leave_type, $leave->start_date->year, [
            'pending' => -$leave->days,
        ]);
    }

    /**
     * Called when an approved leave is cancelled.
     * Removes from used.
     */
    public function onApprovedLeaveCancelled(Leave $leave): void
    {
        $this->adjustBalance($leave->user_id, $leave->leave_type, $leave->start_date->year, [
            'used' => -$leave->days,
        ]);
    }

    // ── Display ID Generation ─────────────────────────────────────────────────

    /**
     * Generate the next display ID for a user and leave type.
     * Format: LV-{code}-{year}-{seq:03}
     */
    public function generateDisplayId(int $userId, string $leaveType, int $year): string
    {
        $code = self::LEAVE_TYPE_CODES[$leaveType] ?? 'OL';
        $prefix = "LV-{$code}-{$year}-";

        $last = Leave::withTrashed()
            ->where('user_id', $userId)
            ->where('display_id', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('display_id');

        $seq = 1;
        if ($last) {
            $parts = explode('-', $last);
            $seq = ((int) end($parts)) + 1;
        }

        return $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function adjustBalance(int $userId, string $leaveType, int $year, array $deltas): void
    {
        $assignment = LeaveAssignment::firstOrCreate(
            ['user_id' => $userId, 'year' => $year, 'leave_type' => $leaveType],
            ['entitlement' => 0, 'used' => 0, 'pending' => 0]
        );

        if (isset($deltas['pending'])) {
            $assignment->pending = max(0, (float) $assignment->pending + (float) $deltas['pending']);
        }
        if (isset($deltas['used'])) {
            $assignment->used = max(0, (float) $assignment->used + (float) $deltas['used']);
        }

        $assignment->save();
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'submit'    => 'Submitted',
            'review'    => 'Reviewed',
            'recommend' => 'Recommended',
            'approve'   => 'Approved',
            'reject'    => 'Rejected',
            'cancel'    => 'Cancelled',
            'edit'      => 'Edited',
            default     => ucfirst($action),
        };
    }
}
