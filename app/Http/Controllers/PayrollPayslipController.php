<?php

namespace App\Http\Controllers;

use App\Models\PayrollClaim;
use App\Models\PayrollClaimItem;
use App\Models\SalaryAssignment;
use App\Models\Setting;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Database\QueryException;

class PayrollPayslipController extends Controller
{
    private const PAYROLL_COMPANY_PROFILE_KEY = 'payroll_company_profile';
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
    ];
    private ?array $payrollCompanyProfile = null;
    private ?AssignmentAuthorizationService $assignmentAuthorization = null;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = PayrollClaim::query()
            ->where('user_id', $user->id)
            ->where('claim_type', 'salary')
            ->with(['items', 'user'])
            ->orderByDesc('period_value')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('period_value') && preg_match('/^\d{4}-\d{2}$/', (string) $request->input('period_value'))) {
            $query->where('period_value', (string) $request->input('period_value'));
        }

        $salaryRecords = SalaryAssignment::query()
            ->where('employee_user_id', $user->id)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        $rows = $query->get()->map(function (PayrollClaim $claim) use ($salaryRecords) {
            $snapshot = $this->resolveOrBuildPayslipSnapshot($claim, $salaryRecords);
            $profileCompleteness = $this->buildEmployeeProfileCompleteness($claim->user);
            $downloadable = $this->isDownloadable($claim) && $profileCompleteness['complete'];
            $downloadReason = null;
            if (! $this->isDownloadable($claim)) {
                $downloadReason = 'Payslip is only available for approved or paid salary claims.';
            } elseif (! $profileCompleteness['complete']) {
                $downloadReason = 'Personal information missing: '.implode(', ', $profileCompleteness['missingLabels']).'.';
            }

            return [
                'id' => $claim->id,
                'payslip_id' => $claim->id,
                'reference' => $claim->display_id,
                'period_value' => $claim->period_value,
                'month' => $this->formatMonthLabel($claim),
                'issued_at' => (string) data_get($snapshot, 'issuedAt', ''),
                'payment_date' => (string) ($claim->payment_date?->toDateString() ?? ''),
                'status' => (string) ($claim->status ?? ''),
                'downloadable' => $downloadable,
                'download_reason' => $downloadReason,
                'download_filename' => $this->buildDownloadFilename($claim),
                'baseline_source' => data_get($snapshot, 'baselineSource', 'unavailable'),
                'employee_profile_complete' => $profileCompleteness['complete'],
                'employee_profile_missing_fields' => $profileCompleteness['missingKeys'],
                'salary_record' => data_get($snapshot, 'salaryRecord'),
                'payroll_snapshot' => data_get($snapshot, 'payrollSnapshot', []),
                'baseline' => data_get($snapshot, 'baseline', []),
                'adjustments' => data_get($snapshot, 'adjustments', []),
                'adjustments_total' => (float) data_get($snapshot, 'adjustmentsTotal', 0),
                'overtime' => data_get($snapshot, 'overtime', []),
                'totals' => data_get($snapshot, 'totals', []),
                'amount' => (float) $claim->amount,
                'approved_overtime_payout' => (float) $claim->approved_overtime_payout,
                'projected_net_payout' => (float) ($claim->projected_net_payout ?? 0),
                'updated_at' => optional($claim->updated_at)->toIso8601String(),
            ];
        })->values()->all();

        return response()->json(['data' => $rows]);
    }

    public function download(Request $request, int $id)
    {
        $user = $request->user();

        $claim = PayrollClaim::query()
            ->where('user_id', $user->id)
            ->where('claim_type', 'salary')
            ->with(['items.attachment', 'user'])
            ->findOrFail($id);

        if (! $this->isDownloadable($claim)) {
            return response()->json([
                'message' => 'Payslip download unavailable for this record.',
            ], 422);
        }

        $profileCompleteness = $this->buildEmployeeProfileCompleteness($claim->user);
        if (! $profileCompleteness['complete']) {
            return response()->json([
                'message' => 'Personal information missing: '.implode(', ', $profileCompleteness['missingLabels']).'. Please update your Profile before generating payslip.',
                'missing_fields' => $profileCompleteness['missingKeys'],
            ], 422);
        }

        $salaryRecords = SalaryAssignment::query()
            ->where('employee_user_id', $user->id)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
        $payload = $this->resolveOrBuildPayslipSnapshot($claim, $salaryRecords);
        $payload['status'] = (string) ($claim->status ?? data_get($payload, 'status', ''));
        $payload['paymentDate'] = (string) ($claim->payment_date?->toDateString() ?? '');
        $payload['generatedAt'] = now()->toIso8601String();
        $payload['generatedBy'] = $this->buildGeneratedByMeta($request);
        $document = Pdf::loadView('pdf.payroll-payslip', [
            'payslip' => $payload,
        ])->setPaper('a4')->setOption([
            'defaultFont' => 'Helvetica',
            'isFontSubsettingEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => false,
        ]);
        $output = $document->output(['compress' => 1]);

        AuditLogger::log($request, 'payroll_payslip_downloaded', $user, [
            'claim_id' => $claim->id,
            'display_id' => $claim->display_id,
            'period_value' => $claim->period_value,
            'status' => $claim->status,
        ]);

        return response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->buildDownloadFilename($claim).'"; filename*=UTF-8\'\''.rawurlencode($this->buildDownloadFilename($claim)),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Length' => strlen($output),
        ]);
    }

    private function isDownloadable(PayrollClaim $claim): bool
    {
        return in_array((string) $claim->status, ['Approved', 'Paid'], true);
    }

    private function resolveOrBuildPayslipSnapshot(PayrollClaim $claim, Collection $salaryRecords): array
    {
        $storedSnapshot = is_array($claim->payslip_snapshot) ? $claim->payslip_snapshot : [];
        if ($storedSnapshot !== []) {
            $hydratedSnapshot = $this->hydrateSnapshotDefaults($claim, $storedSnapshot);
            if ($hydratedSnapshot !== $storedSnapshot && $this->isDownloadable($claim)) {
                $claim->forceFill([
                    'payslip_snapshot' => $hydratedSnapshot,
                ])->save();
            }

            return $hydratedSnapshot;
        }

        $salaryRecord = $this->resolveSalaryRecordForClaim($claim, $salaryRecords);
        $details = $this->buildPayslipDetails($claim, $salaryRecord);
        $issuedAt = $this->resolveIssuedAt($claim);
        $paymentDate = $claim->payment_date?->toDateString() ?? '';
        $periodMeta = $this->buildPeriodMeta($claim);
        $snapshot = [
            'payslipId' => $claim->id,
            'reference' => $claim->display_id,
            'employeeId' => $claim->user_id,
            'employeeName' => (string) ($claim->user?->name ?? ''),
            'employeeProfile' => $this->buildEmployeeProfileMeta($claim->user),
            'employeeRoles' => $this->resolveUserRoleLabels($claim->user),
            'employeeStatutory' => $this->buildEmployeeStatutoryMeta($claim->user?->statutory_info),
            'employer' => $this->buildEmployerMeta(),
            'period' => $periodMeta,
            'issuedAt' => optional($issuedAt)->toIso8601String(),
            'paymentDate' => $paymentDate,
            'status' => (string) ($claim->status ?? ''),
            'baselineSource' => $details['baselineSource'],
            'salaryRecord' => $details['salaryRecord'],
            'payrollSnapshot' => $details['payrollSnapshot'],
            'baseline' => $details['baseline'],
            'adjustments' => $details['adjustments'],
            'adjustmentsTotal' => $details['adjustmentsTotal'],
            'overtime' => $details['overtime'],
            'totals' => $details['totals'],
            'issuedSnapshotAt' => now()->toIso8601String(),
        ];

        if ($this->isDownloadable($claim)) {
            $claim->forceFill([
                'payslip_snapshot' => $snapshot,
            ])->save();
        }

        return $snapshot;
    }

    private function hydrateSnapshotDefaults(PayrollClaim $claim, array $snapshot): array
    {
        $periodMeta = is_array(data_get($snapshot, 'period')) ? data_get($snapshot, 'period') : [];
        $defaultPeriodMeta = $this->buildPeriodMeta($claim);
        $paymentDate = (string) ($claim->payment_date?->toDateString() ?? '');

        return array_merge($snapshot, [
            'employeeId' => data_get($snapshot, 'employeeId', $claim->user_id),
            'employeeName' => (string) data_get($snapshot, 'employeeName', $claim->user?->name ?? ''),
            'employeeProfile' => $this->mergeScalarMetaPreferBase(
                $this->buildEmployeeProfileMeta($claim->user),
                is_array(data_get($snapshot, 'employeeProfile')) ? data_get($snapshot, 'employeeProfile') : [],
            ),
            'employeeRoles' => (function () use ($snapshot, $claim) {
                $snapshotRoles = collect(data_get($snapshot, 'employeeRoles', []))
                    ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                    ->values()
                    ->all();
                if ($snapshotRoles !== []) {
                    return $snapshotRoles;
                }

                return $this->resolveUserRoleLabels($claim->user);
            })(),
            'employeeStatutory' => array_merge(
                $this->buildEmployeeStatutoryMeta($claim->user?->statutory_info),
                is_array(data_get($snapshot, 'employeeStatutory')) ? data_get($snapshot, 'employeeStatutory') : [],
            ),
            'employer' => $this->mergeScalarMetaPreferBase(
                $this->buildEmployerMeta(),
                is_array(data_get($snapshot, 'employer')) ? data_get($snapshot, 'employer') : [],
            ),
            'period' => array_merge($defaultPeriodMeta, $periodMeta),
            'status' => (string) data_get($snapshot, 'status', (string) ($claim->status ?? '')),
            'paymentDate' => $paymentDate,
        ]);
    }

    private function buildPayslipDetails(PayrollClaim $claim, ?SalaryAssignment $salaryRecord): array
    {
        $snapshot = is_array($claim->payroll_snapshot) ? $claim->payroll_snapshot : [];
        $formattedSalaryRecord = $this->formatSalaryRecord($salaryRecord);
        $salaryBaseline = $this->buildBaselineFromSalaryRecord($formattedSalaryRecord);
        $snapshotBaseline = $this->buildBaselineFromSnapshot($snapshot);

        $hasSalaryBaseline = $salaryBaseline['hasData'];
        $hasSnapshotBaseline = $snapshotBaseline['hasData'];
        $baselineSource = match (true) {
            $hasSalaryBaseline && $hasSnapshotBaseline => 'hybrid',
            $hasSnapshotBaseline => 'claim_snapshot',
            $hasSalaryBaseline => 'salary_record',
            default => 'unavailable',
        };

        $baseline = [
            'basicSalary' => $this->pickMoney($snapshotBaseline['basicSalary'], $salaryBaseline['basicSalary']),
            'allowanceTotal' => $this->pickMoney($snapshotBaseline['allowanceTotal'], $salaryBaseline['allowanceTotal']),
            'grossSalary' => $this->pickMoney($snapshotBaseline['grossSalary'], $salaryBaseline['grossSalary']),
            'employeeDeductionsTotal' => $this->pickMoney(
                $snapshotBaseline['employeeDeductionsTotal'],
                $salaryBaseline['employeeDeductionsTotal'],
            ),
            'netSalary' => $this->pickMoney($snapshotBaseline['netSalary'], $salaryBaseline['netSalary']),
            'allowanceItems' => ! empty($snapshotBaseline['allowanceItems'])
                ? $snapshotBaseline['allowanceItems']
                : $salaryBaseline['allowanceItems'],
            'deductionItems' => ! empty($snapshotBaseline['deductionItems'])
                ? $snapshotBaseline['deductionItems']
                : $salaryBaseline['deductionItems'],
            'employeeContributions' => ! empty($snapshotBaseline['employeeContributions'])
                ? $snapshotBaseline['employeeContributions']
                : $salaryBaseline['employeeContributions'],
            'employerContributions' => ! empty($snapshotBaseline['employerContributions'])
                ? $snapshotBaseline['employerContributions']
                : $salaryBaseline['employerContributions'],
        ];

        $basicSalary = (float) ($baseline['basicSalary'] ?? 0);
        $allowanceTotal = (float) ($baseline['allowanceTotal'] ?? 0);
        $grossSalary = (float) ($baseline['grossSalary'] ?? round($basicSalary + $allowanceTotal, 2));
        $employeeDeductionsTotal = (float) ($baseline['employeeDeductionsTotal'] ?? 0);
        $netSalary = (float) ($baseline['netSalary'] ?? round($grossSalary - $employeeDeductionsTotal, 2));
        $baseline['basicSalary'] = round($basicSalary, 2);
        $baseline['allowanceTotal'] = round($allowanceTotal, 2);
        $baseline['grossSalary'] = round($grossSalary, 2);
        $baseline['employeeDeductionsTotal'] = round($employeeDeductionsTotal, 2);
        $baseline['netSalary'] = round($netSalary, 2);

        $adjustments = $this->formatAdjustments($claim);
        $adjustmentsTotal = round((float) ($claim->adjustments_total ?? 0), 2);
        $overtime = $this->formatOvertime($claim);
        $approvedOvertimePayout = round((float) ($claim->approved_overtime_payout ?? 0), 2);
        $netPayable = round((float) ($claim->projected_net_payout ?? 0), 2);

        return [
            'baselineSource' => $baselineSource,
            'salaryRecord' => $formattedSalaryRecord,
            'payrollSnapshot' => $snapshot,
            'baseline' => $baseline,
            'adjustments' => $adjustments,
            'adjustmentsTotal' => $adjustmentsTotal,
            'overtime' => $overtime,
            'totals' => [
                'baselineNetSalary' => round((float) ($baseline['netSalary'] ?? 0), 2),
                'adjustmentsTotal' => $adjustmentsTotal,
                'approvedOvertimePayout' => $approvedOvertimePayout,
                'netPayable' => $netPayable,
                'claimedTotal' => round((float) $claim->amount, 2),
            ],
        ];
    }

    private function buildPeriodMeta(PayrollClaim $claim): array
    {
        $periodValue = trim((string) ($claim->period_value ?? ''));
        $startDate = null;
        $endDate = null;
        if (preg_match('/^\d{4}-\d{2}$/', $periodValue)) {
            try {
                $period = Carbon::createFromFormat('Y-m', $periodValue)->startOfMonth();
                $startDate = $period->copy()->toDateString();
                $endDate = $period->copy()->endOfMonth()->toDateString();
            } catch (\Throwable) {
                $startDate = null;
                $endDate = null;
            }
        }

        return [
            'label' => $this->formatMonthLabel($claim),
            'value' => $periodValue,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];
    }

    private function buildEmployerMeta(): array
    {
        $companyProfile = $this->loadPayrollCompanyProfile();
        $legalName = trim((string) ($companyProfile['legalName'] ?? ''));
        $registrationNumber = trim((string) ($companyProfile['registrationNumber'] ?? ''));

        return [
            'name' => $legalName !== '' ? $legalName : (string) config('app.name', 'Employer'),
            'registrationNumber' => $registrationNumber !== '' ? $registrationNumber : (string) env('PAYROLL_EMPLOYER_REG_NO', ''),
            'myTaxNumber' => trim((string) ($companyProfile['myTaxNumber'] ?? '')),
            'address' => trim((string) ($companyProfile['address'] ?? '')),
            'email' => trim((string) ($companyProfile['email'] ?? '')),
            'phone' => trim((string) ($companyProfile['phone'] ?? '')),
            'financeContactName' => trim((string) ($companyProfile['financeContactName'] ?? '')),
            'financeContactEmail' => trim((string) ($companyProfile['financeContactEmail'] ?? '')),
            'financeContactPhone' => trim((string) ($companyProfile['financeContactPhone'] ?? '')),
        ];
    }

    private function buildEmployeeStatutoryMeta(mixed $statutoryInfo): array
    {
        $raw = is_array($statutoryInfo) ? $statutoryInfo : [];

        return [
            'epfNo' => trim((string) ($raw['epfNo'] ?? '')),
            'perkesoNo' => trim((string) ($raw['perkesoNo'] ?? '')),
            'incomeTaxNo' => trim((string) ($raw['incomeTaxNo'] ?? '')),
        ];
    }

    private function buildEmployeeProfileMeta(mixed $user): array
    {
        return [
            'name' => trim((string) ($user?->name ?? '')),
            'icNumber' => trim((string) ($user?->ic_number ?? '')),
            'email' => trim((string) ($user?->email ?? '')),
            'phone' => trim((string) ($user?->phone ?? '')),
        ];
    }

    private function mergeScalarMetaPreferBase(array $base, array $snapshot): array
    {
        $merged = [];

        // Base (current settings) wins when present; snapshot only fills blanks/missing keys.
        foreach ($base as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $merged[$key] = $value;
                continue;
            }
            if (!is_string($value) && $value !== null) {
                $merged[$key] = $value;
                continue;
            }
            $snapshotValue = $snapshot[$key] ?? null;
            if (is_string($snapshotValue) && trim($snapshotValue) === '') {
                $snapshotValue = null;
            }
            $merged[$key] = $snapshotValue;
        }

        // Preserve any extra snapshot keys not modeled in base.
        foreach ($snapshot as $key => $value) {
            if (array_key_exists($key, $merged)) {
                continue;
            }
            $merged[$key] = $value;
        }

        return $merged;
    }

    private function resolveUserRoleLabels(mixed $user): array
    {
        if (! $user) {
            return [];
        }

        return $this->assignmentAuthorization()
            ->getActiveRoleNames($user)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->values()
            ->all();
    }

    private function buildEmployeeProfileCompleteness(mixed $user): array
    {
        $profile = $this->buildEmployeeProfileMeta($user);
        $roles = $this->resolveUserRoleLabels($user);
        $statutory = $this->buildEmployeeStatutoryMeta($user?->statutory_info);

        $requiredFields = [
            'name' => ['label' => 'name', 'value' => $profile['name']],
            'ic_number' => ['label' => 'IC number', 'value' => $profile['icNumber']],
            'email' => ['label' => 'email', 'value' => $profile['email']],
            'phone' => ['label' => 'contact number', 'value' => $profile['phone']],
            'role' => ['label' => 'role', 'value' => implode(', ', $roles)],
            'epf_number' => ['label' => 'EPF number', 'value' => $statutory['epfNo']],
        ];

        $missing = collect($requiredFields)
            ->filter(fn ($entry) => trim((string) ($entry['value'] ?? '')) === '')
            ->all();

        return [
            'complete' => count($missing) === 0,
            'missingKeys' => array_keys($missing),
            'missingLabels' => array_values(array_map(
                fn ($entry) => (string) ($entry['label'] ?? ''),
                $missing,
            )),
        ];
    }

    private function buildGeneratedByMeta(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $user?->id,
            'name' => trim((string) ($user?->name ?? '')),
            'email' => trim((string) ($user?->email ?? '')),
            'roles' => $this->resolveUserRoleLabels($user),
        ];
    }

    private function assignmentAuthorization(): AssignmentAuthorizationService
    {
        if (! $this->assignmentAuthorization instanceof AssignmentAuthorizationService) {
            $this->assignmentAuthorization = app(AssignmentAuthorizationService::class);
        }

        return $this->assignmentAuthorization;
    }

    private function loadPayrollCompanyProfile(): array
    {
        if ($this->payrollCompanyProfile !== null) {
            return $this->payrollCompanyProfile;
        }

        try {
            $setting = Setting::query()->where('key', self::PAYROLL_COMPANY_PROFILE_KEY)->first();
            $stored = is_array($setting?->value) ? $setting->value : [];
        } catch (QueryException) {
            $stored = [];
        }

        $defaults = self::DEFAULT_PAYROLL_COMPANY_PROFILE;
        $this->payrollCompanyProfile = [
            'legalName' => trim((string) ($stored['legalName'] ?? $defaults['legalName'])),
            'registrationNumber' => trim((string) ($stored['registrationNumber'] ?? $defaults['registrationNumber'])),
            'myTaxNumber' => trim((string) ($stored['myTaxNumber'] ?? $defaults['myTaxNumber'])),
            'address' => trim((string) ($stored['address'] ?? $defaults['address'])),
            'email' => trim((string) ($stored['email'] ?? $defaults['email'])),
            'phone' => trim((string) ($stored['phone'] ?? $defaults['phone'])),
            'financeContactName' => trim((string) ($stored['financeContactName'] ?? $defaults['financeContactName'])),
            'financeContactEmail' => trim((string) ($stored['financeContactEmail'] ?? $defaults['financeContactEmail'])),
            'financeContactPhone' => trim((string) ($stored['financeContactPhone'] ?? $defaults['financeContactPhone'])),
        ];

        return $this->payrollCompanyProfile;
    }

    private function resolveSalaryRecordForClaim(PayrollClaim $claim, Collection $salaryRecords): ?SalaryAssignment
    {
        if ($salaryRecords->isEmpty()) {
            return null;
        }

        $periodDate = $this->parsePeriodValueToDate($claim->period_value);
        if ($periodDate instanceof Carbon) {
            $periodEnd = $periodDate->copy()->endOfMonth();
            $byPeriod = $salaryRecords->first(function (SalaryAssignment $record) use ($periodEnd) {
                if (! ($record->effective_from instanceof Carbon)) {
                    return false;
                }

                return $record->effective_from->copy()->startOfDay()->lte($periodEnd);
            });
            if ($byPeriod instanceof SalaryAssignment) {
                return $byPeriod;
            }
        }

        $referenceDate = $claim->submitted_at instanceof Carbon
            ? $claim->submitted_at->copy()->endOfDay()
            : ($claim->updated_at instanceof Carbon ? $claim->updated_at->copy()->endOfDay() : null);
        if ($referenceDate instanceof Carbon) {
            $bySubmittedAt = $salaryRecords->first(function (SalaryAssignment $record) use ($referenceDate) {
                if (! ($record->effective_from instanceof Carbon)) {
                    return false;
                }

                return $record->effective_from->copy()->startOfDay()->lte($referenceDate);
            });
            if ($bySubmittedAt instanceof SalaryAssignment) {
                return $bySubmittedAt;
            }
        }

        $first = $salaryRecords->first();

        return $first instanceof SalaryAssignment ? $first : null;
    }

    private function parsePeriodValueToDate(mixed $value): ?Carbon
    {
        $raw = trim((string) ($value ?? ''));
        if (! preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m', $raw)->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatSalaryRecord(?SalaryAssignment $record): ?array
    {
        if (! $record) {
            return null;
        }

        $allowanceItems = $this->normalizeAllowanceItems($record->allowances ?? []);
        $allowanceTotalFromItems = round((float) collect($allowanceItems)->sum('amount'), 2);
        $allowanceTotal = $allowanceTotalFromItems > 0
            ? $allowanceTotalFromItems
            : round((float) $record->allowance_total, 2);
        $employeeContributions = $this->normalizeContributionMap($record->employee_contributions ?? []);
        $employerContributions = $this->normalizeContributionMap($record->employer_contributions ?? []);

        return [
            'id' => (int) $record->id,
            'referenceId' => trim((string) ($record->reference_id ?? '')),
            'status' => trim((string) ($record->status ?? '')),
            'effectiveFrom' => optional($record->effective_from)->toDateString(),
            'basicSalary' => round((float) $record->basic_salary, 2),
            'allowanceTotal' => $allowanceTotal,
            'allowanceItems' => $allowanceItems,
            'employeeContributions' => $employeeContributions,
            'employerContributions' => $employerContributions,
            'updatedAt' => optional($record->updated_at)->toIso8601String(),
        ];
    }

    private function buildBaselineFromSalaryRecord(?array $record): array
    {
        if (! $record) {
            return [
                'hasData' => false,
                'basicSalary' => null,
                'allowanceTotal' => null,
                'grossSalary' => null,
                'employeeDeductionsTotal' => null,
                'netSalary' => null,
                'allowanceItems' => [],
                'deductionItems' => [],
                'employeeContributions' => [],
                'employerContributions' => [],
            ];
        }

        $basicSalary = round((float) ($record['basicSalary'] ?? 0), 2);
        $allowanceTotal = round((float) ($record['allowanceTotal'] ?? 0), 2);
        $grossSalary = round($basicSalary + $allowanceTotal, 2);
        $employeeContributions = is_array($record['employeeContributions'] ?? null)
            ? $record['employeeContributions']
            : [];
        $employerContributions = is_array($record['employerContributions'] ?? null)
            ? $record['employerContributions']
            : [];
        $employeeDeductionsTotal = round((float) collect($employeeContributions)->sum(), 2);
        $deductionItems = $this->contributionMapToItems($employeeContributions);
        $allowanceItems = is_array($record['allowanceItems'] ?? null) ? $record['allowanceItems'] : [];

        return [
            'hasData' => true,
            'basicSalary' => $basicSalary,
            'allowanceTotal' => $allowanceTotal,
            'grossSalary' => $grossSalary,
            'employeeDeductionsTotal' => $employeeDeductionsTotal,
            'netSalary' => round($grossSalary - $employeeDeductionsTotal, 2),
            'allowanceItems' => $allowanceItems,
            'deductionItems' => $deductionItems,
            'employeeContributions' => $employeeContributions,
            'employerContributions' => $employerContributions,
        ];
    }

    private function buildBaselineFromSnapshot(array $snapshot): array
    {
        $basicSalary = $this->asMoneyOrNull($snapshot['basic'] ?? $snapshot['basicSalary'] ?? null);
        $allowanceTotal = $this->asMoneyOrNull($snapshot['allowance'] ?? $snapshot['allowanceTotal'] ?? null);
        $grossSalary = $this->asMoneyOrNull($snapshot['gross'] ?? $snapshot['grossSalary'] ?? null);
        $employeeDeductionsTotal = $this->asMoneyOrNull(
            $snapshot['totalDeductions'] ?? $snapshot['employeeDeductionsTotal'] ?? null,
        );
        $netSalary = $this->asMoneyOrNull($snapshot['net'] ?? $snapshot['netSalary'] ?? null);

        $allowanceItems = $this->normalizeSnapshotAmountItems($snapshot['allowanceItems'] ?? []);
        $deductionItems = $this->normalizeSnapshotAmountItems($snapshot['deductionItems'] ?? []);
        $employeeContributions = $this->normalizeContributionMap(
            $snapshot['employeeContributions'] ?? $snapshot['employee_contributions'] ?? [],
        );
        $employerContributions = $this->normalizeContributionMap(
            $snapshot['employerContributions'] ?? $snapshot['employer_contributions'] ?? [],
        );
        $hasData = collect([$basicSalary, $allowanceTotal, $grossSalary, $employeeDeductionsTotal, $netSalary])
            ->contains(fn ($value) => $value !== null);

        return [
            'hasData' => $hasData,
            'basicSalary' => $basicSalary,
            'allowanceTotal' => $allowanceTotal,
            'grossSalary' => $grossSalary,
            'employeeDeductionsTotal' => $employeeDeductionsTotal,
            'netSalary' => $netSalary,
            'allowanceItems' => $allowanceItems,
            'deductionItems' => $deductionItems,
            'employeeContributions' => $employeeContributions,
            'employerContributions' => $employerContributions,
        ];
    }

    private function normalizeSnapshotAmountItems(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(function ($entry, int $index) {
                if (! is_array($entry)) {
                    return null;
                }
                $amount = round((float) ($entry['amount'] ?? 0), 2);

                return [
                    'key' => trim((string) ($entry['key'] ?? "item-{$index}")),
                    'label' => trim((string) ($entry['label'] ?? 'Item')),
                    'amount' => $amount,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeAllowanceItems(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(function ($entry, int $index) {
                if (! is_array($entry)) {
                    return null;
                }
                $amount = round((float) ($entry['amount'] ?? 0), 2);
                if ($amount === 0.0) {
                    return null;
                }
                $name = trim((string) ($entry['name'] ?? 'Allowance '.($index + 1)));

                return [
                    'key' => "allowance-{$index}",
                    'label' => $name !== '' ? $name : 'Allowance '.($index + 1),
                    'amount' => $amount,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeContributionMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $amountRaw) {
            $label = trim((string) $key);
            if ($label === '') {
                continue;
            }
            $amount = round((float) $amountRaw, 2);
            if ($amount === 0.0) {
                continue;
            }
            $normalized[$label] = $amount;
        }

        return $normalized;
    }

    private function contributionMapToItems(array $contributions): array
    {
        return collect($contributions)
            ->map(function ($amount, $key) {
                $label = trim((string) $key);
                if ($label === '') {
                    return null;
                }

                return [
                    'key' => strtolower($label),
                    'label' => strtoupper($label) === 'PERKESO' ? 'PERKESO (SOCSO)' : $this->toTitleLabel($label),
                    'amount' => round((float) $amount, 2),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function toTitleLabel(string $value): string
    {
        $normalized = str_replace(['_', '-'], ' ', trim($value));
        if ($normalized === '') {
            return '';
        }

        return collect(explode(' ', $normalized))
            ->filter(fn ($segment) => trim($segment) !== '')
            ->map(fn ($segment) => ucfirst(strtolower($segment)))
            ->implode(' ');
    }

    private function formatAdjustments(PayrollClaim $claim): array
    {
        $items = $claim->relationLoaded('items')
            ? $claim->items
            : PayrollClaimItem::query()->where('payroll_claim_id', $claim->id)->orderBy('line_no')->get();

        return $items->map(function (PayrollClaimItem $item) {
            $rawType = trim((string) ($item->item_type ?? ''));
            $rawAmount = round((float) $item->amount, 2);
            $normalizedType = strtolower($rawType);
            $isDeductionByType = in_array($normalizedType, ['deduction', 'deduct', 'minus'], true);
            $isDeduction = $isDeductionByType || $rawAmount < 0;
            $amount = round(abs($rawAmount), 2);
            $signedAmount = round($isDeduction ? -$amount : $amount, 2);

            return [
                'lineNo' => (int) $item->line_no,
                'itemType' => $rawType !== '' ? $rawType : ($isDeduction ? 'Deduction' : 'Addition'),
                'title' => trim((string) ($item->title ?? '')),
                'claimDate' => optional($item->claim_date)->toDateString(),
                'amount' => $amount,
                'direction' => $isDeduction ? 'deduction' : 'addition',
                'signedAmount' => $signedAmount,
                'notes' => trim((string) ($item->notes ?? '')),
            ];
        })->values()->all();
    }

    private function formatOvertime(PayrollClaim $claim): array
    {
        $rows = is_array($claim->overtime_rows) ? $claim->overtime_rows : [];
        $normalizedRows = collect($rows)
            ->map(function ($entry) {
                if (! is_array($entry)) {
                    return null;
                }

                return [
                    'overtimeId' => trim((string) ($entry['overtimeId'] ?? '')),
                    'claimDate' => trim((string) ($entry['claimDate'] ?? '')),
                    'overtimeType' => trim((string) ($entry['overtimeType'] ?? '')),
                    'hours' => round((float) ($entry['hours'] ?? 0), 2),
                    'status' => trim((string) ($entry['status'] ?? '')),
                    'isApproved' => (bool) ($entry['isApproved'] ?? false),
                    'multiplierUsed' => round((float) ($entry['multiplierUsed'] ?? 0), 2),
                    'hourlyBaseRateUsed' => $this->asMoneyOrNull($entry['hourlyBaseRateUsed'] ?? null),
                    'payoutUsed' => round((float) ($entry['payoutUsed'] ?? 0), 2),
                ];
            })
            ->filter()
            ->values();

        $approvedRows = $normalizedRows->filter(fn ($entry) => ($entry['isApproved'] ?? false) === true);

        return [
            'rows' => $normalizedRows->all(),
            'rowCount' => $normalizedRows->count(),
            'approvedCount' => $approvedRows->count(),
            'totalHours' => round((float) $normalizedRows->sum('hours'), 2),
            'approvedHours' => round((float) $approvedRows->sum('hours'), 2),
            'approvedPayout' => round((float) $claim->approved_overtime_payout, 2),
            'calculatedApprovedPayout' => round((float) $approvedRows->sum('payoutUsed'), 2),
        ];
    }

    private function asMoneyOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function pickMoney(?float $primary, ?float $fallback): float
    {
        if ($primary !== null) {
            return round($primary, 2);
        }
        if ($fallback !== null) {
            return round($fallback, 2);
        }

        return 0.0;
    }

    private function resolveIssuedAt(PayrollClaim $claim): ?Carbon
    {
        $history = is_array($claim->approval_history) ? $claim->approval_history : [];
        $timestamps = collect($history)
            ->filter(fn ($entry) => is_array($entry))
            ->filter(function (array $entry) {
                $action = strtolower(trim((string) ($entry['action'] ?? '')));

                return in_array($action, ['approved', 'paid'], true);
            })
            ->pluck('at')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->reverse()
            ->values();

        foreach ($timestamps as $value) {
            try {
                return Carbon::parse((string) $value);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function formatMonthLabel(PayrollClaim $claim): string
    {
        $periodValue = trim((string) ($claim->period_value ?? ''));
        if (preg_match('/^\d{4}-\d{2}$/', $periodValue)) {
            try {
                return Carbon::createFromFormat('Y-m', $periodValue)->startOfMonth()->format('F Y');
            } catch (\Throwable) {
                // fall through to period label
            }
        }

        $periodLabel = trim((string) ($claim->period ?? ''));

        return $periodLabel !== '' ? $periodLabel : '-';
    }

    private function buildDownloadFilename(PayrollClaim $claim): string
    {
        $periodValue = trim((string) ($claim->period_value ?? ''));
        $monthToken = '';
        if (preg_match('/^\d{4}-\d{2}$/', $periodValue)) {
            try {
                $monthToken = strtolower(str_replace(' ', '', Carbon::createFromFormat('Y-m', $periodValue)->startOfMonth()->format('F Y')));
            } catch (\Throwable) {
                $monthToken = '';
            }
        }
        if ($monthToken === '') {
            $monthToken = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', (string) ($claim->period ?? '')));
        }

        $employeeRaw = trim((string) ($claim->user?->name ?? $claim->submitted_by_name ?? 'employee'));
        $employeeToken = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $employeeRaw), '-'));
        if ($employeeToken === '') {
            $employeeToken = 'employee-'.$claim->user_id;
        }

        $base = trim("payslip-{$monthToken}_{$employeeToken}", '-_');
        if ($base === '') {
            $base = 'payslip-'.$claim->id;
        }

        return $base.'.pdf';
    }
}
