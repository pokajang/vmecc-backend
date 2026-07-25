<?php

namespace App\Services\InspectionFireExtinguishers;

use App\Models\InspectionCheckRow;
use App\Models\InspectionFireExtinguisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FireExtinguisherMonthlyComplianceService
{
    /** @return array{start: Carbon, end: Carbon, label: string, timezone: string} */
    public function cycle(): array
    {
        $current = now();

        return [
            'start' => $current->copy()->startOfMonth(),
            'end' => $current->copy()->endOfMonth(),
            'label' => $current->format('F Y'),
            'timezone' => config('app.timezone'),
        ];
    }

    /**
     * @param  Collection<int, InspectionFireExtinguisher>  $catalogRows
     * @return array<int, array<string, mixed>>
     */
    public function forCatalog(Collection $catalogRows): array
    {
        $cycle = $this->cycle();
        $catalogIds = $catalogRows->pluck('id')->filter()->map(fn ($id): int => (int) $id)->values();
        if ($catalogIds->isEmpty()) {
            return [];
        }

        $reportCounts = InspectionCheckRow::query()
            ->selectRaw('equipment_catalog_id, COUNT(DISTINCT report_id) AS report_count')
            ->where('inspection_type_key', 'fire-extinguisher-inspection')
            ->where('source_payload_key', 'fireExtinguisherChecks')
            ->whereIn('equipment_catalog_id', $catalogIds)
            ->whereNotNull('submitted_at')
            ->whereBetween('submitted_at', [$cycle['start'], $cycle['end']])
            ->groupBy('equipment_catalog_id')
            ->pluck('report_count', 'equipment_catalog_id');

        return $catalogRows->mapWithKeys(function (InspectionFireExtinguisher $row) use ($cycle, $reportCounts): array {
            $reportCount = (int) ($reportCounts->get((int) $row->id) ?? 0);
            $status = $this->statusFor($row, $reportCount);

            return [(int) $row->id => [
                'status' => $status,
                'label' => $this->labelFor($status),
                'isRequired' => in_array($status, ['complete', 'repeat_check', 'not_inspected'], true),
                'isExcluded' => in_array($status, ['out_of_service', 'retired'], true),
                'reportCount' => $reportCount,
                'cycleLabel' => $cycle['label'],
            ]];
        })->all();
    }

    /**
     * @param  Collection<int, InspectionFireExtinguisher>  $catalogRows
     * @return array<string, mixed>
     */
    public function summarize(Collection $catalogRows): array
    {
        $rows = collect($this->forCatalog($catalogRows));
        $cycle = $this->cycle();

        return [
            'inspected' => $rows->whereIn('status', ['complete', 'repeat_check'])->count(),
            'notInspected' => $rows->where('status', 'not_inspected')->count(),
            'repeatChecks' => $rows->where('status', 'repeat_check')->count(),
            'excludedOutOfService' => $rows->where('status', 'out_of_service')->count(),
            'excludedRetired' => $rows->where('status', 'retired')->count(),
            'cycle' => [
                'type' => 'calendar-month',
                'label' => $cycle['label'],
                'start' => $cycle['start']->toDateString(),
                'end' => $cycle['end']->toDateString(),
                'timezone' => $cycle['timezone'],
            ],
        ];
    }

    private function statusFor(InspectionFireExtinguisher $row, int $reportCount): string
    {
        $lifecycleStatus = (string) ($row->lifecycle_status ?: ($row->is_active ? 'active' : 'retired'));
        if ($lifecycleStatus === 'retired') {
            return 'retired';
        }
        if ($lifecycleStatus === 'out_of_service') {
            return 'out_of_service';
        }
        if ($reportCount > 1) {
            return 'repeat_check';
        }
        if ($reportCount === 1) {
            return 'complete';
        }

        return 'not_inspected';
    }

    private function labelFor(string $status): string
    {
        return match ($status) {
            'complete' => 'Complete',
            'repeat_check' => 'Repeat check',
            'out_of_service' => 'Excluded: out of service',
            'retired' => 'Excluded: retired',
            default => 'Not inspected',
        };
    }
}
