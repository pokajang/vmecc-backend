<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Models\ReportMedia;
use App\Services\ErAssessmentPayloadService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuditErAssessmentDraftReports extends Command
{
    protected $signature = 'reports:er-assessment-draft-audit
        {--from= : Inclusive creation date/time}
        {--to= : Inclusive creation date/time}
        {--limit=500 : Maximum candidate rows to inspect}';

    protected $description = 'Read-only inventory of ER Assessment report rows left in Draft with submission keys.';

    public function handle(ErAssessmentPayloadService $payloadService): int
    {
        $limit = min(max((int) $this->option('limit'), 1), 5000);
        $query = Report::query()
            ->where('report_type', 'er-assessment')
            ->where('status', 'Draft')
            ->whereNotNull('submission_key')
            ->orderBy('id');
        if ($from = trim((string) $this->option('from'))) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = trim((string) $this->option('to'))) {
            $query->where('created_at', '<=', $to);
        }

        $candidates = $query->limit($limit)->get();
        $rows = $candidates->map(function (Report $report) use ($payloadService): array {
            $payloadValid = true;
            $validationDetail = 'valid for current submit contract';
            try {
                $payloadService->validateForSubmit((array) $report->payload);
            } catch (ValidationException $exception) {
                $payloadValid = false;
                $validationDetail = collect($exception->errors())->flatten()->first() ?: 'validation failed';
            } catch (Throwable $exception) {
                $payloadValid = false;
                $validationDetail = $exception->getMessage();
            }

            $ownerExists = $report->owner()->exists();
            $mediaId = trim((string) data_get($report->payload, 'rescueAccessLayout.mediaId', ''));
            $media = $mediaId !== ''
                ? ReportMedia::query()->where('public_id', $mediaId)->first()
                : null;
            $mediaSafe = $media !== null
                && (int) $media->user_id === (int) $report->owner_user_id
                && $media->module === 'er-assessment'
                && $media->links()
                    ->where('parent_type', 'report')
                    ->where('parent_key', $report->report_uid)
                    ->exists();
            $eligible = $payloadValid && $ownerExists && $mediaSafe;

            return [
                $report->report_uid,
                $report->display_id,
                $report->created_at?->toIso8601String() ?? '',
                $payloadValid ? 'yes' : 'no',
                $ownerExists ? 'yes' : 'no',
                $mediaSafe ? 'yes' : 'no',
                $eligible ? 'yes' : 'no',
                $validationDetail,
            ];
        });

        $this->table(
            ['Report UID', 'Display ID', 'Created', 'Payload', 'Owner', 'Media/link', 'Repair eligible', 'Detail'],
            $rows->all(),
        );
        $this->line('Candidates: '.$candidates->count().' (read-only; no report was changed).');

        if ($candidates->isNotEmpty()) {
            $this->warn('Candidates require an explicitly approved, per-record repair or disposition before deployment.');

            return self::FAILURE;
        }

        $this->info('No legacy ER Assessment Draft report candidates found.');

        return self::SUCCESS;
    }
}
