<?php

namespace App\Services;

use App\Models\SalaryAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class PayrollSalaryBaselineService
{
    public const CALCULATION_ENGINE_VERSION = 'payroll-v2';

    public function resolve(User $employee, string $periodValue): array
    {
        $period = $this->parsePeriod($periodValue);
        $assignment = SalaryAssignment::query()
            ->where('employee_user_id', $employee->id)
            ->whereIn('status', ['Active', 'Scheduled'])
            ->whereDate('effective_from', '<=', $period->endOfMonth()->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if (! $assignment instanceof SalaryAssignment) {
            throw ValidationException::withMessages([
                'period_value' => ['No salary assignment is effective for the selected payroll period.'],
            ]);
        }

        $allowances = collect(is_array($assignment->allowances) ? $assignment->allowances : [])
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => [
                'id' => trim((string) ($row['id'] ?? '')),
                'name' => trim((string) ($row['name'] ?? '')),
                'amount' => $this->amount($row['amount'] ?? 0),
            ])
            ->values()
            ->all();
        $allowanceTotal = $this->amount(collect($allowances)->sum('amount'));
        $employeeContributions = $this->contributions($assignment->employee_contributions);
        $employerContributions = $this->contributions($assignment->employer_contributions);
        $basic = $this->amount($assignment->basic_salary);
        $employeeDeductionTotal = $this->amount(array_sum($employeeContributions));
        $gross = $this->amount($basic + $allowanceTotal);
        if ($employeeDeductionTotal > $gross) {
            throw ValidationException::withMessages([
                'salary_assignment' => [
                    'Employee deductions exceed gross salary for the selected payroll period.',
                ],
            ]);
        }
        $net = $this->amount($gross - $employeeDeductionTotal);

        return [
            'calculationEngineVersion' => self::CALCULATION_ENGINE_VERSION,
            'salaryAssignmentPublicId' => (string) ($assignment->public_id ?: $assignment->id),
            'salaryAssignmentId' => (int) $assignment->id,
            'salaryAssignmentVersion' => (int) ($assignment->version ?: 1),
            'period' => $period->format('Y-m'),
            'effectiveFrom' => optional($assignment->effective_from)->toDateString(),
            'calculatedAt' => now()->toIso8601String(),
            'basic' => $basic,
            'allowances' => $allowances,
            'allowanceTotal' => $allowanceTotal,
            'employeeContributions' => $employeeContributions,
            'employerContributions' => $employerContributions,
            'employeeDeductionTotal' => $employeeDeductionTotal,
            'gross' => $gross,
            'net' => $net,
            'hasConfiguredBaseline' => true,
            'serverAuthoritative' => true,
        ];
    }

    private function parsePeriod(string $periodValue): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $periodValue)) {
            throw ValidationException::withMessages([
                'period_value' => ['Payroll period must use YYYY-MM format.'],
            ]);
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m', $periodValue)->startOfMonth();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'period_value' => ['Payroll period is invalid.'],
            ]);
        }
    }

    private function contributions(mixed $value): array
    {
        $source = is_array($value) ? $value : [];

        return [
            'epf' => $this->amount($source['epf'] ?? 0),
            'perkeso' => $this->amount($source['perkeso'] ?? 0),
            'sip' => $this->amount($source['sip'] ?? 0),
        ];
    }

    private function amount(mixed $value): float
    {
        return max(0.0, round(is_numeric($value) ? (float) $value : 0.0, 2));
    }
}
