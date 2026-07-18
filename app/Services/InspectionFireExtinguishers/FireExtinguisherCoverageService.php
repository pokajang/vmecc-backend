<?php

namespace App\Services\InspectionFireExtinguishers;

use App\Models\InspectionCheckRow;
use App\Models\InspectionFireExtinguisher;
use App\Models\InspectionFireExtinguisherIssue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FireExtinguisherCoverageService
{
    public function __construct(
        private readonly FireExtinguisherCoveragePolicy $policy,
        private readonly FireExtinguisherCoverageRowBuilder $rowBuilder,
    ) {}

    /**
     * Build the complete, unpaginated coverage result for a filter snapshot.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters = [], bool $includeChecks = false): array
    {
        $normalized = $this->policy->normalizeFilters($filters);
        $query = $this->catalogQuery($normalized);

        $total = (clone $query)->count();
        $catalogRows = $query->orderBy('zone')->orderBy('main_location_name')->orderBy('sub_location_name')
            ->orderBy('id_loc_no')->orderBy('sort_order')->orderBy('id')->get();
        [$periodStart, $periodEnd] = $this->periodRange(
            $normalized['period'],
            $normalized['periodFrom'],
            $normalized['periodTo'],
        );
        $coverageRows = $this->rowBuilder->coverageRowsForCatalog($catalogRows, $periodStart, $periodEnd);
        $duplicateCounts = $this->coverageLocatorDuplicateCounts($catalogRows);
        $data = $catalogRows->map(fn (InspectionFireExtinguisher $row): array => $this->rowBuilder->formatCoverageRow(
            $row,
            $coverageRows[(int) $row->id] ?? null,
            $duplicateCounts[(int) $row->id] ?? 1,
            $includeChecks,
        ))->values();
        $filtered = $this->policy->filter($data, $normalized);
        $sorted = $this->policy->sort($filtered, $normalized['sort'], $normalized['direction']);

        return [
            'rows' => $sorted,
            'unfilteredRows' => $data,
            'total' => $total,
            'filtered' => $sorted->count(),
            'summary' => $this->policy->summary($filtered),
            'options' => $this->coverageFilterOptions($data),
            'filters' => $normalized,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
        ];
    }

    /**
     * Build a database-paginated result for the common table view.
     *
     * Filters that depend on hydrated inspection rows intentionally fall back to
     * build(), preserving their existing behavior without loading every row for
     * the default view.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    public function buildPage(array $filters, int $page, int $perPage): ?array
    {
        $normalized = $this->policy->normalizeFilters($filters);
        if (! $this->supportsDatabasePagination($normalized)) {
            return null;
        }

        [$periodStart, $periodEnd] = $this->periodRange(
            $normalized['period'],
            $normalized['periodFrom'],
            $normalized['periodTo'],
        );
        $query = $this->catalogQuery($normalized);
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $direction = $normalized['direction'];

        $catalogRows = $this->applyZoneLocationOrder($query, $direction)
            ->orderBy('zone', $direction)
            ->orderBy('main_location_name', $direction)
            ->orderBy('sub_location_name', $direction)
            ->orderBy('id_loc_no', $direction)
            ->orderBy('sort_order', $direction)
            ->orderBy('id', $direction)
            ->forPage($page, $perPage)
            ->get();
        $coverageRows = $this->rowBuilder->coverageRowsForCatalogPage($catalogRows, $periodStart, $periodEnd);
        $duplicateCounts = $this->coverageLocatorDuplicateCounts($catalogRows);
        $rows = $catalogRows->map(fn (InspectionFireExtinguisher $row): array => $this->rowBuilder->formatCoverageRow(
            $row,
            $coverageRows[(int) $row->id] ?? null,
            $duplicateCounts[(int) $row->id] ?? 1,
            false,
        ))->values();

        return [
            'rows' => $rows,
            'total' => $total,
            'filtered' => $total,
            'summary' => $this->databaseSummary($normalized, $periodStart, $periodEnd, $total),
            'options' => $this->databaseFilterOptions($normalized, $periodStart, $periodEnd),
            'filters' => $normalized,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'page' => $page,
            'perPage' => $perPage,
            'lastPage' => $lastPage,
            'strategy' => 'database-page',
        ];
    }

    private function applyZoneLocationOrder(Builder $query, string $direction): Builder
    {
        if ($query->getConnection()->getDriverName() === 'pgsql') {
            return $query->orderByRaw(
                "CASE
                    WHEN TRIM(zone) ~ '^[0-9]+$' THEN CAST(TRIM(zone) AS INTEGER)
                    WHEN LOWER(TRIM(zone)) ~ '^zone[[:space:]]+[0-9]+'
                        THEN CAST(SUBSTRING(LOWER(TRIM(zone)) FROM '^zone[[:space:]]+([0-9]+)') AS INTEGER)
                    ELSE 2147483647
                END {$direction}",
            );
        }

        if ($query->getConnection()->getDriverName() === 'sqlite') {
            return $query->orderByRaw(
                "CASE
                    WHEN LOWER(TRIM(zone)) LIKE 'zone %'
                        THEN CAST(TRIM(REPLACE(LOWER(zone), 'zone ', '')) AS INTEGER)
                    WHEN TRIM(zone) <> '' AND TRIM(zone) NOT GLOB '*[^0-9]*'
                        THEN CAST(TRIM(zone) AS INTEGER)
                    ELSE 2147483647
                END {$direction}",
            );
        }

        if (in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $query->orderByRaw(
                "CASE
                    WHEN TRIM(zone) REGEXP '^[0-9]+$' THEN CAST(TRIM(zone) AS UNSIGNED)
                    WHEN LOWER(TRIM(zone)) REGEXP '^zone[[:space:]]+[0-9]+'
                        THEN CAST(TRIM(REPLACE(LOWER(zone), 'zone ', '')) AS UNSIGNED)
                    ELSE 2147483647
                END {$direction}",
            );
        }

        return $query;
    }

    /** @param array<string, string> $normalized */
    private function supportsDatabasePagination(array $normalized): bool
    {
        return $normalized['status'] === 'all'
            && $normalized['inspectedBy'] === 'all'
            && $normalized['duplicateScope'] === 'all'
            && $normalized['issues'] === 'all'
            && $normalized['certification'] === 'all'
            && $normalized['sort'] === 'zone-location';
    }

    /** @param array<string, string> $normalized */
    private function catalogQuery(array $normalized, bool $withIssueCounts = true): Builder
    {
        $query = InspectionFireExtinguisher::query();
        if ($withIssueCounts) {
            $query->withCount([
                'issues as open_issues_count' => fn ($builder) => $builder->whereIn('status', InspectionFireExtinguisherIssue::ACTIVE_STATUSES),
                'issues as overdue_issues_count' => fn ($builder) => $builder->whereIn('status', InspectionFireExtinguisherIssue::ACTIVE_STATUSES)->where('due_at', '<', now()),
            ]);
        }
        if ($normalized['lifecycleStatus'] === 'all') {
            // Include every lifecycle state.
        } elseif (in_array($normalized['lifecycleStatus'], ['active', 'out_of_service', 'retired'], true)) {
            $query->where('lifecycle_status', $normalized['lifecycleStatus']);
        } else {
            $query->where('lifecycle_status', 'active');
        }
        if ($normalized['zone'] !== '') {
            $query->where('zone', $normalized['zone']);
        }
        if ($normalized['location'] !== '') {
            $query->where('main_location_name', $normalized['location']);
        }
        if ($normalized['search'] !== '') {
            $search = $normalized['search'];
            $query->where(function ($builder) use ($search): void {
                $like = "%{$search}%";
                $builder->where('zone', 'like', $like)
                    ->orWhere('main_location_name', 'like', $like)
                    ->orWhere('sub_location_name', 'like', $like)
                    ->orWhere('id_loc_no', 'like', $like)
                    ->orWhere('barcode_no', 'like', $like)
                    ->orWhere('fe_type', 'like', $like)
                    ->orWhere('certification_validity', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * @param  array<string, string>  $normalized
     * @return array<string, int>
     */
    private function databaseSummary(
        array $normalized,
        ?Carbon $periodStart,
        ?Carbon $periodEnd,
        int $total,
    ): array {
        $catalogIds = $this->catalogQuery($normalized, false)->select('inspection_fire_extinguishers.id');
        $checks = $this->inspectionRowsQuery($catalogIds, $periodStart, $periodEnd);
        $inspected = (clone $checks)->distinct()->count('equipment_catalog_id');
        $repeatAssets = DB::query()->fromSub(
            (clone $checks)
                ->select('equipment_catalog_id')
                ->groupBy('equipment_catalog_id')
                ->havingRaw('COUNT(DISTINCT report_id) > 1'),
            'repeat_assets',
        )->count();
        $issues = $this->catalogQuery($normalized, false)
            ->whereHas('issues', fn ($builder) => $builder->whereIn('status', InspectionFireExtinguisherIssue::ACTIVE_STATUSES))
            ->count();
        $expired = $this->catalogQuery($normalized, false)
            ->whereNotNull('certification_validity')
            ->whereDate('certification_validity', '<', now()->toDateString())
            ->count();
        $locatorRows = $this->catalogQuery($normalized, false)->get(['id', 'barcode_no', 'id_loc_no']);
        $locatorDuplicates = collect($this->coverageLocatorDuplicateCounts($locatorRows))
            ->filter(fn (int $count): bool => $count > 1)
            ->count();

        return [
            'total' => $total,
            'inspected' => $inspected,
            'notInspected' => max(0, $total - $inspected),
            'issues' => $issues,
            'duplicates' => $repeatAssets,
            'locatorDuplicates' => $locatorDuplicates,
            'expired' => $expired,
        ];
    }

    /**
     * @param  array<string, string>  $normalized
     * @return array<string, array<int, string>>
     */
    private function databaseFilterOptions(
        array $normalized,
        ?Carbon $periodStart,
        ?Carbon $periodEnd,
    ): array {
        $baseQuery = $this->catalogQuery($normalized, false);
        $zones = (clone $baseQuery)->whereNotNull('zone')->distinct()->pluck('zone')
            ->map(fn ($value): string => $this->text($value))->filter()->unique()
            ->sort(fn (string $a, string $b): int => ($this->policy->zoneSortValue($a) <=> $this->policy->zoneSortValue($b)) ?: strnatcasecmp($a, $b))
            ->values()->all();
        $locations = (clone $baseQuery)->whereNotNull('main_location_name')->distinct()->pluck('main_location_name')
            ->map(fn ($value): string => $this->text($value))->filter()->unique()
            ->sort(fn (string $a, string $b): int => strnatcasecmp($a, $b))->values()->all();
        $catalogIds = (clone $baseQuery)->select('inspection_fire_extinguishers.id');
        $latestChecks = $this->inspectionRowsQuery($catalogIds, $periodStart, $periodEnd)
            ->selectRaw('equipment_catalog_id, MAX(submitted_at) AS latest_submitted_at')
            ->groupBy('equipment_catalog_id');
        $inspectors = InspectionCheckRow::query()
            ->joinSub($latestChecks, 'latest_checks', function ($join): void {
                $join->on('latest_checks.equipment_catalog_id', '=', 'inspection_check_rows.equipment_catalog_id')
                    ->on('latest_checks.latest_submitted_at', '=', 'inspection_check_rows.submitted_at');
            })
            ->where('inspection_check_rows.inspection_type_key', 'fire-extinguisher-inspection')
            ->where('inspection_check_rows.source_payload_key', 'fireExtinguisherChecks')
            ->whereNotNull('submitted_by_user_id')
            ->join('users', 'users.id', '=', 'inspection_check_rows.submitted_by_user_id')
            ->distinct()
            ->pluck('users.name')
            ->map(fn ($value): string => $this->text($value))->filter()->unique()
            ->sort(fn (string $a, string $b): int => strnatcasecmp($a, $b))->values()->all();

        return compact('zones', 'locations', 'inspectors');
    }

    private function inspectionRowsQuery(
        Builder $catalogIds,
        ?Carbon $periodStart,
        ?Carbon $periodEnd,
    ): Builder {
        return InspectionCheckRow::query()
            ->where('inspection_type_key', 'fire-extinguisher-inspection')
            ->where('source_payload_key', 'fireExtinguisherChecks')
            ->whereIn('equipment_catalog_id', $catalogIds)
            ->whereNotNull('submitted_at')
            ->when($periodStart, fn ($query) => $query->where('submitted_at', '>=', $periodStart))
            ->when($periodEnd, fn ($query) => $query->where('submitted_at', '<=', $periodEnd));
    }

    /** @param array<string, mixed> $filters */
    public function detail(InspectionFireExtinguisher $row, array $filters = []): array
    {
        $row->loadCount([
            'issues as open_issues_count' => fn ($builder) => $builder->whereIn('status', InspectionFireExtinguisherIssue::ACTIVE_STATUSES),
            'issues as overdue_issues_count' => fn ($builder) => $builder->whereIn('status', InspectionFireExtinguisherIssue::ACTIVE_STATUSES)->where('due_at', '<', now()),
        ]);
        $normalized = $this->policy->normalizeFilters($filters);
        [$periodStart, $periodEnd] = $this->periodRange(
            $normalized['period'],
            $normalized['periodFrom'],
            $normalized['periodTo'],
        );
        $catalogRows = collect([$row]);
        $coverageRows = $this->rowBuilder->coverageRowsForCatalog($catalogRows, $periodStart, $periodEnd);
        $duplicateCounts = $this->coverageLocatorDuplicateCounts($catalogRows);

        return [
            'row' => $this->rowBuilder->formatCoverageRow(
                $row,
                $coverageRows[(int) $row->id] ?? null,
                $duplicateCounts[(int) $row->id] ?? 1,
                true,
            ),
            'filters' => $normalized,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
        ];
    }

    /** @return array<string, array<int, string>> */
    private function coverageFilterOptions(Collection $coverageRows): array
    {
        return [
            'zones' => $coverageRows->pluck('zone')->map(fn ($value): string => $this->text($value))
                ->filter()->unique()->sort(fn (string $a, string $b): int => ($this->policy->zoneSortValue($a) <=> $this->policy->zoneSortValue($b)) ?: strnatcasecmp($a, $b)
                )->values()->all(),
            'locations' => $coverageRows->pluck('location')->map(fn ($value): string => $this->text($value))
                ->filter()->unique()->sort(fn (string $a, string $b): int => strnatcasecmp($a, $b))->values()->all(),
            'inspectors' => $coverageRows->pluck('inspectedBy')->map(fn ($value): string => $this->text($value))
                ->filter()->unique()->sort(fn (string $a, string $b): int => strnatcasecmp($a, $b))->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, InspectionFireExtinguisher>  $catalogRows
     * @return array<int, int>
     */
    private function coverageLocatorDuplicateCounts(Collection $catalogRows): array
    {
        $locators = $catalogRows->flatMap(fn (InspectionFireExtinguisher $row): array => $this->locatorCandidates([
            'barcodeNo' => $row->barcode_no ?? '', 'idLocNo' => $row->id_loc_no ?? '',
        ]))->filter()->unique()->values()->all();
        if ($locators === []) {
            return [];
        }

        $targets = array_fill_keys($locators, true);
        $activeRows = InspectionFireExtinguisher::query()->where('is_active', true)
            ->where(function ($query) use ($locators): void {
                $query->whereIn(DB::raw('LOWER(TRIM(barcode_no))'), $locators)
                    ->orWhereIn(DB::raw('LOWER(TRIM(id_loc_no))'), $locators);
            })->get(['id', 'barcode_no', 'id_loc_no']);
        $activeIdsByLocator = [];
        foreach ($activeRows as $activeRow) {
            foreach ($this->locatorCandidates([
                'barcodeNo' => $activeRow->barcode_no ?? '', 'idLocNo' => $activeRow->id_loc_no ?? '',
            ]) as $locator) {
                if (isset($targets[$locator])) {
                    $activeIdsByLocator[$locator][(int) $activeRow->id] = true;
                }
            }
        }

        return $catalogRows->mapWithKeys(function (InspectionFireExtinguisher $row) use ($activeIdsByLocator): array {
            $matchingIds = [];
            foreach ($this->locatorCandidates([
                'barcodeNo' => $row->barcode_no ?? '', 'idLocNo' => $row->id_loc_no ?? '',
            ]) as $locator) {
                foreach ($activeIdsByLocator[$locator] ?? [] as $activeId => $_) {
                    $matchingIds[(int) $activeId] = true;
                }
            }

            return [(int) $row->id => max(1, count($matchingIds))];
        })->all();
    }

    /** @return array{0: Carbon|null, 1: Carbon|null} */
    private function periodRange(string $period, string $from, string $to): array
    {
        return match ($period) {
            'today' => [now()->startOfDay(), now()->endOfDay()],
            'thisweek', 'this-week', 'this_week' => [now()->startOfWeek(), now()->endOfWeek()],
            'thismonth', 'this-month', 'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
            'lastmonth', 'last-month', 'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'last7', 'last-7', 'last_7' => [now()->startOfDay()->subDays(7), now()->endOfDay()],
            'last30', 'last-30', 'last_30' => [now()->startOfDay()->subDays(30), now()->endOfDay()],
            'last90', 'last-90', 'last_90' => [now()->startOfDay()->subDays(90), now()->endOfDay()],
            'custom' => $this->customPeriodRange($from, $to),
            default => [null, null],
        };
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function customPeriodRange(string $from, string $to): array
    {
        $start = $this->dateBoundary($from);
        $end = $this->dateBoundary($to, true);
        if (! $start || ! $end || $start->gt($end)) {
            throw ValidationException::withMessages(['period' => ['A valid custom period range is required.']]);
        }

        return [$start, $end];
    }

    private function dateBoundary(string $date, bool $endOfDay = false): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }
        try {
            $parsed = Carbon::createFromFormat('Y-m-d', $date);
        } catch (\Throwable) {
            return null;
        }

        return $endOfDay ? $parsed->endOfDay() : $parsed->startOfDay();
    }

    /** @return array<int, string> */
    private function locatorCandidates(array $data): array
    {
        return collect([$data['barcodeNo'] ?? '', $data['idLocNo'] ?? ''])
            ->map(fn ($value): string => $this->locatorPart($value))->filter()->unique()->values()->all();
    }

    private function locatorPart(mixed $value): string
    {
        $locator = trim((string) $value);
        $locator = preg_replace('/^(?:s\s*\/?\s*n|serial(?:\s*(?:number|no\.?))?|barcode)\s*[:#-]?\s*/i', '', $locator) ?? $locator;

        return Str::of($locator)->squish()->lower()->toString();
    }

    private function text(mixed $value): string
    {
        return Str::of((string) $value)->squish()->toString();
    }
}
