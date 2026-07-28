<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->backfill('overtime_records', 'claim_date', 'start_time');
        $this->backfill('leaves', 'start_date', null);
    }

    public function down(): void
    {
        // Snapshot backfills are intentionally retained until the context columns are removed.
    }

    private function backfill(string $table, string $dateColumn, ?string $timeColumn): void
    {
        DB::table($table)
            ->whereNull('workflow_routing_source')
            ->orderBy('id')
            ->chunkById(100, function ($records) use ($table, $dateColumn, $timeColumn): void {
                foreach ($records as $record) {
                    $effectiveAt = $this->effectiveAt($record, $dateColumn, $timeColumn);
                    $snapshot = $this->jsonObject($record->workflow_snapshot ?? null);
                    $applicantRoles = $this->jsonList($record->applicant_roles ?? null);
                    $requestedRole = trim((string) ($snapshot['applicantRole'] ?? ''));
                    if ($requestedRole === '' || strtolower($requestedRole) === 'fallback') {
                        $requestedRole = trim((string) ($applicantRoles[0] ?? ''));
                    }

                    $context = $this->coverageContext((int) $record->user_id, $effectiveAt, $requestedRole)
                        ?? $this->assignmentContext((int) $record->user_id, $effectiveAt, $requestedRole)
                        ?? $this->legacyContext((int) $record->user_id, $requestedRole);

                    $snapshot['workflowContext'] = $context;
                    DB::table($table)->where('id', $record->id)->update([
                        'workflow_team_id' => $context['teamId'],
                        'workflow_team_name' => $context['teamName'],
                        'workflow_applicant_role' => $context['applicantRole'],
                        'workflow_routing_source' => $context['routingSource'],
                        'duty_coverage_assignment_id' => $context['dutyCoverageAssignmentId'],
                        'workflow_snapshot' => json_encode($snapshot, JSON_UNESCAPED_SLASHES),
                    ]);
                }
            });
    }

    private function effectiveAt(object $record, string $dateColumn, ?string $timeColumn): Carbon
    {
        $date = trim((string) ($record->{$dateColumn} ?? '')) ?: now()->toDateString();
        if ($timeColumn !== null) {
            $time = substr(trim((string) ($record->{$timeColumn} ?? '00:00')), 0, 5) ?: '00:00';

            return Carbon::parse("{$date} {$time}");
        }

        $shift = strtolower(trim((string) ($record->work_shift ?? 'normal')));

        return Carbon::parse($date)->setTime(str_contains($shift, 'night') ? 20 : 8, 0);
    }

    private function coverageContext(int $userId, Carbon $effectiveAt, string $role): ?array
    {
        $query = DB::table('duty_coverage_assignments as coverage')
            ->join('roles as role', 'role.id', '=', 'coverage.acting_role_id')
            ->join('teams as team', 'team.id', '=', 'coverage.acting_team_id')
            ->where('coverage.user_id', $userId)
            ->whereNull('coverage.cancelled_at')
            ->where('coverage.effective_from', '<=', $effectiveAt)
            ->where('coverage.effective_until', '>', $effectiveAt);
        if ($role !== '') {
            $query->whereRaw('LOWER(TRIM(role.name)) = ?', [strtolower($role)]);
        }
        $coverage = $query
            ->orderByDesc('coverage.effective_from')
            ->orderByDesc('coverage.id')
            ->first([
                'coverage.id',
                'coverage.acting_team_id',
                'team.name as team_name',
                'role.name as role_name',
            ]);

        if (! $coverage && $role !== '') {
            return $this->coverageContext($userId, $effectiveAt, '');
        }
        if (! $coverage) {
            return null;
        }

        return [
            'teamId' => (int) $coverage->acting_team_id,
            'teamName' => (string) $coverage->team_name,
            'applicantRole' => (string) $coverage->role_name,
            'routingSource' => 'temporary_coverage',
            'dutyCoverageAssignmentId' => (int) $coverage->id,
        ];
    }

    private function assignmentContext(int $userId, Carbon $effectiveAt, string $role): ?array
    {
        $date = $effectiveAt->toDateString();
        $query = DB::table('user_role_assignments as assignment')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->join('teams as team', 'team.id', '=', 'assignment.team_id')
            ->where('assignment.user_id', $userId)
            ->whereNotNull('assignment.team_id')
            ->where(fn ($builder) => $builder
                ->whereNull('assignment.start_date')
                ->orWhere('assignment.start_date', '<=', $date))
            ->where(fn ($builder) => $builder
                ->whereNull('assignment.end_date')
                ->orWhere('assignment.end_date', '>=', $date));
        if ($role !== '') {
            $query->whereRaw('LOWER(TRIM(role.name)) = ?', [strtolower($role)]);
        }
        $assignment = $query
            ->orderByDesc('assignment.is_primary')
            ->orderByDesc('assignment.id')
            ->first([
                'assignment.team_id',
                'team.name as team_name',
                'role.name as role_name',
            ]);

        if (! $assignment && $role !== '') {
            return $this->assignmentContext($userId, $effectiveAt, '');
        }
        if (! $assignment) {
            return null;
        }

        return [
            'teamId' => (int) $assignment->team_id,
            'teamName' => (string) $assignment->team_name,
            'applicantRole' => (string) $assignment->role_name,
            'routingSource' => 'role_assignment',
            'dutyCoverageAssignmentId' => null,
        ];
    }

    private function legacyContext(int $userId, string $role): array
    {
        $user = DB::table('users')->where('id', $userId)->first(['team']);
        $teamName = trim((string) ($user->team ?? ''));
        $team = $teamName !== ''
            ? DB::table('teams')->where('name', $teamName)->first(['id', 'name'])
            : null;

        return [
            'teamId' => $team ? (int) $team->id : null,
            'teamName' => (string) ($team->name ?? ''),
            'applicantRole' => $role !== '' ? $role : null,
            'routingSource' => $team ? 'legacy_team' : 'organization',
            'dutyCoverageAssignmentId' => null,
        ];
    }

    private function jsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function jsonList(mixed $value): array
    {
        $decoded = $this->jsonObject($value);

        return array_is_list($decoded) ? $decoded : [];
    }
};
