<?php

namespace App\Services\InspectionFireExtinguishers;

use App\Models\User;
use App\Services\InspectionReports\InspectionReportPhotoSanitizer;
use App\Services\ReportMediaService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FireExtinguisherExceptionExportBuilder
{
    public function __construct(
        private readonly FireExtinguisherCoverageService $coverageService,
        private readonly ReportMediaService $reportMediaService,
        private readonly InspectionReportPhotoSanitizer $photoSanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $requestData
     * @return array<string, mixed>
     */
    public function preview(array $requestData): array
    {
        $selection = $this->select($requestData, false);

        return [
            'total' => $selection['summary']['total'],
            'issues' => $selection['summary']['issues'],
            'expired' => $selection['summary']['expired'],
            'overlap' => $selection['summary']['overlap'],
            'appliedFilters' => $selection['appliedFilters'],
            'scope' => $selection['scope'],
        ];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @return array<string, mixed>
     */
    public function build(array $requestData, ?User $user): array
    {
        $selection = $this->select($requestData, true);
        $maxRecords = max(1, (int) config('inspection_reports.exception_export.max_records', 500));
        if ($selection['items']->count() > $maxRecords) {
            throw ValidationException::withMessages([
                'filters' => ["This export contains more than {$maxRecords} extinguishers. Narrow the filters and try again."],
            ]);
        }

        $items = $selection['items']->map(function (array $row): array {
            $defects = collect($row['checks'] ?? [])->filter(
                fn (array $check): bool => (bool) ($check['hasDefect'] ?? false),
            )->values()->all();
            $reportUid = trim((string) ($row['latestReportUid'] ?? ''));
            if ($reportUid !== '' && $defects !== []) {
                $hydrated = $this->reportMediaService->hydrateLinkedPayloadForPdf(
                    ['checks' => $defects],
                    'report',
                    $reportUid,
                    'inspection',
                );
                $defects = is_array($hydrated['checks'] ?? null) ? $hydrated['checks'] : $defects;
            }

            return [
                ...$row,
                'isIssue' => (int) ($row['issueCount'] ?? 0) > 0,
                'isExpired' => $this->isExpired($row),
                'daysExpired' => $this->isExpired($row) ? abs((int) $row['daysLeft']) : 0,
                'defects' => $defects,
            ];
        })->values()->all();

        $generatedAt = now();
        $reportSummary = [
            'total' => count($items),
            'issues' => collect($items)->where('isIssue', true)->count(),
            'expired' => collect($items)->where('isExpired', true)->count(),
            'overlap' => collect($items)->filter(
                fn (array $item): bool => (bool) ($item['isIssue'] ?? false) && (bool) ($item['isExpired'] ?? false),
            )->count(),
        ];
        $record = [
            'title' => $this->title($selection['categories']),
            'categories' => $selection['categories'],
            'layoutMode' => $this->layoutMode($selection['categories']),
            'generatedAt' => $generatedAt->toIso8601String(),
            'generatedAtDisplay' => $generatedAt->format('d M Y, H:i'),
            'generatedBy' => trim((string) ($user?->name ?? '')) ?: 'System user',
            'asOfDate' => $generatedAt->toDateString(),
            'asOfDateDisplay' => $generatedAt->format('d M Y'),
            'scope' => $selection['scope'],
            'appliedFilters' => $selection['appliedFilters'],
            'summary' => $reportSummary,
            'items' => $items,
        ];

        $sanitized = $this->photoSanitizer->sanitize(
            $record,
            max(1, (int) config('inspection_reports.exception_export.max_images', 100)),
        );

        return [
            ...$sanitized->record,
            'renderMeta' => [
                'imageCount' => $sanitized->imageCount,
                'unavailableImageCount' => $sanitized->unavailableImageCount,
                'omittedImageCount' => $sanitized->omittedImageCount,
                'imageBytes' => $sanitized->totalImageBytes,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @return array<string, mixed>
     */
    private function select(array $requestData, bool $includeChecks): array
    {
        $categories = collect($requestData['categories'] ?? [])->map(
            fn ($category): string => strtolower(trim((string) $category)),
        )->filter(fn (string $category): bool => in_array($category, ['issues', 'expired'], true))
            ->unique()->values()->all();
        $scope = ($requestData['scope'] ?? 'current_filters') === 'all' ? 'all' : 'current_filters';
        $filters = $scope === 'all' ? [] : (array) ($requestData['filters'] ?? []);
        $filters['issues'] = 'all';
        $filters['certification'] = 'all';
        $filters['sort'] = 'zone-location';
        $filters['direction'] = 'asc';

        $coverage = $this->coverageService->build($filters, $includeChecks);
        /** @var Collection<int, array<string, mixed>> $baseRows */
        $baseRows = $coverage['rows'];
        $issueRows = $baseRows->filter(fn (array $row): bool => (int) ($row['issueCount'] ?? 0) > 0);
        $expiredRows = $baseRows->filter(fn (array $row): bool => $this->isExpired($row));
        $selected = $baseRows->filter(function (array $row) use ($categories): bool {
            return (in_array('issues', $categories, true) && (int) ($row['issueCount'] ?? 0) > 0)
                || (in_array('expired', $categories, true) && $this->isExpired($row));
        })->unique(fn (array $row): string => (string) ($row['catalogId'] ?? $row['id'] ?? ''))->values();

        return [
            'categories' => $categories,
            'scope' => $scope,
            'items' => $selected,
            'summary' => [
                'total' => $selected->count(),
                'issues' => $issueRows->count(),
                'expired' => $expiredRows->count(),
                'overlap' => $baseRows->filter(fn (array $row): bool => (int) ($row['issueCount'] ?? 0) > 0 && $this->isExpired($row)
                )->count(),
            ],
            'appliedFilters' => $scope === 'all' ? [] : $this->appliedFilterLabels($coverage['filters']),
        ];
    }

    private function isExpired(array $row): bool
    {
        return trim((string) ($row['daysLeft'] ?? '')) !== '' && (int) $row['daysLeft'] < 0;
    }

    /** @param array<int, string> $categories */
    private function title(array $categories): string
    {
        if (in_array('issues', $categories, true) && in_array('expired', $categories, true)) {
            return 'Fire Extinguisher Issues and Expired Certification Report';
        }

        return in_array('expired', $categories, true)
            ? 'Fire Extinguisher Expired Certification Report'
            : 'Fire Extinguisher Issues Report';
    }

    /** @param array<int, string> $categories */
    private function layoutMode(array $categories): string
    {
        $hasIssues = in_array('issues', $categories, true);
        $hasExpired = in_array('expired', $categories, true);

        if ($hasIssues && $hasExpired) {
            return 'combined';
        }

        return $hasExpired ? 'expired' : 'issues';
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<int, array{key: string, label: string}>
     */
    private function appliedFilterLabels(array $filters): array
    {
        $labels = [];
        $append = function (string $key, string $label) use (&$labels): void {
            if (trim($label) !== '') {
                $labels[] = ['key' => $key, 'label' => $label];
            }
        };

        if ($filters['search'] !== '') {
            $append('search', 'Search: '.$filters['search']);
        }
        if ($filters['period'] !== 'all') {
            $periodLabels = [
                'today' => 'Today',
                'thisweek' => 'This week',
                'thismonth' => 'This month',
                'lastmonth' => 'Last month',
                'last7' => 'Last 7 days',
                'last30' => 'Last 30 days',
                'last90' => 'Last 90 days',
            ];
            $periodLabel = $filters['period'] === 'custom'
                ? "Period: {$filters['periodFrom']} to {$filters['periodTo']}"
                : 'Period: '.($periodLabels[$filters['period']] ?? Str::headline($filters['period']));
            $append('period', $periodLabel);
        }
        if ($filters['zone'] !== '') {
            $append('zone', 'Zone: '.$filters['zone']);
        }
        if ($filters['location'] !== '') {
            $append('location', 'Location: '.$filters['location']);
        }
        if ($filters['inspectedBy'] !== 'all') {
            $append('inspectedBy', 'Inspector: '.$filters['inspectedBy']);
        }
        if ($filters['status'] !== 'all') {
            $append('status', 'Status: '.Str::headline($filters['status']));
        }
        if ($filters['duplicateScope'] !== 'all') {
            $append('duplicateScope', match ($filters['duplicateScope']) {
                'locator' => 'Duplicate barcode',
                'id-loc' => 'Duplicate ID Loc No.',
                default => 'Repeat checks',
            });
        }

        return $labels;
    }
}
