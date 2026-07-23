<?php

namespace App\Services;

final class ReportModuleRegistry
{
    /** @var array<string, ReportModuleAdapter> */
    private readonly array $adapters;

    public function __construct(FitnessTestReportModuleAdapter $fitnessTestAdapter)
    {
        $this->adapters = [
            'fitness-test' => $fitnessTestAdapter,
        ];
    }

    public function for(string $reportType): ?ReportModuleAdapter
    {
        $normalized = strtolower(trim($reportType));

        return $this->adapters[$normalized] ?? null;
    }
}
