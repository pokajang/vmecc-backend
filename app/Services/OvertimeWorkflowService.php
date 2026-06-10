<?php

namespace App\Services;

use App\Models\OvertimeRecord;
use App\Models\Setting;
use Illuminate\Support\Str;

class OvertimeWorkflowService
{
    private const APPROVAL_RULES_KEY = 'overtime_approval_rules';
    private const RATE_SETTINGS_KEY = 'overtime_rate_settings';

    private const DEFAULT_POLICY = [
        'workflow' => [
            'rules' => [],
            'fallback' => [
                'reviewRole' => 'Contract Manager',
                'recommendRole' => 'Human Resource',
                'approveRole' => 'Client Contract Manager',
            ],
            'options' => [
                'requireRecommendation' => true,
                'enforceDistinctApprovers' => false,
            ],
        ],
        'typeVisibility' => [
            'weekday' => true,
            'weekend' => true,
            'publicHoliday' => true,
        ],
    ];

    private const DEFAULT_RATE_SETTINGS = [
        'otApplicability' => [
            'roles' => ['Tactical Response Team'],
        ],
        'weekdayMultiplier' => '1.5',
        'weekendMultiplier' => '2.0',
        'publicHolidayMultiplier' => '3.0',
        'baseHourCalculation' => [
            'mode' => 'auto_statutory',
            'monthlyDivisor' => '26',
            'globalNormalHoursPerDay' => '8',
            'normalHoursStrategy' => 'statutory_8h',
            'defaultRoleHoursPerDay' => '8',
            'roleNormalHoursPerDay' => [],
        ],
    ];

    public function loadApprovalRules(): array
    {
        $setting = Setting::query()->where('key', self::APPROVAL_RULES_KEY)->first();
        return $this->normalizeApprovalRules($setting?->value ?? []);
    }

