<?php

namespace App\Http\Controllers;

use App\Models\CustomShift;
use App\Models\Setting;
use App\Services\InspectionWorkflowService;
use App\Services\ReportingWorkflowService;
use App\Services\LeaveWorkflowService;
use App\Services\OvertimeWorkflowService;
use App\Services\PayrollClaimWorkflowService;
use App\Services\AuditLogger;
use App\Services\SystemMaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function __construct(
        private readonly LeaveWorkflowService $leaveWorkflowService,
        private readonly OvertimeWorkflowService $overtimeWorkflowService,
        private readonly PayrollClaimWorkflowService $payrollClaimWorkflowService,
        private readonly InspectionWorkflowService $inspectionWorkflowService,
        private readonly ReportingWorkflowService $reportingWorkflowService,
        private readonly SystemMaintenanceService $systemMaintenanceService,
    ) {}

    private const SHIFT_WINDOWS_KEY = 'shift_windows';
    private const SALARY_STATUTORY_RATES_KEY = 'salary_statutory_rates';
    private const PAYROLL_COMPANY_PROFILE_KEY = 'payroll_company_profile';
    private const DEFAULT_WINDOWS = [
        'normal_start' => '08:00',
        'normal_end'   => '17:00',
        'day_start'    => '07:00',
        'day_end'      => '19:00',
        'night_start'  => '19:00',
        'night_end'    => '07:00',
    ];
    private const TIME_REGEX = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';
    private const DEFAULT_SALARY_STATUTORY_RATES = [
        'epf' => ['employeeRate' => 0.11, 'employerRate' => 0.13],
        'perkeso' => ['employeeRate' => 0.005, 'employerRate' => 0.005],
        'sip' => ['employeeRate' => 0.002, 'employerRate' => 0.002],
        'updatedAt' => null,
        'updatedBy' => '',
    ];
    private const DEFAULT_PAYROLL_COMPANY_PROFILE = [
        'legalName' => '',
        'registrationNumber' => '',
        'myTaxNumber' => '',
        'address' => '',
        'email' => '',
        'phone' => '',
        'financeContactName' => '',
        'financeContactEmail' => '',
        'financeContactPhone' => '',
        'updatedAt' => null,
        'updatedBy' => '',
        'history' => [],
    ];

    public function getShiftWindows(): JsonResponse
    {
        try {
            $setting = Setting::where('key', self::SHIFT_WINDOWS_KEY)->first();
            $value = $setting?->value ?? self::DEFAULT_WINDOWS;
        } catch (QueryException $e) {
            // Table missing or other query issue; return defaults to keep UI working.
            $value = self::DEFAULT_WINDOWS;
        }

        return response()->json(['data' => $value]);
    }

    public function updateShiftWindows(Request $request): JsonResponse
    {
        $r = ['required', 'regex:' . self::TIME_REGEX];
        $data = $request->validate([
            'normal_start' => $r,
            'normal_end'   => $r,
            'day_start'    => $r,
            'day_end'      => $r,
            'night_start'  => $r,
            'night_end'    => $r,
        ]);

        try {
            Setting::updateOrCreate(
                ['key' => self::SHIFT_WINDOWS_KEY],
                ['value' => $data]
            );
        } catch (QueryException $e) {
            // If table missing, surface a friendly error.
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        return response()->json(['message' => 'Shift windows updated.', 'data' => $data]);
    }

    // ── Custom Shifts ─────────────────────────────────────────────────────────

    private const BUILT_IN_SHIFTS = [
        ['slug' => 'day',    'name' => 'Day',    'start' => null, 'end' => null, 'builtin' => true],
        ['slug' => 'night',  'name' => 'Night',  'start' => null, 'end' => null, 'builtin' => true],
    ];

    public function getCustomShifts(): JsonResponse
    {
        return response()->json(['data' => CustomShift::orderBy('sort_order')->orderBy('name')->get()]);
    }

    /**
     * Returns the full ordered shift list for the roster assignment UI:
     * built-ins (day, night) first, then custom shifts.
     */
    public function getAllShifts(): JsonResponse
    {
        $custom = CustomShift::orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($s) => [
                'slug'    => $s->name,
                'name'    => $s->name,
                'start'   => $s->start,
                'end'     => $s->end,
                'builtin' => false,
            ])->values()->toArray();

        return response()->json(['data' => array_merge(self::BUILT_IN_SHIFTS, $custom)]);
    }

    public function storeCustomShift(Request $request): JsonResponse
    {
        if (CustomShift::count() >= 50) {
            return response()->json(['message' => 'Custom shift limit reached (50).'], 422);
        }

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:100', 'unique:custom_shifts,name'],
            'start' => ['required', 'regex:' . self::TIME_REGEX],
            'end'   => ['required', 'regex:' . self::TIME_REGEX],
        ]);

        $shift = CustomShift::create($data);

        AuditLogger::log($request, 'custom_shift_created', null, ['id' => $shift->id, 'name' => $shift->name]);

        return response()->json(['data' => $shift], 201);
    }

    public function updateCustomShift(Request $request, CustomShift $customShift): JsonResponse
    {
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:100', Rule::unique('custom_shifts', 'name')->ignore($customShift->id)],
            'start' => ['required', 'regex:' . self::TIME_REGEX],
            'end'   => ['required', 'regex:' . self::TIME_REGEX],
        ]);

        $customShift->update($data);

        AuditLogger::log($request, 'custom_shift_updated', null, ['id' => $customShift->id, 'name' => $customShift->name]);

        return response()->json(['data' => $customShift->fresh()]);
    }

    public function deleteCustomShift(Request $request, CustomShift $customShift): JsonResponse
    {
        // Prevent deletion if this shift slug is referenced by any roster row
        $slug = $customShift->name;
        $inUse = \DB::table('rosters')->where('shift', $slug)->exists();

        if ($inUse) {
            return response()->json([
                'message' => "Cannot delete \"{$slug}\" — it is assigned in existing roster records. Remove all roster assignments for this shift first.",
            ], 422);
        }

        AuditLogger::log($request, 'custom_shift_deleted', null, ['id' => $customShift->id, 'name' => $customShift->name]);

        $customShift->delete();

        return response()->json(['message' => 'Custom shift deleted.']);
    }

    // ── Leave Approval Rules ──────────────────────────────────────────────────

    public function getLeaveApprovalRules(): JsonResponse
    {
        try {
            $rules = $this->leaveWorkflowService->loadApprovalRules();
        } catch (QueryException $e) {
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        return response()->json(['data' => $rules]);
    }

    public function updateLeaveApprovalRules(Request $request): JsonResponse
    {
        $roleRequired = ['required', 'string', 'max:255', Rule::exists('roles', 'name')];
        $roleNullable = ['nullable', 'string', 'max:255', Rule::exists('roles', 'name')];
        $data = $request->validate([
            'rules'                             => ['required', 'array'],
            'rules.*.id'                        => ['nullable', 'string'],
            'rules.*.applicantRole'             => $roleRequired,
            'rules.*.reviewRole'                => $roleRequired,
            'rules.*.recommendRole'             => $roleNullable,
            'rules.*.approveRole'               => $roleRequired,
            'rules.*.active'                    => ['nullable', 'boolean'],
            'fallback'                          => ['nullable', 'array'],
            'fallback.reviewRole'               => $roleNullable,
            'fallback.recommendRole'            => $roleNullable,
            'fallback.approveRole'              => $roleNullable,
            'options'                           => ['nullable', 'array'],
            'options.requireRecommendation'     => ['nullable', 'boolean'],
            'options.enforceDistinctApprovers'  => ['nullable', 'boolean'],
        ]);

        try {
            $this->leaveWorkflowService->saveApprovalRules($data);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        return response()->json([
            'message' => 'Leave approval rules updated.',
            'data'    => $this->leaveWorkflowService->loadApprovalRules(),
        ]);
    }

    public function getOvertimeApprovalRules(): JsonResponse
    {
        try {
            $rules = $this->overtimeWorkflowService->loadApprovalRules();
        } catch (QueryException $e) {
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        return response()->json(['data' => $rules]);
    }

    public function updateOvertimeApprovalRules(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workflow' => ['nullable', 'array'],
            'workflow.rules' => ['nullable', 'array'],
            'workflow.fallback' => ['nullable', 'array'],
            'workflow.options' => ['nullable', 'array'],
            'typeVisibility' => ['nullable', 'array'],
        ]);

        try {
            $this->overtimeWorkflowService->saveApprovalRules($data);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        return response()->json([
            'message' => 'Overtime approval rules updated.',
            'data' => $this->overtimeWorkflowService->loadApprovalRules(),
        ]);
    }

    public function getInspectionWorkflowRules(): JsonResponse
    {
        try {
            $rules = $this->inspectionWorkflowService->loadWorkflowRules();
        } catch (QueryException $e) {
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        return response()->json(['data' => $rules]);
    }

    public function getReportingWorkflowRules(): JsonResponse
    {
        try {
            $rules = $this->reportingWorkflowService->loadWorkflowRules();
        } catch (QueryException $e) {
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        return response()->json(['data' => $rules]);
    }

    public function updateInspectionWorkflowRules(Request $request): JsonResponse
    {
        $roleRequired = ['required', 'string', 'max:255', Rule::exists('roles', 'name')];
        $data = $request->validate([
            'fallback' => ['required', 'array'],
            'fallback.reviewRole' => $roleRequired,
            'fallback.fallbackReviewRole' => $roleRequired,
            'fallback.approveRole' => $roleRequired,
            'options' => ['nullable', 'array'],
            'options.useTeamScopedAic' => ['nullable', 'boolean'],
            'options.allowSubmitWithoutTeam' => ['nullable', 'boolean'],
            'options.allowIcFallbackReview' => ['nullable', 'boolean'],
            'options.preventSelfReview' => ['nullable', 'boolean'],
            'options.preventSelfApprove' => ['nullable', 'boolean'],
        ]);

        try {
            $this->inspectionWorkflowService->saveWorkflowRules($data);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        return response()->json([
            'message' => 'Inspection workflow rules updated.',
            'data' => $this->inspectionWorkflowService->loadWorkflowRules(),
        ]);
    }

    public function updateReportingWorkflowRules(Request $request): JsonResponse
    {
        $roleRequired = ['required', 'string', 'max:255', Rule::exists('roles', 'name')];
        $data = $request->validate([
            'modules' => ['required', 'array'],
            'modules.inspection' => ['required', 'array'],
            'modules.inspection.fallback' => ['required', 'array'],
            'modules.inspection.fallback.reviewRole' => $roleRequired,
            'modules.inspection.fallback.fallbackReviewRole' => $roleRequired,
            'modules.inspection.fallback.approveRole' => $roleRequired,
            'modules.inspection.options' => ['nullable', 'array'],
            'modules.inspection.options.useTeamScopedAic' => ['nullable', 'boolean'],
            'modules.inspection.options.allowSubmitWithoutTeam' => ['nullable', 'boolean'],
            'modules.inspection.options.allowIcFallbackReview' => ['nullable', 'boolean'],
            'modules.inspection.options.preventSelfReview' => ['nullable', 'boolean'],
            'modules.inspection.options.preventSelfApprove' => ['nullable', 'boolean'],
            'modules.erco' => ['nullable', 'array'],
            'modules.erco.fallback' => ['nullable', 'array'],
            'modules.erco.fallback.reviewRole' => ['nullable', 'string', 'max:255', Rule::exists('roles', 'name')],
            'modules.erco.fallback.fallbackReviewRole' => ['nullable', 'string', 'max:255', Rule::exists('roles', 'name')],
            'modules.erco.fallback.approveRole' => ['nullable', 'string', 'max:255', Rule::exists('roles', 'name')],
            'modules.erco.options' => ['nullable', 'array'],
            'modules.erco.options.useTeamScopedAic' => ['nullable', 'boolean'],
            'modules.erco.options.allowSubmitWithoutTeam' => ['nullable', 'boolean'],
            'modules.erco.options.allowIcFallbackReview' => ['nullable', 'boolean'],
            'modules.erco.options.preventSelfReview' => ['nullable', 'boolean'],
            'modules.erco.options.preventSelfApprove' => ['nullable', 'boolean'],
            'modules.drill' => ['nullable', 'array'],
            'modules.drill.fallback' => ['nullable', 'array'],
            'modules.drill.fallback.reviewRole' => ['nullable', 'string', 'max:255', Rule::exists('roles', 'name')],
            'modules.drill.fallback.fallbackReviewRole' => ['nullable', 'string', 'max:255', Rule::exists('roles', 'name')],
            'modules.drill.fallback.approveRole' => ['nullable', 'string', 'max:255', Rule::exists('roles', 'name')],
            'modules.drill.options' => ['nullable', 'array'],
            'modules.drill.options.useTeamScopedAic' => ['nullable', 'boolean'],
            'modules.drill.options.allowSubmitWithoutTeam' => ['nullable', 'boolean'],
            'modules.drill.options.allowIcFallbackReview' => ['nullable', 'boolean'],
            'modules.drill.options.preventSelfReview' => ['nullable', 'boolean'],
            'modules.drill.options.preventSelfApprove' => ['nullable', 'boolean'],
            'modules.fitness-test' => ['nullable', 'array'],
            'modules.fitness-test.fallback' => ['nullable', 'array'],
            'modules.fitness-test.fallback.reviewRole' => ['nullable', 'string', 'max:255', Rule::exists('roles', 'name')],
            'modules.fitness-test.fallback.fallbackReviewRole' => ['nullable', 'string', 'max:255', Rule::exists('roles', 'name')],
            'modules.fitness-test.fallback.approveRole' => ['nullable', 'string', 'max:255', Rule::exists('roles', 'name')],
            'modules.fitness-test.options' => ['nullable', 'array'],
            'modules.fitness-test.options.useTeamScopedAic' => ['nullable', 'boolean'],
            'modules.fitness-test.options.allowSubmitWithoutTeam' => ['nullable', 'boolean'],
            'modules.fitness-test.options.allowIcFallbackReview' => ['nullable', 'boolean'],
            'modules.fitness-test.options.preventSelfReview' => ['nullable', 'boolean'],
            'modules.fitness-test.options.preventSelfApprove' => ['nullable', 'boolean'],
        ]);

        try {
            $saved = $this->reportingWorkflowService->saveWorkflowRules($data);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        return response()->json([
            'message' => 'Reporting workflow rules updated.',
            'data' => $saved,
        ]);
    }

    public function getOvertimeRateSettings(): JsonResponse
    {
        try {
            $rates = $this->overtimeWorkflowService->loadRateSettings();
        } catch (QueryException $e) {
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        return response()->json(['data' => $rates]);
    }

    public function updateOvertimeRateSettings(Request $request): JsonResponse
    {
        $multiplierRule = ['nullable', 'numeric', 'min:0', 'max:100'];
        $data = $request->validate([
            'weekdayMultiplier'      => $multiplierRule,
            'weekendMultiplier'      => $multiplierRule,
            'publicHolidayMultiplier'=> $multiplierRule,
            'otApplicability'        => ['nullable', 'array'],
            'otApplicability.roles'  => ['nullable', 'array'],
            'otApplicability.roles.*'=> ['string', 'max:255', Rule::exists('roles', 'name')],
            'baseHourCalculation'    => ['nullable', 'array'],
            'baseHourCalculation.mode' => ['nullable', Rule::in(['auto_statutory', 'month_days_division'])],
            'baseHourCalculation.monthlyDivisor' => ['nullable', 'numeric', 'min:0', 'max:366'],
            'baseHourCalculation.globalNormalHoursPerDay' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'baseHourCalculation.normalHoursStrategy' => ['nullable', Rule::in(['statutory_8h', 'global', 'role_based'])],
            'baseHourCalculation.defaultRoleHoursPerDay' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'baseHourCalculation.roleNormalHoursPerDay' => ['nullable', 'array'],
            'baseHourCalculation.roleNormalHoursPerDay.*' => ['nullable', 'numeric', 'min:0', 'max:24'],
        ]);

        try {
            $this->overtimeWorkflowService->saveRateSettings($data);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        return response()->json([
            'message' => 'Overtime rate settings updated.',
            'data' => $this->overtimeWorkflowService->loadRateSettings(),
        ]);
    }

    public function getSalaryWorkflowRules(): JsonResponse
    {
        try {
            $rules = $this->payrollClaimWorkflowService->loadWorkflowRules();
        } catch (QueryException $e) {
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        return response()->json(['data' => $rules]);
    }

    public function updateSalaryWorkflowRules(Request $request): JsonResponse
    {
        $roleNullable = ['nullable', 'string', 'max:255', Rule::exists('roles', 'name')];
        $data = $request->validate([
            'fallback'             => ['nullable', 'array'],
            'fallback.checkRole'   => $roleNullable,
            'fallback.reviewRole'  => $roleNullable,
            'fallback.approveRole' => $roleNullable,
        ]);

        try {
            $this->payrollClaimWorkflowService->saveWorkflowRules($data);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        return response()->json([
            'message' => 'Salary workflow rules updated.',
            'data' => $this->payrollClaimWorkflowService->loadWorkflowRules(),
        ]);
    }

    public function getSalaryStatutoryRates(): JsonResponse
    {
        try {
            $setting = Setting::query()->where('key', self::SALARY_STATUTORY_RATES_KEY)->first();
            $stored = is_array($setting?->value) ? $setting->value : [];
        } catch (QueryException $e) {
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        return response()->json(['data' => $this->normalizeSalaryStatutoryRates($stored)]);
    }

    public function updateSalaryStatutoryRates(Request $request): JsonResponse
    {
        $rateRule = ['required', 'numeric', 'min:0', 'max:1'];
        $data = $request->validate([
            'epf' => ['required', 'array'],
            'epf.employeeRate' => $rateRule,
            'epf.employerRate' => $rateRule,
            'perkeso' => ['required', 'array'],
            'perkeso.employeeRate' => $rateRule,
            'perkeso.employerRate' => $rateRule,
            'sip' => ['required', 'array'],
            'sip.employeeRate' => $rateRule,
            'sip.employerRate' => $rateRule,
        ]);

        $actorName = (string) ($request->user()?->name ?? '');
        $normalized = $this->normalizeSalaryStatutoryRates(array_merge($data, [
            'updatedAt' => now()->toIso8601String(),
            'updatedBy' => $actorName,
        ]));

        try {
            Setting::query()->updateOrCreate(
                ['key' => self::SALARY_STATUTORY_RATES_KEY],
                ['value' => $normalized]
            );
        } catch (QueryException $e) {
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        AuditLogger::log($request, 'salary_statutory_rates_updated', null, [
            'updatedBy' => $actorName,
            'rates' => $normalized,
        ]);

        return response()->json([
            'message' => 'Salary statutory rates updated.',
            'data' => $normalized,
        ]);
    }

    public function getPayrollCompanyProfile(): JsonResponse
    {
        try {
            $setting = Setting::query()->where('key', self::PAYROLL_COMPANY_PROFILE_KEY)->first();
            $stored = is_array($setting?->value) ? $setting->value : [];
        } catch (QueryException $e) {
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        return response()->json(['data' => $this->normalizePayrollCompanyProfile($stored)]);
    }

    public function updatePayrollCompanyProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legalName' => ['nullable', 'string', 'max:255'],
            'registrationNumber' => ['nullable', 'string', 'max:100'],
            'myTaxNumber' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'financeContactName' => ['nullable', 'string', 'max:255'],
            'financeContactEmail' => ['nullable', 'email', 'max:255'],
            'financeContactPhone' => ['nullable', 'string', 'max:50'],
        ]);

        $actorName = (string) ($request->user()?->name ?? '');
        $updatedAt = now()->toIso8601String();
        $normalized = $this->normalizePayrollCompanyProfile(array_merge($data, [
            'updatedAt' => now()->toIso8601String(),
            'updatedBy' => $actorName,
        ]));

        $existingHistory = [];
        try {
            $existingSetting = Setting::query()->where('key', self::PAYROLL_COMPANY_PROFILE_KEY)->first();
            $existingRaw = is_array($existingSetting?->value) ? $existingSetting->value : [];
            $existingHistoryRaw = $existingRaw['history'] ?? [];
            $existingHistory = is_array($existingHistoryRaw) ? $existingHistoryRaw : [];
        } catch (QueryException) {
            $existingHistory = [];
        }

        $historyEntry = [
            'updatedAt' => $updatedAt,
            'updatedBy' => $actorName,
            'legalName' => $normalized['legalName'],
            'registrationNumber' => $normalized['registrationNumber'],
            'myTaxNumber' => $normalized['myTaxNumber'],
            'address' => $normalized['address'],
            'email' => $normalized['email'],
            'phone' => $normalized['phone'],
            'financeContactName' => $normalized['financeContactName'],
            'financeContactEmail' => $normalized['financeContactEmail'],
            'financeContactPhone' => $normalized['financeContactPhone'],
        ];
        $normalized['history'] = array_slice(
            array_values([...$existingHistory, $historyEntry]),
            -100,
        );

        try {
            Setting::query()->updateOrCreate(
                ['key' => self::PAYROLL_COMPANY_PROFILE_KEY],
                ['value' => $normalized]
            );
        } catch (QueryException $e) {
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        AuditLogger::log($request, 'payroll_company_profile_updated', null, [
            'updatedBy' => $actorName,
            'profile' => $normalized,
        ]);

        return response()->json([
            'message' => 'Payroll company profile updated.',
            'data' => $normalized,
        ]);
    }

    public function getSystemMaintenance(): JsonResponse
    {
        try {
            $loaded = $this->systemMaintenanceService->load();
            $resolved = $this->systemMaintenanceService->resolveState($loaded);
            $setting = $resolved['setting'];
        } catch (QueryException $e) {
            $setting = $this->systemMaintenanceService->default();
        }

        return response()->json(['data' => $setting]);
    }

    public function updateSystemMaintenance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:500'],
            'updatedAt' => ['nullable', 'date'],
            'phase' => ['nullable', Rule::in([
                SystemMaintenanceService::PHASE_OFF,
                SystemMaintenanceService::PHASE_GRACE,
                SystemMaintenanceService::PHASE_ENFORCED,
            ])],
            'graceEndsAt' => ['nullable', 'date'],
        ]);

        try {
            $previous = $this->systemMaintenanceService->loadFresh();
            $setting = $this->systemMaintenanceService->save($data, $request->user());
        } catch (QueryException $e) {
            return response()->json(['message' => 'Settings table not available. Please run migrations.'], 500);
        }

        $action = $this->resolveSystemMaintenanceAuditAction($previous, $setting);
        if ($action) {
            AuditLogger::log($request, $action, null, [
                'previous' => $previous,
                'next' => $setting,
            ]);
        }

        return response()->json([
            'message' => 'System maintenance setting updated.',
            'data' => $setting,
        ]);
    }

    private function resolveSystemMaintenanceAuditAction(array $previous, array $next): ?string
    {
        $previousEnabled = (bool) ($previous['enabled'] ?? false);
        $nextEnabled = (bool) ($next['enabled'] ?? false);
        $previousPhase = (string) ($previous['phase'] ?? SystemMaintenanceService::PHASE_OFF);
        $nextPhase = (string) ($next['phase'] ?? SystemMaintenanceService::PHASE_OFF);

        if (! $previousEnabled && $nextEnabled && $nextPhase === SystemMaintenanceService::PHASE_GRACE) {
            return 'system_maintenance_enabled_grace';
        }
        if ($nextEnabled && $nextPhase === SystemMaintenanceService::PHASE_ENFORCED && $previousPhase !== SystemMaintenanceService::PHASE_ENFORCED) {
            return 'system_maintenance_enforced';
        }
        if ($previousEnabled && ! $nextEnabled) {
            return 'system_maintenance_disabled';
        }

        return 'system_maintenance_updated';
    }

    private function normalizeSalaryStatutoryRates(array $value): array
    {
        $defaults = self::DEFAULT_SALARY_STATUTORY_RATES;
        $toRate = static fn (mixed $candidate, float $fallback): float => is_numeric($candidate)
            ? max(0.0, min(1.0, round((float) $candidate, 6)))
            : $fallback;

        return [
            'epf' => [
                'employeeRate' => $toRate($value['epf']['employeeRate'] ?? null, (float) $defaults['epf']['employeeRate']),
                'employerRate' => $toRate($value['epf']['employerRate'] ?? null, (float) $defaults['epf']['employerRate']),
            ],
            'perkeso' => [
                'employeeRate' => $toRate($value['perkeso']['employeeRate'] ?? null, (float) $defaults['perkeso']['employeeRate']),
                'employerRate' => $toRate($value['perkeso']['employerRate'] ?? null, (float) $defaults['perkeso']['employerRate']),
            ],
            'sip' => [
                'employeeRate' => $toRate($value['sip']['employeeRate'] ?? null, (float) $defaults['sip']['employeeRate']),
                'employerRate' => $toRate($value['sip']['employerRate'] ?? null, (float) $defaults['sip']['employerRate']),
            ],
            'updatedAt' => $value['updatedAt'] ?? $defaults['updatedAt'],
            'updatedBy' => (string) ($value['updatedBy'] ?? $defaults['updatedBy']),
        ];
    }

    private function normalizePayrollCompanyProfile(array $value): array
    {
        $defaults = self::DEFAULT_PAYROLL_COMPANY_PROFILE;

        return [
            'legalName' => trim((string) ($value['legalName'] ?? $defaults['legalName'])),
            'registrationNumber' => trim((string) ($value['registrationNumber'] ?? $defaults['registrationNumber'])),
            'myTaxNumber' => trim((string) ($value['myTaxNumber'] ?? $defaults['myTaxNumber'])),
            'address' => trim((string) ($value['address'] ?? $defaults['address'])),
            'email' => trim((string) ($value['email'] ?? $defaults['email'])),
            'phone' => trim((string) ($value['phone'] ?? $defaults['phone'])),
            'financeContactName' => trim((string) ($value['financeContactName'] ?? $defaults['financeContactName'])),
            'financeContactEmail' => trim((string) ($value['financeContactEmail'] ?? $defaults['financeContactEmail'])),
            'financeContactPhone' => trim((string) ($value['financeContactPhone'] ?? $defaults['financeContactPhone'])),
            'updatedAt' => $value['updatedAt'] ?? $defaults['updatedAt'],
            'updatedBy' => trim((string) ($value['updatedBy'] ?? $defaults['updatedBy'])),
            'history' => collect(is_array($value['history'] ?? null) ? $value['history'] : [])
                ->map(function ($entry) {
                    if (!is_array($entry)) return null;
                    return [
                        'updatedAt' => trim((string) ($entry['updatedAt'] ?? '')),
                        'updatedBy' => trim((string) ($entry['updatedBy'] ?? '')),
                        'legalName' => trim((string) ($entry['legalName'] ?? '')),
                        'registrationNumber' => trim((string) ($entry['registrationNumber'] ?? '')),
                        'myTaxNumber' => trim((string) ($entry['myTaxNumber'] ?? '')),
                        'address' => trim((string) ($entry['address'] ?? '')),
                        'email' => trim((string) ($entry['email'] ?? '')),
                        'phone' => trim((string) ($entry['phone'] ?? '')),
                        'financeContactName' => trim((string) ($entry['financeContactName'] ?? '')),
                        'financeContactEmail' => trim((string) ($entry['financeContactEmail'] ?? '')),
                        'financeContactPhone' => trim((string) ($entry['financeContactPhone'] ?? '')),
                    ];
                })
                ->filter()
                ->values()
                ->all(),
        ];
    }
}
