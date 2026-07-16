<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Models\ReportDraft;
use App\Services\ReportMediaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeReportMediaE2eArtifacts extends Command
{
    protected $signature = 'reports:purge-media-e2e-artifacts
        {--report-id=* : Exact report UID created by the authenticated media E2E}
        {--draft-id=* : Exact draft UID created by the authenticated media E2E}';

    protected $description = 'Permanently remove explicitly identified report-media E2E rows in local/test environments.';

    public function handle(ReportMediaService $mediaService): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This cleanup command is restricted to local and testing environments.');

            return self::INVALID;
        }

        $reportIds = $this->safeIds((array) $this->option('report-id'), '/^report-(?:erco|drl)-/');
        $draftIds = $this->safeIds((array) $this->option('draft-id'), '/^drf_[a-z0-9]+$/');
        if ($reportIds === [] && $draftIds === []) {
            $this->error('Provide at least one valid --report-id or --draft-id.');

            return self::INVALID;
        }

        $reports = Report::withTrashed()
            ->whereIn('report_uid', $reportIds)
            ->where('submission_key', 'like', 'e2e-report-media-%')
            ->get();
        $drafts = ReportDraft::query()
            ->whereIn('draft_id', $draftIds)
            ->whereIn('title', [
                'ERCO authenticated media smoke',
                'DRILL authenticated media smoke',
            ])
            ->get();

        DB::transaction(function () use ($drafts, $mediaService, $reports): void {
            foreach ($reports as $report) {
                $mediaService->removeParentLinks('report', (string) $report->report_uid);
                $report->forceDelete();
            }
            foreach ($drafts as $draft) {
                $mediaService->removeParentLinks('report_draft', (string) $draft->draft_id);
                $draft->delete();
            }
        });

        $this->info(sprintf('Purged %d report(s) and %d draft(s).', $reports->count(), $drafts->count()));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function safeIds(array $values, string $pattern): array
    {
        return collect($values)
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => preg_match($pattern, $value) === 1)
            ->unique()
            ->values()
            ->all();
    }
}