    public function saveApprovalRules(array $rules): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::APPROVAL_RULES_KEY],
            ['value' => $this->normalizeApprovalRules($rules)],
        );
    }

    public function loadRateSettings(): array
    {
        $setting = Setting::query()->where('key', self::RATE_SETTINGS_KEY)->first();
        return $this->normalizeRateSettings($setting?->value ?? []);
    }

    public function saveRateSettings(array $value): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::RATE_SETTINGS_KEY],
            ['value' => $this->normalizeRateSettings($value)],
        );
    }

    public function normalizeApprovalRules(mixed $value): array
    {
        $source = is_array($value) ? $value : [];
        $workflow = is_array($source['workflow'] ?? null) ? $source['workflow'] : $source;

        $rules = collect(is_array($workflow['rules'] ?? null) ? $workflow['rules'] : [])
            ->filter(fn ($row) => is_array($row))
            ->map(function (array $row, int $index) {
                return [
                    'id' => trim((string) ($row['id'] ?? '')) ?: "ot-rule-" . ($index + 1),
                    'applicantRole' => trim((string) ($row['applicantRole'] ?? '')),
                    'reviewRole' => trim((string) ($row['reviewRole'] ?? '')),
                    'recommendRole' => trim((string) ($row['recommendRole'] ?? '')),
                    'approveRole' => trim((string) ($row['approveRole'] ?? '')),
                    'active' => ($row['active'] ?? true) !== false,
                ];
            })
            ->filter(fn (array $row) => $row['applicantRole'] !== '')
            ->values()
            ->all();

        $fallback = is_array($workflow['fallback'] ?? null)
            ? $workflow['fallback']
            : (self::DEFAULT_POLICY['workflow']['fallback']);

        $options = is_array($workflow['options'] ?? null)
            ? $workflow['options']
            : (self::DEFAULT_POLICY['workflow']['options']);

        $typeVisibility = is_array($source['typeVisibility'] ?? null)
            ? $source['typeVisibility']
            : self::DEFAULT_POLICY['typeVisibility'];

        $normalizedVisibility = [
            'weekday' => ($typeVisibility['weekday'] ?? true) !== false,
            'weekend' => ($typeVisibility['weekend'] ?? true) !== false,
            'publicHoliday' => ($typeVisibility['publicHoliday'] ?? true) !== false,
        ];

        if (!collect($normalizedVisibility)->contains(true)) {
            $normalizedVisibility = self::DEFAULT_POLICY['typeVisibility'];
        }

        return [
            'workflow' => [
                'rules' => $rules,
                'fallback' => [
                    'reviewRole' => trim((string) ($fallback['reviewRole'] ?? self::DEFAULT_POLICY['workflow']['fallback']['reviewRole'])),
                    'recommendRole' => trim((string) ($fallback['recommendRole'] ?? self::DEFAULT_POLICY['workflow']['fallback']['recommendRole'])),
                    'approveRole' => trim((string) ($fallback['approveRole'] ?? self::DEFAULT_POLICY['workflow']['fallback']['approveRole'])),
                ],
                'options' => [
                    'requireRecommendation' => ($options['requireRecommendation'] ?? true) !== false,
                    'enforceDistinctApprovers' => ($options['enforceDistinctApprovers'] ?? false) === true,
                ],
            ],
            'typeVisibility' => $normalizedVisibility,
        ];
    }

    public function normalizeRateSettings(mixed $value): array
    {
        $source = is_array($value) ? $value : [];
        $base = is_array($source['baseHourCalculation'] ?? null) ? $source['baseHourCalculation'] : [];
        $otApplicability = is_array($source['otApplicability'] ?? null) ? $source['otApplicability'] : [];
        $roles = collect(is_array($otApplicability['roles'] ?? null) ? $otApplicability['roles'] : [])
            ->map(fn ($entry) => trim((string) $entry))
            ->filter()
            ->values()
            ->all();
        $legacyTeam = trim((string) ($otApplicability['team'] ?? ''));
        if ($legacyTeam !== '') {
            $roles[] = $legacyTeam;
        }
        $roles = collect($roles)->unique()->values()->all();
        if (count($roles) === 0) {
            $roles = self::DEFAULT_RATE_SETTINGS['otApplicability']['roles'];
        }

        return [
            'otApplicability' => [
                'roles' => $roles,
            ],
            'weekdayMultiplier' => trim((string) ($source['weekdayMultiplier'] ?? self::DEFAULT_RATE_SETTINGS['weekdayMultiplier'])),
            'weekendMultiplier' => trim((string) ($source['weekendMultiplier'] ?? self::DEFAULT_RATE_SETTINGS['weekendMultiplier'])),
            'publicHolidayMultiplier' => trim((string) ($source['publicHolidayMultiplier'] ?? self::DEFAULT_RATE_SETTINGS['publicHolidayMultiplier'])),
            'baseHourCalculation' => [
                'mode' => in_array(($base['mode'] ?? 'auto_statutory'), ['auto_statutory', 'month_days_division'], true)
                    ? (string) ($base['mode'] ?? 'auto_statutory')
                    : 'auto_statutory',
                'monthlyDivisor' => trim((string) ($base['monthlyDivisor'] ?? self::DEFAULT_RATE_SETTINGS['baseHourCalculation']['monthlyDivisor'])),
                'globalNormalHoursPerDay' => trim((string) ($base['globalNormalHoursPerDay'] ?? self::DEFAULT_RATE_SETTINGS['baseHourCalculation']['globalNormalHoursPerDay'])),
                'normalHoursStrategy' => in_array(
                    ($base['normalHoursStrategy'] ?? 'statutory_8h'),
                    ['statutory_8h', 'global', 'role_based'],
                    true,
                ) ? (string) ($base['normalHoursStrategy'] ?? 'statutory_8h') : 'statutory_8h',
                'defaultRoleHoursPerDay' => trim((string) ($base['defaultRoleHoursPerDay'] ?? self::DEFAULT_RATE_SETTINGS['baseHourCalculation']['defaultRoleHoursPerDay'])),
                'roleNormalHoursPerDay' => collect(
                    is_array($base['roleNormalHoursPerDay'] ?? null) ? $base['roleNormalHoursPerDay'] : [],
                )->mapWithKeys(function ($value, $role) {
                    $roleName = trim((string) $role);
                    if ($roleName === '') {
                        return [];
                    }
                    $hours = trim((string) $value);
                    if ($hours === '') {
                        return [];
                    }
                    return [$roleName => $hours];
                })->all(),
            ],
        ];
    }

    public function resolveApprovalRule(array $policy, array $applicantRoles): array
    {
        $workflow = $policy['workflow'] ?? [];
        $rules = collect(is_array($workflow['rules'] ?? null) ? $workflow['rules'] : [])
            ->filter(fn ($row) => is_array($row) && ($row['active'] ?? true) !== false)
            ->values();

        $normalizedApplicantRoles = collect($applicantRoles)
            ->map(fn ($role) => trim((string) $role))
            ->filter()
            ->sortByDesc(fn ($role) => RoleCatalog::ROLE_PRIORITY[$role] ?? 0)
            ->values();

        foreach ($normalizedApplicantRoles as $role) {
            $match = $rules->first(fn ($row) => strtolower(trim((string) ($row['applicantRole'] ?? ''))) === strtolower($role));
            if ($match) {
                return $match;
            }
        }

        $fallback = is_array($workflow['fallback'] ?? null)
            ? $workflow['fallback']
            : self::DEFAULT_POLICY['workflow']['fallback'];

        return [
            'id' => 'ot-rule-fallback',
            'applicantRole' => 'Fallback',
            'reviewRole' => trim((string) ($fallback['reviewRole'] ?? '')),
            'recommendRole' => trim((string) ($fallback['recommendRole'] ?? '')),
            'approveRole' => trim((string) ($fallback['approveRole'] ?? '')),
            'active' => true,
        ];
    }

    public function buildWorkflowForSubmission(array $applicantRoles): array
    {
        $policy = $this->loadApprovalRules();
        $rule = $this->resolveApprovalRule($policy, $applicantRoles);
        $options = is_array($policy['workflow']['options'] ?? null)
            ? $policy['workflow']['options']
            : self::DEFAULT_POLICY['workflow']['options'];

        $requireRecommendation = ($options['requireRecommendation'] ?? true) !== false;

        return [
            'workflowSnapshot' => [
                'reviewRole' => trim((string) ($rule['reviewRole'] ?? '')),
                'recommendRole' => trim((string) ($rule['recommendRole'] ?? '')),
                'approveRole' => trim((string) ($rule['approveRole'] ?? '')),
                'requireRecommendation' => $requireRecommendation,
            ],
            'workflowStage' => 'review',
            'nextActionRole' => trim((string) ($rule['reviewRole'] ?? '')) ?: null,
            'applicantRoles' => collect($applicantRoles)
                ->map(fn ($role) => trim((string) $role))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    public function advanceWorkflow(OvertimeRecord $record, string $action, int $actorUserId, string $actorName, ?string $remarks = null): array
    {
        $snapshot = is_array($record->workflow_snapshot) ? $record->workflow_snapshot : [];
        $requireRecommendation = ($snapshot['requireRecommendation'] ?? true) !== false;

        $entry = [
            'id' => (string) Str::uuid(),
            'at' => now()->toIso8601String(),
            'action' => $this->actionLabel($action),
            'by' => $actorName,
            'byUserId' => (string) $actorUserId,
            'remarks' => (string) ($remarks ?? ''),
        ];

        $history = collect(is_array($record->approval_history) ? $record->approval_history : [])
            ->push($entry)
            ->take(-20)
            ->values()
            ->all();

        if ($action === 'reject') {
            return [
                'status' => 'Rejected',
                'workflow_stage' => 'done',
                'next_action_role' => null,
                'approval_history' => $history,
            ];
        }

        if ($action === 'cancel') {
            return [
                'status' => 'Cancelled',
                'workflow_stage' => 'done',
                'next_action_role' => null,
                'approval_history' => $history,
            ];
        }

        if ($action === 'review') {
            if ($requireRecommendation) {
                return [
                    'workflow_stage' => 'recommend',
                    'next_action_role' => trim((string) ($snapshot['recommendRole'] ?? '')) ?: null,
                    'approval_history' => $history,
                ];
            }
            return [
                'workflow_stage' => 'approve',
                'next_action_role' => trim((string) ($snapshot['approveRole'] ?? '')) ?: null,
                'approval_history' => $history,
            ];
        }

        if ($action === 'recommend') {
            return [
                'workflow_stage' => 'approve',
                'next_action_role' => trim((string) ($snapshot['approveRole'] ?? '')) ?: null,
                'approval_history' => $history,
            ];
        }

        if ($action === 'approve') {
            return [
                'status' => 'Approved',
                'workflow_stage' => 'done',
                'next_action_role' => null,
                'approval_history' => $history,
            ];
        }

        return ['approval_history' => $history];
    }

    public function generateDisplayId(int $userId, int $year): string
    {
        $prefix = "OT-{$year}-";
        $last = OvertimeRecord::withTrashed()
            ->where('user_id', $userId)
            ->where('display_id', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('display_id');

        $seq = 1;
        if ($last) {
            $seq = ((int) substr((string) $last, strlen($prefix))) + 1;
        }

        return $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'submit' => 'Submitted',
            'review' => 'Reviewed',
            'recommend' => 'Recommended',
            'approve' => 'Approved',
            'reject' => 'Rejected',
            'cancel' => 'Cancelled',
            'edit' => 'Edited',
            default => ucfirst($action),
        };
    }
}
