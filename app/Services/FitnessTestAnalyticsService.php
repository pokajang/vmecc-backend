<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class FitnessTestAnalyticsService
{
    private const MINIMUM_COHORT_SIZE = 3;

    public function filters(array $input): array
    {
        $data = [
            'monthFrom' => $input['monthFrom'] ?? $input['month_from'] ?? Carbon::now()->startOfMonth()->subMonths(5)->format('Y-m'),
            'monthTo' => $input['monthTo'] ?? $input['month_to'] ?? Carbon::now()->startOfMonth()->format('Y-m'),
            'teamId' => $input['teamId'] ?? $input['team_id'] ?? null,
            'shift' => $input['shift'] ?? null,
            'protocolRevision' => $input['protocolRevision'] ?? $input['protocol_revision'] ?? null,
            'fitnessResult' => $input['fitnessResult'] ?? $input['fitness_result'] ?? null,
            'proficiencyResult' => $input['proficiencyResult'] ?? $input['proficiency_result'] ?? null,
            'ageBand' => $input['ageBand'] ?? $input['age_band'] ?? null,
            'approvalStatus' => $input['approvalStatus'] ?? $input['approval_status'] ?? 'approved',
        ];
        Validator::make($data, [
            'monthFrom' => ['required', 'date_format:Y-m'], 'monthTo' => ['required', 'date_format:Y-m'],
            'teamId' => ['nullable', 'integer', 'min:1'], 'shift' => ['nullable', 'string', 'max:190'],
            'protocolRevision' => ['nullable', 'string', 'max:64'],
            'fitnessResult' => ['nullable', 'in:passed,failed,incomplete'],
            'proficiencyResult' => ['nullable', 'in:passed,failed,incomplete'],
            'ageBand' => ['nullable', 'regex:/^\d{1,3}-\d{1,3}$/'],
            'approvalStatus' => ['nullable', 'in:approved,submitted,reviewed,rejected,draft'],
        ])->validate();
        if ($data['monthFrom'] > $data['monthTo']) {
            abort(422, 'monthFrom must not be after monthTo.');
        }

        return $data;
    }

    public function stats(array $filters): array
    {
        return [
            'filters' => $filters,
            'summary' => $this->summary($this->query($filters)),
            'trends' => $this->trends($filters),
            'overduePersonnel' => $this->overdueCount($filters),
            'minimumCohortSize' => self::MINIMUM_COHORT_SIZE,
        ];
    }

    public function trends(array $filters): array
    {
        return [
            'monthly' => $this->grouped($filters, 'ftr.reporting_month', 'reportingMonth'),
            'team' => $this->grouped($filters, 'COALESCE(t.name, ftg.team_id)', 'team'),
            'shift' => $this->grouped($filters, 'COALESCE(ftg.shift_name_snapshot, \'Unassigned\')', 'shift'),
        ];
    }

    public function checkpoints(array $filters): array
    {
        $denominator = (clone $this->query($filters))->count('fpr.id');
        $rows = $this->query($filters)
            ->join('fitness_test_checkpoint_results as fpc', 'fpc.fitness_test_participant_result_id', '=', 'fpr.id')
            ->selectRaw('fpc.checkpoint_code as checkpointCode, COUNT(DISTINCT fpr.id) as recordedParticipants, SUM(CASE WHEN fpc.completed = 1 THEN 1 ELSE 0 END) as completedParticipants')
            ->groupBy('fpc.checkpoint_code')
            ->orderBy('fpc.display_order')
            ->get();

        return $rows->map(fn ($row) => [
            'checkpointCode' => $row->checkpointCode,
            'recordedParticipants' => (int) $row->recordedParticipants,
            'completedParticipants' => (int) $row->completedParticipants,
            'missingParticipants' => max(0, $denominator - (int) $row->recordedParticipants),
            'completionRate' => $this->rate((int) $row->completedParticipants, $denominator),
        ])->values()->all();
    }

    public function coverage(array $filters): array
    {
        return [
            'summary' => $this->summary($this->query($filters)),
            'team' => $this->grouped($filters, 'COALESCE(t.name, ftg.team_id)', 'team'),
            'shift' => $this->grouped($filters, 'COALESCE(ftg.shift_name_snapshot, \'Unassigned\')', 'shift'),
            'minimumCohortSize' => self::MINIMUM_COHORT_SIZE,
        ];
    }

    public function personnel(int $userId, array $filters): array
    {
        $rows = $this->query($filters)
            ->where('fpr.user_id', $userId)
            ->selectRaw("r.report_uid as reportUid, r.status as reportStatus, ftr.reporting_month as reportingMonth, ftr.protocol_revision as protocolRevision, t.name as teamName, ftg.shift_name_snapshot as shift, fpr.fitness_result as fitnessResult, fpr.proficiency_result as proficiencyResult, fpr.fitness_tested_on as fitnessTestedOn, fpr.proficiency_tested_on as proficiencyTestedOn")
            ->orderByDesc('ftr.reporting_month')
            ->get();

        return ['summary' => $this->summary((clone $this->query($filters))->where('fpr.user_id', $userId)), 'history' => $rows];
    }

    private function query(array $filters)
    {
        $query = DB::table('fitness_test_participant_results as fpr')
            ->join('fitness_test_shift_groups as ftg', 'ftg.id', '=', 'fpr.fitness_test_shift_group_id')
            ->join('fitness_test_reports as ftr', 'ftr.id', '=', 'ftg.fitness_test_report_id')
            ->join('reports as r', 'r.id', '=', 'ftr.report_id')
            ->leftJoin('teams as t', 't.id', '=', 'ftg.team_id')
            ->whereNull('r.deleted_at')
            ->whereBetween('ftr.reporting_month', [$filters['monthFrom'], $filters['monthTo']])
            ->whereRaw('LOWER(r.status) = ?', [strtolower($filters['approvalStatus'])]);
        foreach (['teamId' => 'ftg.team_id', 'shift' => 'ftg.shift_name_snapshot', 'protocolRevision' => 'ftr.protocol_revision', 'fitnessResult' => 'fpr.fitness_result', 'proficiencyResult' => 'fpr.proficiency_result'] as $key => $column) {
            if ($filters[$key] !== null && $filters[$key] !== '') {
                $query->where($column, $filters[$key]);
            }
        }
        if ($filters['ageBand']) {
            [$minimum, $maximum] = array_map('intval', explode('-', $filters['ageBand'], 2));
            $query->whereBetween('fpr.age_snapshot', [min($minimum, $maximum), max($minimum, $maximum)]);
        }

        return $query;
    }

    private function summary($query): array
    {
        $combined = $this->combinedResultExpression();
        $row = $query->selectRaw("COUNT(*) as totalParticipants, SUM(CASE WHEN fpr.fitness_result IN ('passed','failed') OR fpr.proficiency_result IN ('passed','failed') THEN 1 ELSE 0 END) as assessedParticipants, SUM(CASE WHEN {$combined} = 'passed' THEN 1 ELSE 0 END) as passed, SUM(CASE WHEN {$combined} = 'failed' THEN 1 ELSE 0 END) as failed, SUM(CASE WHEN {$combined} = 'incomplete' THEN 1 ELSE 0 END) as incomplete, SUM(CASE WHEN fpr.fitness_result = 'passed' THEN 1 ELSE 0 END) as fitnessPassed, SUM(CASE WHEN fpr.proficiency_result = 'passed' THEN 1 ELSE 0 END) as proficiencyPassed")->first();
        $total = (int) ($row->totalParticipants ?? 0);
        return [
            'totalParticipants' => $total, 'assessedParticipants' => (int) ($row->assessedParticipants ?? 0),
            'participationCoverageRate' => $this->rate((int) ($row->assessedParticipants ?? 0), $total),
            'passed' => (int) ($row->passed ?? 0), 'failed' => (int) ($row->failed ?? 0), 'incomplete' => (int) ($row->incomplete ?? 0),
            'fitnessPassed' => (int) ($row->fitnessPassed ?? 0), 'proficiencyPassed' => (int) ($row->proficiencyPassed ?? 0),
        ];
    }

    private function grouped(array $filters, string $column, string $label): array
    {
        $combined = $this->combinedResultExpression();
        return $this->query($filters)
            ->selectRaw("{$column} as {$label}, COUNT(*) as participants, SUM(CASE WHEN {$combined} = 'passed' THEN 1 ELSE 0 END) as passed, SUM(CASE WHEN {$combined} = 'failed' THEN 1 ELSE 0 END) as failed")
            ->groupByRaw($column)
            ->havingRaw('COUNT(*) >= ?', [self::MINIMUM_COHORT_SIZE])
            ->orderBy($label)
            ->get()
            ->map(fn ($row) => ['label' => $row->{$label}, 'participants' => (int) $row->participants, 'passed' => (int) $row->passed, 'failed' => (int) $row->failed, 'passRate' => $this->rate((int) $row->passed, (int) $row->participants)])
            ->values()->all();
    }

    private function overdueCount(array $filters): int
    {
        $combined = $this->combinedResultExpression();
        return $this->query($filters)->where('ftr.reporting_month', '<', Carbon::now()->format('Y-m'))->whereRaw("{$combined} = 'incomplete'")->count();
    }

    private function combinedResultExpression(): string
    {
        return "CASE WHEN fpr.fitness_result = 'failed' OR fpr.proficiency_result = 'failed' THEN 'failed' WHEN fpr.fitness_result = 'passed' AND fpr.proficiency_result = 'passed' THEN 'passed' ELSE 'incomplete' END";
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 0.0 : round(($numerator / $denominator) * 100, 2);
    }
}
