<?php

namespace App\Services;

use App\Models\Report;

final class FitnessShadowReadCutoverService
{
    public function isReady(): bool
    {
        return $this->summary()['blockingReports'] === 0;
    }

    public function summary(): array
    {
        $base = Report::query()
            ->where('report_type', 'fitness-test')
            ->whereNotNull('domain_projected_at');

        $projectedReports = (clone $base)->count();
        $blockingReports = (clone $base)
            ->whereRaw("NOT EXISTS (SELECT 1 FROM fitness_shadow_reconciliations fsr WHERE fsr.report_id = reports.id AND fsr.id = (SELECT MAX(latest.id) FROM fitness_shadow_reconciliations latest WHERE latest.report_id = reports.id) AND fsr.status = 'matched')")
            ->count();

        return [
            'projectedReports' => $projectedReports,
            'blockingReports' => $blockingReports,
            'ready' => $blockingReports === 0,
        ];
    }
}
