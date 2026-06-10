<?php

namespace App\Services;

use App\Models\OvertimeRecord;
use App\Models\Setting;
use Illuminate\Support\Str;

class PayrollClaimWorkflowService
{
    private const SALARY_WORKFLOW_RULES_KEY = 'salary_workflow_rules';

    private const DEFAULT_WORKFLOW = [
        'rules' => [],
        'fallback' => [
            'checkRole' => 'Admin',
            'reviewRole' => 'Finance',
            'approveRole' => 'Contract Manager',
        ],
    ];

    public function __construct(private readonly OvertimeWorkflowService $overtimeWorkflowService) {}

    public function loadWorkflowRules(): array
    {
        $setting = Setting::query()->where('key', self::SALARY_WORKFLOW_RULES_KEY)->first();

        return $this->normalizeWorkflowRules($setting?->value ?? []);
    }

    public function saveWorkflowRules(array $rules): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::SALARY_WORKFLOW_RULES_KEY],
            ['value' => $this->normalizeWorkflowRules($rules)],
        );
    }

    public function normalizeWorkflowRules(mixed $value): array
    {
        $source = is_array($value) ? $value : [];
        $fallback = is_array($source['fallback'] ?? null)
            ? $source['fallback']
            : self::DEFAULT_WORKFLOW['fallback'];

        return [
            'rules' => [],
            'fallback' => [
                'checkRole' => trim((string) ($fallback['checkRole'] ?? self::DEFAULT_WORKFLOW['fallback']['checkRole'])),
                'reviewRole' => trim((string) ($fallback['reviewRole'] ?? self::DEFAULT_WORKFLOW['fallback']['reviewRole'])),
                'approveRole' => trim((string) ($fallback['approveRole'] ?? self::DEFAULT_WORKFLOW['fallback']['approveRole'])),
            ],
        ];
    }

    public function resolveWorkflowRule(array $policy): array
    {
        $fallback = is_array($policy['fallback'] ?? null)
            ? $policy['fallback']
            : self::DEFAULT_WORKFLOW['fallback'];

        return [
            'id' => 'salary-rule-fallback',
            'applicantRole' => 'Global',
            'checkRole' => trim((string) ($fallback['checkRole'] ?? '')),
            'reviewRole' => trim((string) ($fallback['reviewRole'] ?? '')),
            'approveRole' => trim((string) ($fallback['approveRole'] ?? '')),
            'active' => true,
        ];
    }

    public function buildWorkflowForSubmission(): array
    {
        $rule = $this->resolveWorkflowRule($this->loadWorkflowRules());

        return [
            'workflowSnapshot' => [
                'checkRole' => $rule['checkRole'],
                'reviewRole' => $rule['reviewRole'],
                'approveRole' => $rule['approveRole'],
            ],
            'workflowStage' => 'check',
            'nextActionRole' => $rule['checkRole'] ?: null,
        ];
    }

    public function advanceWorkflow(array $snapshot, array $history, string $action, int $actorUserId, string $actorName, ?string $remarks = null): array
    {
        $entry = [
            'id' => (string) Str::uuid(),
            'at' => now()->toIso8601String(),
            'action' => $this->actionLabel($action),
            'by' => $actorName,
            'byUserId' => (string) $actorUserId,
            'remarks' => (string) ($remarks ?? ''),
        ];

        $nextHistory = collect(is_array($history) ? $history : [])->push($entry)->take(-20)->values()->all();

        if ($action === 'reject') {
            return [
                'status' => 'Rejected',
                'workflowStage' => 'done',
                'nextActionRole' => null,
                'approvalHistory' => $nextHistory,
            ];
        }

        if ($action === 'cancel') {
            return [
                'status' => 'Cancelled',
                'workflowStage' => 'done',
                'nextActionRole' => null,
                'approvalHistory' => $nextHistory,
            ];
        }

        if ($action === 'check') {
            return [
                'workflowStage' => 'review',
                'nextActionRole' => trim((string) ($snapshot['reviewRole'] ?? '')) ?: null,
                'approvalHistory' => $nextHistory,
            ];
        }

        if ($action === 'review') {
            return [
                'workflowStage' => 'approve',
                'nextActionRole' => trim((string) ($snapshot['approveRole'] ?? '')) ?: null,
                'approvalHistory' => $nextHistory,
            ];
        }

        if ($action === 'approve') {
            return [
                'status' => 'Approved',
                'workflowStage' => 'done',
                'nextActionRole' => null,
                'approvalHistory' => $nextHistory,
            ];
        }

        return [
            'approvalHistory' => $nextHistory,
        ];
    }

    public function calculateSalaryOvertimeSnapshot(
        int $userId,
        string $periodValue,
        float $assignedBasicSalary,
        array $applicantRoles = [],
    ): array {
        $rateSettings = $this->overtimeWorkflowService->loadRateSettings();
        [$yearRaw, $monthRaw] = explode('-', $periodValue);
        $year = (int) $yearRaw;
        $month = (int) $monthRaw;
        $rows = OvertimeRecord::query()
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->where('status', 'Approved')
            ->whereNotNull('claim_date')
            ->whereYear('claim_date', $year)
            ->whereMonth('claim_date', $month)
            ->orderByDesc('claim_date')
            ->orderByDesc('id')
            ->get();

        $baseCalc = is_array($rateSettings['baseHourCalculation'] ?? null)
            ? $rateSettings['baseHourCalculation']
            : [];
        $mode = in_array(($baseCalc['mode'] ?? 'auto_statutory'), ['auto_statutory', 'month_days_division'], true)
            ? (string) ($baseCalc['mode'] ?? 'auto_statutory')
            : 'auto_statutory';
        $normalHoursStrategy = in_array(
            ($baseCalc['normalHoursStrategy'] ?? 'statutory_8h'),
            ['statutory_8h', 'global', 'role_based'],
            true,
        ) ? (string) ($baseCalc['normalHoursStrategy'] ?? 'statutory_8h') : 'statutory_8h';
        $monthlyDivisor = $this->parsePositive($baseCalc['monthlyDivisor'] ?? '26');
        $globalNormalHoursPerDay = $this->parsePositive($baseCalc['globalNormalHoursPerDay'] ?? '8');
        $defaultRoleHoursPerDay = $this->parsePositive($baseCalc['defaultRoleHoursPerDay'] ?? '8');
        $roleNormalHoursPerDay = $this->normalizeRoleHoursMap($baseCalc['roleNormalHoursPerDay'] ?? []);
        $defaultApplicantRoles = $this->normalizeRoles($applicantRoles);
        $previewNormalHoursPerDay = $this->resolveNormalHoursPerDay(
            normalHoursStrategy: $normalHoursStrategy,
            globalNormalHoursPerDay: $globalNormalHoursPerDay,
            defaultRoleHoursPerDay: $defaultRoleHoursPerDay,
            roleNormalHoursPerDay: $roleNormalHoursPerDay,
            applicantRoles: $defaultApplicantRoles,
        );
        $autoHourlyBase = ($monthlyDivisor > 0 && $previewNormalHoursPerDay > 0)
            ? round($assignedBasicSalary / $monthlyDivisor / $previewNormalHoursPerDay, 2)
            : null;

        $parseMultiplier = fn ($value, float $fallback) => ($this->parsePositive($value) > 0 ? $this->parsePositive($value) : $fallback);
        $multipliers = [
            'weekday' => $parseMultiplier($rateSettings['weekdayMultiplier'] ?? '1.5', 1.5),
            'weekend' => $parseMultiplier($rateSettings['weekendMultiplier'] ?? '2.0', 2.0),
            'publicHoliday' => $parseMultiplier($rateSettings['publicHolidayMultiplier'] ?? '3.0', 3.0),
        ];

        $normalizedRows = $rows->map(function (OvertimeRecord $row) use (
            $mode,
            $autoHourlyBase,
            $multipliers,
            $monthlyDivisor,
            $normalHoursStrategy,
            $globalNormalHoursPerDay,
            $defaultRoleHoursPerDay,
            $roleNormalHoursPerDay,
            $defaultApplicantRoles,
            $assignedBasicSalary,
        ) {
            $type = in_array($row->overtime_type, ['weekday', 'weekend', 'publicHoliday'], true)
                ? $row->overtime_type
                : 'weekday';
            $hours = round(((int) $row->duration_minutes) / 60, 2);
            $multiplier = $multipliers[$type] ?? $multipliers['weekday'];
            $rowApplicantRoles = $this->normalizeRoles(
                (is_array($row->applicant_roles) && count($row->applicant_roles) > 0)
                    ? $row->applicant_roles
                    : $defaultApplicantRoles,
            );
            $normalHoursPerDay = $this->resolveNormalHoursPerDay(
                normalHoursStrategy: $normalHoursStrategy,
                globalNormalHoursPerDay: $globalNormalHoursPerDay,
                defaultRoleHoursPerDay: $defaultRoleHoursPerDay,
                roleNormalHoursPerDay: $roleNormalHoursPerDay,
                applicantRoles: $rowApplicantRoles,
            );

            $monthDaysDivisor = null;
            if ($mode === 'month_days_division') {
                $claimDate = $row->claim_date;
                if ($claimDate !== null) {
                    $monthDaysDivisor = (int) $claimDate->daysInMonth;
                }
            }
            $monthDaysHourlyBase = ($mode === 'month_days_division' && $monthDaysDivisor !== null && $monthDaysDivisor > 0 && $normalHoursPerDay > 0)
                ? round($assignedBasicSalary / $monthDaysDivisor / $normalHoursPerDay, 2)
                : null;
            $autoHourlyBaseForRow = $mode === 'month_days_division' ? $monthDaysHourlyBase : $autoHourlyBase;

            $hourlyBaseRateUsed = $autoHourlyBaseForRow;
            $hourlyBaseSource = $autoHourlyBaseForRow !== null
                ? ($mode === 'month_days_division' ? 'month_days_division' : 'auto_statutory')
                : 'missing';

            $payout = ($hourlyBaseRateUsed !== null)
                ? round($hours * $hourlyBaseRateUsed * $multiplier, 2)
                : 0.0;

            return [
                'overtimeId' => $row->display_id,
                'overtimeRecordId' => $row->id,
                'overtimeType' => $type,
                'claimDate' => optional($row->claim_date)->toDateString(),
                'durationMinutes' => (int) $row->duration_minutes,
                'hours' => $hours,
                'status' => $row->status,
                'isApproved' => strcasecmp((string) $row->status, 'Approved') === 0,
                'applicantRoles' => $rowApplicantRoles,
                'multiplierUsed' => $multiplier,
                'hourlyBaseRateUsed' => $hourlyBaseRateUsed,
                'hourlyBaseSource' => $hourlyBaseSource,
                'monthlyDivisorUsed' => $mode === 'month_days_division' ? $monthDaysDivisor : $monthlyDivisor,
                'globalNormalHoursPerDayUsed' => $normalHoursPerDay,
                'payoutUsed' => $payout,
            ];
        })->values();

        $allHours = round((float) $normalizedRows->sum('hours'), 2);
        $approvedRows = $normalizedRows->filter(fn ($row) => ($row['isApproved'] ?? false) === true);
        $approvedHours = round((float) $approvedRows->sum('hours'), 2);
        $approvedPayout = round((float) $approvedRows->sum('payoutUsed'), 2);

        return [
            'rows' => $normalizedRows->all(),
            'totals' => [
                'allHours' => $allHours,
                'approvedHours' => $approvedHours,
                'approvedPayout' => $approvedPayout,
                'approvedCount' => $approvedRows->count(),
            ],
            'rateSnapshot' => [
                'hourlyBaseMode' => $mode,
                'hourlyBaseRateUsed' => $mode === 'month_days_division' ? null : $autoHourlyBase,
                'monthlyDivisorUsed' => $mode === 'month_days_division' ? 'calendar_days_by_overtime_month' : $monthlyDivisor,
                'globalNormalHoursPerDayUsed' => $previewNormalHoursPerDay,
                'normalHoursStrategyUsed' => $normalHoursStrategy,
                'weekdayMultiplier' => $multipliers['weekday'],
                'weekendMultiplier' => $multipliers['weekend'],
                'publicHolidayMultiplier' => $multipliers['publicHoliday'],
            ],
        ];
    }

    public function generateDisplayId(int $userId, int $year): string
    {
        $prefix = "CLM-{$year}-";
        $last = \App\Models\PayrollClaim::withTrashed()
            ->where('user_id', $userId)
            ->where('display_id', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('display_id');

        $seq = 1;
        if ($last) {
            $seq = ((int) substr((string) $last, strlen($prefix))) + 1;
        }

        return $prefix.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    private function parsePositive(mixed $value): float
    {
        $parsed = is_numeric($value) ? (float) $value : 0.0;

        return $parsed > 0 ? $parsed : 0.0;
    }

    private function normalizeRoles(mixed $roles): array
    {
        if (! is_array($roles)) {
            return [];
        }

        return collect($roles)
            ->map(fn ($role) => trim((string) $role))
            ->filter(fn ($role) => $role !== '')
            ->values()
            ->all();
    }

    private function normalizeRoleHoursMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->mapWithKeys(function ($hours, $role) {
                $normalizedRole = strtolower(trim((string) $role));
                if ($normalizedRole === '') {
                    return [];
                }

                $parsedHours = $this->parsePositive($hours);
                if ($parsedHours <= 0) {
                    return [];
                }

                return [$normalizedRole => $parsedHours];
            })
            ->all();
    }

    private function resolveNormalHoursPerDay(
        string $normalHoursStrategy,
        float $globalNormalHoursPerDay,
        float $defaultRoleHoursPerDay,
        array $roleNormalHoursPerDay,
        array $applicantRoles,
    ): float {
        if ($normalHoursStrategy === 'statutory_8h') {
            return 8.0;
        }

        if ($normalHoursStrategy === 'global') {
            return $globalNormalHoursPerDay > 0 ? $globalNormalHoursPerDay : 8.0;
        }

        foreach ($this->normalizeRoles($applicantRoles) as $role) {
            $matched = $roleNormalHoursPerDay[strtolower($role)] ?? 0;
            if ($matched > 0) {
                return (float) $matched;
            }
        }

        if ($defaultRoleHoursPerDay > 0) {
            return $defaultRoleHoursPerDay;
        }

        return 8.0;
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'submit' => 'Submitted',
            'edit' => 'Edited',
            'check' => 'Checked',
            'review' => 'Reviewed',
            'approve' => 'Approved',
            'reject' => 'Rejected',
            'cancel' => 'Cancelled',
            default => ucfirst($action),
        };
    }
}
