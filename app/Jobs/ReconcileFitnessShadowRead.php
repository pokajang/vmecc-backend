<?php

namespace App\Jobs;

use App\Models\Report;
use App\Services\FitnessShadowReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReconcileFitnessShadowRead implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $reportId) {}

    public function handle(FitnessShadowReconciliationService $service): void
    {
        $report = Report::query()->find($this->reportId);
        if (! $report) {
            Log::warning('Fitness shadow reconciliation skipped: report not found.', [
                'report_id' => $this->reportId,
            ]);

            return;
        }

        if (strtolower(trim((string) $report->report_type)) !== 'fitness-test') {
            return;
        }

        $service->reconcile($report);
    }
}
