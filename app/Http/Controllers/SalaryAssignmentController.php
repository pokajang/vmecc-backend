<?php

namespace App\Http\Controllers;

use App\Models\PayrollClaim;
use App\Models\SalaryAssignment;
use App\Models\SalaryAssignmentHistory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SalaryAssignmentService;
use App\Services\WorkflowNotificationService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalaryAssignmentController extends Controller
{
    private const NOTIFICATION_ROLE_TARGETS = ['Salary Manager'];

    public function __construct(
        private readonly SalaryAssignmentService $assignmentService,
        private readonly WorkflowNotificationService $notificationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = SalaryAssignment::query()->with(['employee'])->orderByDesc('effective_from')->orderByDesc('id');

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('employee_user_id')) {
            $query->where('employee_user_id', (int) $request->input('employee_user_id'));
        }

        $rows = $query->get()->map(fn (SalaryAssignment $row) => $this->formatRow($row));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        $payload = $this->validatePayload($request);
        $normalized = $this->assignmentService->normalizePayload(array_merge($payload, [
            'updated_by' => (string) ($actor->name ?? ''),
        ]));

        [$row, $history] = DB::transaction(function () use ($normalized, $actor) {
            $this->lockEmployeesForAssignmentWrites([(int) $normalized['employee_user_id']]);
            $this->assertEffectiveDateAvailable(
                (int) $normalized['employee_user_id'],
                (string) $normalized['effective_from'],
            );
            $row = SalaryAssignment::query()->create(array_merge($normalized, ['version' => 1]));
            $history = $this->assignmentService->writeHistory(
                $row,
                'created',
                [],
                $row->fresh()->load(['employee'])->toArray(),
                (string) ($actor->name ?? '')
            );
            $row->load(['employee']);
            $this->emitAssignmentNotification($row, $actor, 'set_salary');

            return [$row, $history];
        });

        AuditLogger::log($request, 'salary_assignment_created', $actor, ['assignment_id' => $row->id]);

        return response()->json([
            'data' => $this->formatRow($row),
            'history' => $this->formatHistoryRow($history),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $payload = $this->validatePayload($request);
        $normalized = $this->assignmentService->normalizePayload(array_merge($payload, [
            'updated_by' => (string) ($actor->name ?? ''),
        ]));
        unset($normalized['reference_id']);

        [$row, $history] = DB::transaction(function () use ($id, $payload, $normalized, $actor) {
            $employeeUserId = (int) SalaryAssignment::query()
                ->whereKey($id)
                ->value('employee_user_id');
            if ($employeeUserId <= 0) {
                abort(404);
            }
            $this->lockEmployeesForAssignmentWrites([$employeeUserId]);

            $row = SalaryAssignment::query()->with(['employee'])->lockForUpdate()->findOrFail($id);
            $this->assertExpectedVersion($row, (int) $payload['expected_version']);
            if ((int) $normalized['employee_user_id'] !== (int) $row->employee_user_id) {
                throw ValidationException::withMessages([
                    'employee_user_id' => [
                        'The employee on a salary assignment cannot be changed. Create a new assignment for the correct employee.',
                    ],
                ]);
            }
            if (PayrollClaim::query()->where('salary_assignment_id', $row->id)->exists()) {
                throw ValidationException::withMessages([
                    'assignment' => [
                        'This assignment is referenced by a payroll claim and is immutable. Create a new effective-dated assignment instead.',
                    ],
                ]);
            }
            $before = $row->toArray();
            $this->assertEffectiveDateAvailable(
                (int) $normalized['employee_user_id'],
                (string) $normalized['effective_from'],
                (int) $row->id,
            );
            $row->update(array_merge($normalized, [
                'effective_date_key' => $normalized['effective_from'],
                'version' => ((int) $row->version) + 1,
            ]));
            $history = $this->assignmentService->writeHistory(
                $row,
                'updated',
                $before,
                $row->fresh()->load(['employee'])->toArray(),
                (string) ($actor->name ?? '')
            );
            $row->refresh()->load(['employee']);
            $this->emitAssignmentNotification($row, $actor, 'updated_salary');

            return [$row, $history];
        });

        AuditLogger::log($request, 'salary_assignment_updated', $actor, ['assignment_id' => $row->id]);

        return response()->json([
            'data' => $this->formatRow($row),
            'history' => $this->formatHistoryRow($history),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $expectedVersion = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
        ])['expected_version'];
        [$row, $history] = DB::transaction(function () use ($id, $expectedVersion, $actor) {
            $employeeUserId = (int) SalaryAssignment::query()
                ->whereKey($id)
                ->value('employee_user_id');
            if ($employeeUserId <= 0) {
                abort(404);
            }
            $this->lockEmployeesForAssignmentWrites([$employeeUserId]);

            $row = SalaryAssignment::query()->with(['employee'])->lockForUpdate()->findOrFail($id);
            $this->assertExpectedVersion($row, (int) $expectedVersion);
            if (PayrollClaim::query()->where('salary_assignment_id', $row->id)->exists()) {
                throw ValidationException::withMessages([
                    'assignment' => [
                        'This assignment is referenced by a payroll claim and cannot be deleted. Publish a replacement assignment instead.',
                    ],
                ]);
            }
            $before = $row->toArray();
            $history = $this->assignmentService->writeHistory(
                $row,
                'deleted',
                $before,
                [],
                (string) ($actor->name ?? '')
            );
            $row->update(['effective_date_key' => null]);
            $row->delete();
            $this->emitAssignmentNotification($row, $actor, 'deleted_salary');

            return [$row, $history];
        });

        AuditLogger::log($request, 'salary_assignment_deleted', $actor, ['assignment_id' => $row->id]);

        return response()->json([
            'history' => $this->formatHistoryRow($history),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'assignment_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $limit = (int) ($validated['limit'] ?? 25);
        $query = SalaryAssignmentHistory::query()->orderByDesc('occurred_at')->orderByDesc('id');
        $assignmentId = (int) ($validated['assignment_id'] ?? 0);
        if ($assignmentId > 0) {
            $query->where('salary_assignment_id', $assignmentId);
        }

        $rows = $query->limit($limit)->get()->map(fn (SalaryAssignmentHistory $row) => $this->formatHistoryRow($row));

        return response()->json([
            'data' => $rows,
            'meta' => ['limit' => $limit],
        ]);
    }

    private function validatePayload(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'reference_id' => ['nullable', 'string', 'max:50'],
            'employee_user_id' => ['required', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::in(['Active', 'Scheduled'])],
            'effective_from' => ['required', 'date'],
            'basic_salary' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'allowances' => ['nullable', 'array', 'max:50'],
            'allowances.*.name' => ['nullable', 'string', 'max:120'],
            'allowances.*.amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'employee_contributions' => ['nullable', 'array'],
            'employee_contributions.epf' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'employee_contributions.perkeso' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'employee_contributions.sip' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'employer_contributions' => ['nullable', 'array'],
            'employer_contributions.epf' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'employer_contributions.perkeso' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'employer_contributions.sip' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'notes_history' => ['nullable', 'array', 'max:50'],
            'notes_history.*.id' => ['nullable', 'string', 'max:120'],
            'notes_history.*.text' => ['nullable', 'string', 'max:2000'],
            'notes_history.*.createdAt' => ['nullable', 'string', 'max:40'],
            'notes_history.*.createdBy' => ['nullable', 'string', 'max:120'],
            'notes_history.*.updatedAt' => ['nullable', 'string', 'max:40'],
            'notes_history.*.updatedBy' => ['nullable', 'string', 'max:120'],
            'expected_version' => [
                $request->isMethod('put') || $request->isMethod('patch') ? 'required' : 'nullable',
                'integer',
                'min:1',
            ],
        ]);

        $validator->after(function ($validator) {
            $data = $validator->getData();
            $allowances = $data['allowances'] ?? [];
            if (! is_array($allowances)) {
                return;
            }
            foreach ($allowances as $index => $allowance) {
                if (! is_array($allowance)) {
                    continue;
                }
                $amount = is_numeric($allowance['amount'] ?? null) ? (float) $allowance['amount'] : 0.0;
                $name = trim((string) ($allowance['name'] ?? ''));
                if ($amount > 0 && $name === '') {
                    $validator->errors()->add("allowances.$index.name", 'Allowance name is required when amount is greater than zero.');
                }
            }
            $gross = (float) ($data['basic_salary'] ?? 0)
                + (float) collect($allowances)->sum(
                    fn ($allowance) => is_array($allowance) && is_numeric($allowance['amount'] ?? null)
                        ? (float) $allowance['amount']
                        : 0,
                );
            $employeeContributions = is_array($data['employee_contributions'] ?? null)
                ? $data['employee_contributions']
                : [];
            $deductions = collect(['epf', 'perkeso', 'sip'])->sum(
                fn (string $key) => is_numeric($employeeContributions[$key] ?? null)
                    ? (float) $employeeContributions[$key]
                    : 0,
            );
            if ($deductions > $gross) {
                $validator->errors()->add(
                    'employee_contributions',
                    'Employee deductions must not exceed gross salary.',
                );
            }
        });

        return $validator->validate();
    }

    private function formatRow(SalaryAssignment $row): array
    {
        return [
            'id' => $row->id,
            'public_id' => $row->public_id,
            'reference_id' => $row->reference_id,
            'employee_user_id' => $row->employee_user_id,
            'employee' => $row->employee?->name ?? '',
            'email' => $row->employee?->email ?? '',
            'team' => $row->employee?->team ?? '',
            'status' => $row->status,
            'effective_from' => optional($row->effective_from)->toDateString(),
            'basic_salary' => (float) $row->basic_salary,
            'allowance_total' => (float) $row->allowance_total,
            'allowances' => $row->allowances ?? [],
            'employee_contributions' => $row->employee_contributions ?? [],
            'employer_contributions' => $row->employer_contributions ?? [],
            'notes_history' => $row->notes_history ?? [],
            'updated_by' => $row->updated_by,
            'created_at' => optional($row->created_at)->toIso8601String(),
            'updated_at' => optional($row->updated_at)->toIso8601String(),
            'version' => (int) $row->version,
        ];
    }

    private function assertEffectiveDateAvailable(
        int $employeeUserId,
        string $effectiveFrom,
        ?int $ignoreAssignmentId = null,
    ): void {
        $query = SalaryAssignment::query()
            ->where('employee_user_id', $employeeUserId)
            ->whereDate('effective_from', $effectiveFrom)
            ->lockForUpdate();
        if ($ignoreAssignmentId) {
            $query->whereKeyNot($ignoreAssignmentId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'effective_from' => ['An assignment already exists for this employee and effective date.'],
            ]);
        }
    }

    private function lockEmployeesForAssignmentWrites(array $employeeUserIds): void
    {
        $ids = collect($employeeUserIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        User::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    private function assertExpectedVersion(SalaryAssignment $assignment, int $expectedVersion): void
    {
        if ($expectedVersion === (int) $assignment->version) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'code' => 'SALARY_ASSIGNMENT_VERSION_CONFLICT',
            'message' => 'This salary assignment changed. Reload it before trying again.',
            'currentVersion' => (int) $assignment->version,
        ], 409));
    }

    private function emitAssignmentNotification(SalaryAssignment $row, $actor, string $eventType): void
    {
        $ownerUserId = (int) ($row->employee_user_id ?? 0);
        if ($ownerUserId <= 0) {
            return;
        }

        $this->notificationService->emit(
            module: 'salary',
            eventType: $eventType,
            recordType: 'salary_assignment',
            recordId: (int) $row->id,
            recordDisplayId: (string) ($row->reference_id ?: $row->id),
            ownerUserId: $ownerUserId,
            actor: [
                'userId' => $actor?->id ?? null,
                'name' => $actor?->name ?? '',
                'email' => $actor?->email ?? '',
            ],
            targetUserIds: [$ownerUserId],
            targetRoles: self::NOTIFICATION_ROLE_TARGETS,
            actionRequired: false,
            metadata: [
                'module' => 'salary',
                'status' => (string) ($row->status ?? 'Active'),
                'workflowStage' => null,
                'nextActionRole' => self::NOTIFICATION_ROLE_TARGETS[0],
                'detailRouteKey' => (string) ($row->public_id ?: $row->id),
            ],
        );
    }

    private function formatHistoryRow(SalaryAssignmentHistory $row): array
    {
        $beforeData = is_array($row->before_data) ? $row->before_data : [];
        $afterData = is_array($row->after_data) ? $row->after_data : [];
        $snapshot = ! empty($afterData) ? $afterData : $beforeData;
        $employeeContributions = is_array($snapshot['employee_contributions'] ?? null)
            ? $snapshot['employee_contributions']
            : [];
        $allowances = is_array($snapshot['allowances'] ?? null) ? $snapshot['allowances'] : [];
        $basicSalary = round((float) ($snapshot['basic_salary'] ?? 0), 2);
        $allowanceTotal = round((float) ($snapshot['allowance_total'] ?? 0), 2);
        $epf = round((float) ($employeeContributions['epf'] ?? 0), 2);
        $perkeso = round((float) ($employeeContributions['perkeso'] ?? 0), 2);
        $sip = round((float) ($employeeContributions['sip'] ?? 0), 2);
        $totalDeductions = round($epf + $perkeso + $sip, 2);
        $netPayable = round($basicSalary + $allowanceTotal - $totalDeductions, 2);
        $effectiveFrom = trim((string) ($snapshot['effective_from'] ?? ''));
        $eventType = strtolower(trim((string) ($row->event_type ?? 'updated')));
        $humanType = match ($eventType) {
            'created' => 'Created',
            'updated' => 'Updated',
            'deleted' => 'Deleted',
            default => ucfirst($eventType ?: 'Updated'),
        };

        return [
            'id' => (string) $row->id,
            'assignmentId' => trim((string) ($snapshot['id'] ?? '')),
            'at' => optional($row->occurred_at)->toIso8601String() ?? optional($row->created_at)->toIso8601String(),
            'by' => trim((string) ($row->actor_name ?? '')),
            'employee' => trim((string) ($snapshot['employee']['name'] ?? $snapshot['employee'] ?? '')),
            'eventType' => $humanType,
            'summary' => sprintf(
                '%s | Basic RM %s | Allowance RM %s',
                $effectiveFrom !== '' ? $effectiveFrom : '-',
                number_format($basicSalary, 2, '.', ''),
                number_format($allowanceTotal, 2, '.', '')
            ),
            'details' => [
                'basicSalary' => $basicSalary,
                'allowances' => $allowances,
                'allowanceTotal' => $allowanceTotal,
                'epf' => $epf,
                'perkeso' => $perkeso,
                'sip' => $sip,
                'totalDeductions' => $totalDeductions,
                'netPayable' => $netPayable,
                'effectiveFrom' => $effectiveFrom,
                'status' => trim((string) ($snapshot['status'] ?? '')),
            ],
        ];
    }
}
