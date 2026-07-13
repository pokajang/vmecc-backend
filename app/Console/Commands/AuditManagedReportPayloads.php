<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Models\ReportMediaLink;
use App\Services\DrillPayloadService;
use App\Services\ErcoPayloadService;
use App\Services\FitnessTestPayloadService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class AuditManagedReportPayloads extends Command
{
    protected $signature = 'reports:audit-payloads
        {--module= : Limit the audit to erco, drill, or fitness-test}
        {--report-uid= : Limit the audit to one report UID}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only validation audit for ERCO, Drill, and Fitness Test payloads.';

    private const MODULES = ['erco', 'drill', 'fitness-test'];

    private const SERVER_FIELDS = [
        'approvalHistory',
        'approvedAt',
        'actionOwner',
        'canApprove',
        'canReject',
        'canReview',
        'createdAt',
        'displayId',
        'nextAction',
        'nextActionRole',
        'ownerUserId',
        'rejectedAt',
        'reviewedAt',
        'scopeTeamId',
        'status',
        'submissionKey',
        'submittedAt',
        'submittedBy',
        'timeline',
        'updatedAt',
        'updatedBy',
        'workflowSnapshot',
        'workflowStage',
    ];

    public function handle(
        ErcoPayloadService $ercoPayloadService,
        DrillPayloadService $drillPayloadService,
        FitnessTestPayloadService $fitnessTestPayloadService,
    ): int {
        $module = strtolower(trim((string) $this->option('module')));
        if ($module !== '' && ! in_array($module, self::MODULES, true)) {
            $this->error('--module must be erco, drill, or fitness-test.');

            return self::INVALID;
        }

        $query = Report::query()
            ->whereIn('report_type', $module !== '' ? [$module] : self::MODULES)
            ->orderBy('id');
        $reportUid = trim((string) $this->option('report-uid'));
        if ($reportUid !== '') {
            $query->where('report_uid', $reportUid);
        }

        $rows = [];
        foreach ($query->cursor() as $report) {
            $payload = is_array($report->payload) ? $report->payload : [];
            $issues = $this->validationIssues(
                (string) $report->report_type,
                (string) $report->status,
                $payload,
                $ercoPayloadService,
                $drillPayloadService,
                $fitnessTestPayloadService,
            );
            $pollutedFields = array_values(array_intersect(self::SERVER_FIELDS, array_keys($payload)));
            if ($pollutedFields !== []) {
                $issues['payload'] = array_values(array_unique([
                    ...($issues['payload'] ?? []),
                    'Server-owned fields embedded in payload: '.implode(', ', $pollutedFields).'.',
                ]));
            }
            $mediaIssue = $this->mediaLinkIssue((string) $report->report_uid, $payload);
            if ($mediaIssue !== null) {
                $issues['media'] = array_values(array_unique([
                    ...($issues['media'] ?? []),
                    $mediaIssue,
                ]));
            }

            $rows[] = [
                'reportUid' => (string) $report->report_uid,
                'displayId' => (string) $report->display_id,
                'module' => (string) $report->report_type,
                'status' => (string) $report->status,
                'valid' => $issues === [],
                'issues' => $issues,
            ];
        }

        $invalidCount = count(array_filter($rows, fn (array $row): bool => ! $row['valid']));
        $result = [
            'scanned' => count($rows),
            'valid' => count($rows) - $invalidCount,
            'invalid' => $invalidCount,
            'reports' => $rows,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Report UID', 'Display ID', 'Module', 'Status', 'Result', 'Issues'],
                array_map(fn (array $row): array => [
                    $row['reportUid'],
                    $row['displayId'],
                    $row['module'],
                    $row['status'],
                    $row['valid'] ? 'valid' : 'invalid',
                    $this->formatIssues($row['issues']),
                ], $rows),
            );
            $this->info("Scanned {$result['scanned']}; valid {$result['valid']}; invalid {$result['invalid']}.");
        }

        return $invalidCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function validationIssues(
        string $module,
        string $status,
        array $payload,
        ErcoPayloadService $ercoPayloadService,
        DrillPayloadService $drillPayloadService,
        FitnessTestPayloadService $fitnessTestPayloadService,
    ): array {
        $isDraft = strcasecmp($status, 'Draft') === 0;
        try {
            match ($module) {
                'erco' => $isDraft
                    ? $ercoPayloadService->validateForDraft($payload)
                    : $ercoPayloadService->validateForSubmit($payload),
                'drill' => $isDraft
                    ? $drillPayloadService->validateForDraft($payload)
                    : $drillPayloadService->validateForSubmit($payload),
                'fitness-test' => $isDraft
                    ? $fitnessTestPayloadService->validateForDraft($payload)
                    : $fitnessTestPayloadService->validateForSubmit($payload),
            };

            return [];
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
    }

    private function formatIssues(array $issues): string
    {
        $rows = [];
        foreach ($issues as $field => $messages) {
            foreach ((array) $messages as $message) {
                $rows[] = $field.': '.$message;
            }
        }

        return implode(' | ', $rows);
    }

    private function mediaLinkIssue(string $reportUid, array $payload): ?string
    {
        $payloadIds = $this->managedMediaIds($payload);
        $linkedIds = ReportMediaLink::query()
            ->join('report_media', 'report_media.id', '=', 'report_media_links.report_media_id')
            ->where('report_media_links.parent_type', 'report')
            ->where('report_media_links.parent_key', $reportUid)
            ->pluck('report_media.public_id')
            ->map(fn ($value): string => (string) $value)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($payloadIds === $linkedIds) {
            return null;
        }

        return sprintf(
            'Managed media links differ from payload references (payload: %s; linked: %s).',
            implode(', ', $payloadIds) ?: 'none',
            implode(', ', $linkedIds) ?: 'none',
        );
    }

    private function managedMediaIds(array $payload): array
    {
        $ids = [];
        $walk = function (mixed $value) use (&$walk, &$ids): void {
            if (! is_array($value)) {
                return;
            }
            $mediaId = trim((string) ($value['mediaId'] ?? $value['media_id'] ?? ''));
            if ($mediaId !== '') {
                $ids[] = $mediaId;
            }
            foreach ($value as $child) {
                $walk($child);
            }
        };
        $walk($payload);
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
