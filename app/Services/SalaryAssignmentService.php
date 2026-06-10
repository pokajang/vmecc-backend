<?php

namespace App\Services;

use App\Models\SalaryAssignment;
use App\Models\SalaryAssignmentHistory;
use Illuminate\Support\Arr;

class SalaryAssignmentService
{
    public function normalizePayload(array $payload): array
    {
        $allowances = collect(Arr::get($payload, 'allowances', []))
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row, int $index) => [
                'id' => trim((string) ($row['id'] ?? '')) ?: 'allow-' . ($index + 1),
                'name' => trim((string) ($row['name'] ?? '')),
                'amount' => $this->toAmount($row['amount'] ?? 0),
            ])
            ->values()
            ->all();

        $referenceId = trim((string) ($payload['reference_id'] ?? ''));

        return [
            'reference_id' => $referenceId !== '' ? $referenceId : null,
            'employee_user_id' => (int) ($payload['employee_user_id'] ?? 0),
            'status' => trim((string) ($payload['status'] ?? 'Active')) ?: 'Active',
            'effective_from' => $payload['effective_from'] ?? null,
            'basic_salary' => $this->toAmount($payload['basic_salary'] ?? 0),
            'allowance_total' => round((float) collect($allowances)->sum('amount'), 2),
            'allowances' => $allowances,
            'employee_contributions' => is_array($payload['employee_contributions'] ?? null)
                ? $payload['employee_contributions']
                : ['epf' => 0, 'perkeso' => 0, 'sip' => 0],
            'employer_contributions' => is_array($payload['employer_contributions'] ?? null)
                ? $payload['employer_contributions']
                : ['epf' => 0, 'perkeso' => 0, 'sip' => 0],
            'notes_history' => is_array($payload['notes_history'] ?? null) ? $payload['notes_history'] : [],
            'updated_by' => trim((string) ($payload['updated_by'] ?? '')),
        ];
    }

    public function writeHistory(SalaryAssignment $assignment, string $eventType, array $before, array $after, string $actorName): SalaryAssignmentHistory
    {
        return SalaryAssignmentHistory::create([
            'salary_assignment_id' => $assignment->id,
            'event_type' => $eventType,
            'before_data' => $before,
            'after_data' => $after,
            'actor_name' => $actorName,
            'occurred_at' => now(),
        ]);
    }

    private function toAmount(mixed $value): float
    {
        return round(is_numeric($value) ? (float) $value : 0.0, 2);
    }
}
