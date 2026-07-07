<?php

namespace App\Http\Controllers;

use App\Models\InspectionCheckRow;
use App\Models\InspectionFireExtinguisher;
use App\Models\Report;
use App\Services\AssignmentAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InspectionFireExtinguisherController extends Controller
{
    private const FIRE_EXTINGUISHER_INSPECTION_TYPE_KEY = 'fire-extinguisher-inspection';

    private const FIRE_EXTINGUISHER_SOURCE_PAYLOAD_KEY = 'fireExtinguisherChecks';

    private const COVERAGE_CHECK_FIELDS = [
        'physical' => [
            'checkKey' => 'physical-condition',
            'payloadKey' => 'physicalCondition',
            'label' => 'FE Physical Condition',
            'remarksKey' => 'physicalConditionRemarks',
            'photosKey' => 'physicalConditionPhotos',
        ],
        'signage' => [
            'checkKey' => 'signage-condition',
            'payloadKey' => 'signageCondition',
            'label' => 'FE Signage Condition',
            'remarksKey' => 'signageConditionRemarks',
            'photosKey' => 'signageConditionPhotos',
        ],
        'boxKey' => [
            'checkKey' => 'box-key-availability',
            'payloadKey' => 'boxKeyAvailability',
            'label' => 'FE Box Key Availability',
            'remarksKey' => 'boxKeyAvailabilityRemarks',
            'photosKey' => 'boxKeyAvailabilityPhotos',
        ],
        'boxGlass' => [
            'checkKey' => 'box-glass-availability',
            'payloadKey' => 'boxGlassAvailability',
            'label' => 'FE Box Glass Availability',
            'remarksKey' => 'boxGlassAvailabilityRemarks',
            'photosKey' => 'boxGlassAvailabilityPhotos',
        ],
        'operational' => [
            'checkKey' => 'operational-condition',
            'payloadKey' => 'operationalCondition',
            'label' => 'Operational Condition',
            'remarksKey' => 'operationalConditionRemarks',
            'photosKey' => 'operationalConditionPhotos',
        ],
    ];

    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureInspectionPermission($request);

        $zone = Str::of((string) $request->query('zone', ''))->squish()->toString();
        $mainLocation = Str::of((string) $request->query('mainLocation', ''))->squish()->toString();
        $subLocation = Str::of((string) $request->query('subLocation', ''))->squish()->toString();
        $search = Str::of((string) $request->query('search', ''))->squish()->toString();

        $query = InspectionFireExtinguisher::query()->where('is_active', true);
        if ($zone !== '') {
            $query->where('zone', $zone);
        }
        if ($mainLocation !== '') {
            $query->where('main_location_name', $mainLocation);
        }
        if ($subLocation !== '') {
            $query->where('sub_location_name', $subLocation);
        }
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $like = "%{$search}%";
                $builder
                    ->where('zone', 'like', $like)
                    ->orWhere('main_location_name', 'like', $like)
                    ->orWhere('sub_location_name', 'like', $like)
                    ->orWhere('id_loc_no', 'like', $like)
                    ->orWhere('barcode_no', 'like', $like)
                    ->orWhere('fe_type', 'like', $like)
                    ->orWhere('certification_validity', 'like', $like);
            });
        }

        $rows = $query
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $lastInspections = $this->latestInspectionsForRows($rows);

        $version = InspectionFireExtinguisher::query()->max('updated_at');

        return response()->json([
            'data' => $rows
                ->map(fn (InspectionFireExtinguisher $row) => $this->formatRow(
                    $row,
                    $request,
                    $lastInspections[(int) $row->id] ?? null,
                ))
                ->values(),
            'meta' => [
                'zone' => $zone,
                'mainLocation' => $mainLocation,
                'subLocation' => $subLocation,
                'search' => $search,
                'version' => $version ? Carbon::parse($version)->toISOString() : null,
                'source' => 'database',
            ],
        ]);
    }

    public function coverage(Request $request): JsonResponse
    {
        $this->ensureInspectionPermission($request);

        $zone = Str::of((string) $request->query('zone', ''))->squish()->toString();
        $mainLocation = Str::of((string) $request->query('mainLocation', $request->query('location', '')))->squish()->toString();
        $search = Str::of((string) $request->query('search', ''))->squish()->toString();
        $period = Str::of((string) $request->query('period', 'all'))->squish()->lower()->toString();
        $periodFrom = Str::of((string) $request->query('periodFrom', $request->query('from', '')))->squish()->toString();
        $periodTo = Str::of((string) $request->query('periodTo', $request->query('to', '')))->squish()->toString();
        $status = Str::of((string) $request->query('status', 'all'))->squish()->lower()->toString();
        $issues = Str::of((string) $request->query('issues', 'all'))->squish()->lower()->toString();
        $certification = Str::of((string) $request->query('certification', 'all'))->squish()->lower()->toString();
        $inspectedBy = Str::of((string) $request->query('inspectedBy', $request->query('lastInspectedBy', 'all')))->squish()->toString();
        $sort = Str::of((string) $request->query('sort', 'zone-location'))->squish()->lower()->toString();
        $direction = Str::of((string) $request->query('direction', 'asc'))->squish()->lower()->toString() === 'desc'
            ? 'desc'
            : 'asc';
        $page = max(1, (int) $request->query('page', 1));
        $perPageQuery = Str::of((string) $request->query('perPage', $request->query('per_page', 10)))->squish()->lower()->toString();
        $perPage = $perPageQuery === 'all' ? 'all' : min(100, max(1, (int) $perPageQuery ?: 10));

        $query = InspectionFireExtinguisher::query()->where('is_active', true);
        if ($zone !== '') {
            $query->where('zone', $zone);
        }
        if ($mainLocation !== '') {
            $query->where('main_location_name', $mainLocation);
        }
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $like = "%{$search}%";
                $builder
                    ->where('zone', 'like', $like)
                    ->orWhere('main_location_name', 'like', $like)
                    ->orWhere('sub_location_name', 'like', $like)
                    ->orWhere('id_loc_no', 'like', $like)
                    ->orWhere('barcode_no', 'like', $like)
                    ->orWhere('fe_type', 'like', $like)
                    ->orWhere('certification_validity', 'like', $like);
            });
        }

        $total = (clone $query)->count();
        $catalogRows = $query
            ->orderBy('zone')
            ->orderBy('main_location_name')
            ->orderBy('sub_location_name')
            ->orderBy('id_loc_no')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        [$periodStart, $periodEnd] = $this->coveragePeriodRange($period, $periodFrom, $periodTo);
        $coverageRows = $this->coverageRowsForCatalog($catalogRows, $periodStart, $periodEnd);
        $data = $catalogRows
            ->map(fn (InspectionFireExtinguisher $row) => $this->formatCoverageRow(
                $row,
                $coverageRows[(int) $row->id] ?? null,
            ))
            ->values();
        $filteredData = $this->filterCoverageData($data, [
            'status' => $status,
            'issues' => $issues,
            'certification' => $certification,
            'inspectedBy' => $inspectedBy,
        ]);
        $sortedData = $this->sortCoverageData($filteredData, $sort, $direction);
        $filteredCount = $sortedData->count();
        $lastPage = $perPage === 'all' ? 1 : max(1, (int) ceil($filteredCount / $perPage));
        $page = min($page, $lastPage);
        $pagedData = $perPage === 'all'
            ? $sortedData->values()
            : $sortedData->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $pagedData,
            'meta' => [
                'source' => 'database',
                'period' => $period,
                'periodFrom' => $periodStart?->toDateString(),
                'periodTo' => $periodEnd?->toDateString(),
                'zone' => $zone,
                'mainLocation' => $mainLocation,
                'search' => $search,
                'status' => $status,
                'issues' => $issues,
                'certification' => $certification,
                'inspectedBy' => $inspectedBy,
                'sort' => $sort,
                'direction' => $direction,
                'page' => $page,
                'perPage' => $perPage,
                'lastPage' => $lastPage,
                'total' => $total,
                'filtered' => $filteredCount,
                'summary' => $this->coverageSummary($filteredData),
                'options' => $this->coverageFilterOptions($data),
            ],
        ]);
    }

    public function coverageDetail(Request $request, int $extinguisherId): JsonResponse
    {
        $this->ensureInspectionPermission($request);

        $period = Str::of((string) $request->query('period', 'all'))->squish()->lower()->toString();
        $periodFrom = Str::of((string) $request->query('periodFrom', $request->query('from', '')))->squish()->toString();
        $periodTo = Str::of((string) $request->query('periodTo', $request->query('to', '')))->squish()->toString();
        $row = $this->findActiveRow($extinguisherId);
        [$periodStart, $periodEnd] = $this->coveragePeriodRange($period, $periodFrom, $periodTo);
        $coverageRows = $this->coverageRowsForCatalog(collect([$row]), $periodStart, $periodEnd);
        $coverage = $coverageRows[(int) $row->id] ?? null;

        return response()->json([
            'data' => array_merge($this->formatCoverageRow($row, $coverage), [
                'checks' => $coverage['checks'] ?? $this->emptyCoverageChecks(),
                'duplicateReports' => $coverage['duplicateReports'] ?? [],
            ]),
            'meta' => [
                'source' => 'database',
                'period' => $period,
                'periodFrom' => $periodStart?->toDateString(),
                'periodTo' => $periodEnd?->toDateString(),
            ],
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $this->ensureInspectionPermission($request);

        $locator = $this->locatorPart($request->query('locator', ''));
        if ($locator === '') {
            throw ValidationException::withMessages([
                'locator' => ['A fire extinguisher locator is required.'],
            ]);
        }

        $rows = InspectionFireExtinguisher::query()
            ->where('is_active', true)
            ->whereNotNull('barcode_no')
            ->whereRaw('LOWER(TRIM(barcode_no)) = ?', [$locator])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($rows->count() === 0) {
            abort(Response::HTTP_NOT_FOUND, 'Fire extinguisher locator was not found.');
        }

        if ($rows->count() > 1) {
            $lastInspections = $this->latestInspectionsForRows($rows);

            return response()->json([
                'message' => 'Multiple active fire extinguishers use this locator.',
                'data' => $rows
                    ->map(fn (InspectionFireExtinguisher $row) => $this->formatRow(
                        $row,
                        $request,
                        $lastInspections[(int) $row->id] ?? null,
                    ))
                    ->values(),
                'meta' => [
                    'locator' => $request->query('locator', ''),
                    'normalizedLocator' => $locator,
                    'count' => $rows->count(),
                ],
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'data' => $this->formatRow(
                $rows->first(),
                $request,
                $this->latestInspectionsForRows($rows)[(int) $rows->first()->id] ?? null,
            ),
            'meta' => [
                'locator' => $request->query('locator', ''),
                'normalizedLocator' => $locator,
                'source' => 'database',
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureInspectionPermission($request);

        $data = $request->validate($this->rules());
        $row = DB::transaction(function () use ($data, $request) {
            $this->assertUniqueActiveIdentity($data, null, true);
            $this->assertUniqueActiveLocator($data, null, true);

            return InspectionFireExtinguisher::query()->create($this->payloadToAttributes($data, [
                'source' => 'custom',
                'created_by' => $request->user()?->id,
                'is_active' => true,
                'sort_order' => $this->nextSortOrder((string) ($data['mainLocation'] ?? $data['main_location'] ?? '')),
            ]));
        });

        return response()->json(['data' => $this->formatRow($row, $request)], 201);
    }

    public function update(Request $request, int $extinguisherId): JsonResponse
    {
        $this->ensureInspectionPermission($request);
        $row = $this->findActiveRow($extinguisherId);

        $data = $request->validate($this->rules());
        $attributes = $this->payloadToAttributes($data);
        if ($this->locatorChanged($row, $data)) {
            $this->assertUniqueActiveLocator($data, $row->id);
        }
        if ($row->source === 'custom') {
            $this->assertUniqueActiveIdentity($data, $row->id);
        } else {
            $attributes['active_identity_key'] = null;
        }
        $row->fill($attributes)->save();

        return response()->json(['data' => $this->formatRow($row, $request)]);
    }

    public function destroy(Request $request, int $extinguisherId): JsonResponse|Response
    {
        $this->ensureInspectionPermission($request);
        $row = $this->findActiveRow($extinguisherId);

        $row->update([
            'is_active' => false,
            'active_identity_key' => null,
        ]);

        return response()->noContent();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        return [
            'zone' => ['nullable', 'string', 'max:80'],
            'mainLocation' => ['required_without:main_location', 'string', 'max:190'],
            'main_location' => ['nullable', 'string', 'max:190'],
            'subLocation' => ['nullable', 'string', 'max:190'],
            'sub_location' => ['nullable', 'string', 'max:190'],
            'idLocNo' => ['nullable', 'string', 'max:190'],
            'id_loc_no' => ['nullable', 'string', 'max:190'],
            'barcodeNo' => ['nullable', 'string', 'max:190'],
            'barcode_no' => ['nullable', 'string', 'max:190'],
            'feType' => ['nullable', 'string', 'max:120'],
            'fe_type' => ['nullable', 'string', 'max:120'],
            'certificationValidity' => ['nullable', 'date'],
            'certification_validity' => ['nullable', 'date'],
        ];
    }

    private function findActiveRow(int $id): InspectionFireExtinguisher
    {
        return InspectionFireExtinguisher::query()->where('is_active', true)->findOrFail($id);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function payloadToAttributes(array $data, array $extra = []): array
    {
        $validity = trim((string) ($data['certificationValidity'] ?? $data['certification_validity'] ?? ''));

        return array_merge([
            'zone' => $this->text($data['zone'] ?? '') ?: null,
            'main_location_name' => $this->text($data['mainLocation'] ?? $data['main_location'] ?? ''),
            'sub_location_name' => $this->text($data['subLocation'] ?? $data['sub_location'] ?? '') ?: null,
            'id_loc_no' => $this->text($data['idLocNo'] ?? $data['id_loc_no'] ?? '') ?: null,
            'barcode_no' => $this->text($data['barcodeNo'] ?? $data['barcode_no'] ?? '') ?: null,
            'active_identity_key' => $this->activeIdentityKey($data),
            'fe_type' => $this->normalizeFeType($data['feType'] ?? $data['fe_type'] ?? '') ?: null,
            'certification_validity' => $validity !== '' ? $validity : null,
        ], $extra);
    }

    private function formatRow(
        InspectionFireExtinguisher $row,
        Request $request,
        ?InspectionCheckRow $lastInspection = null,
    ): array
    {
        $validity = $row->certification_validity;
        $activeIdentityKey = (string) ($row->active_identity_key ?? '');
        $canonicalAssetKey = $this->canonicalAssetKey($row);

        return [
            'id' => $row->id,
            'catalogId' => $row->id,
            'canonicalAssetKey' => $canonicalAssetKey,
            'activeIdentityKey' => $activeIdentityKey,
            'sourceRowNumber' => $row->source_row_number,
            'source' => $row->source,
            'equipmentSource' => $row->source,
            'zone' => (string) ($row->zone ?? ''),
            'mainLocation' => $row->main_location_name,
            'location' => $row->main_location_name,
            'subLocation' => (string) ($row->sub_location_name ?? ''),
            'idLocNo' => (string) ($row->id_loc_no ?? ''),
            'barcodeNo' => (string) ($row->barcode_no ?? ''),
            'feType' => $this->normalizeFeType($row->fe_type ?? ''),
            'certificationValidity' => $validity ? $validity->format('Y-m-d') : '',
            'daysLeftToExpire' => $this->daysLeftToExpire($validity),
            'sortOrder' => $row->sort_order,
            'isActive' => $row->is_active,
            'canEdit' => true,
            'canDelete' => true,
            'lastInspection' => $this->formatLastInspection($lastInspection),
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @param array<string, string> $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function filterCoverageData(Collection $rows, array $filters): Collection
    {
        return $rows
            ->filter(function (array $row) use ($filters): bool {
                $inspectionStatus = $this->coverageInspectionStatus($row);
                $certificationStatus = $this->coverageCertificationStatus($row);
                $status = $filters['status'] ?? 'all';
                $issues = $filters['issues'] ?? 'all';
                $certification = $filters['certification'] ?? 'all';
                $inspectedBy = $filters['inspectedBy'] ?? 'all';

                if ($status !== 'all' && $inspectionStatus !== $status) {
                    return false;
                }
                if ($inspectedBy !== 'all' && $this->text($row['inspectedBy'] ?? '') !== $inspectedBy) {
                    return false;
                }
                if ($issues === 'with-issues' && (int) ($row['issueCount'] ?? 0) <= 0) {
                    return false;
                }
                if ($issues === 'no-issues' && (int) ($row['issueCount'] ?? 0) > 0) {
                    return false;
                }
                if ($certification !== 'all' && $certificationStatus !== $certification) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sortCoverageData(Collection $rows, string $sort, string $direction = 'asc'): Collection
    {
        $sorted = $rows->sort(function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'latest' => $this->compareCoverageValues($b['latestInspectionAt'] ?? '', $a['latestInspectionAt'] ?? ''),
                'days-left' => $this->compareCoverageValues((int) ($a['daysLeft'] ?? 0), (int) ($b['daysLeft'] ?? 0)),
                'issues' => $this->compareCoverageValues((int) ($b['issueCount'] ?? 0), (int) ($a['issueCount'] ?? 0)),
                'duplicates', 'reports' => $this->compareCoverageValues((int) ($b['duplicateCount'] ?? 0), (int) ($a['duplicateCount'] ?? 0)),
                default => $this->compareCoverageLocation($a, $b),
            };
        })->values();

        return $direction === 'desc' && $sort === 'zone-location'
            ? $sorted->reverse()->values()
            : $sorted;
    }

    private function compareCoverageLocation(array $a, array $b): int
    {
        $zoneCompare = $this->compareCoverageValues(
            $this->coverageZoneSortValue((string) ($a['zone'] ?? '')),
            $this->coverageZoneSortValue((string) ($b['zone'] ?? '')),
        );
        if ($zoneCompare !== 0) {
            return $zoneCompare;
        }

        return strnatcasecmp(
            implode(' ', [
                (string) ($a['zone'] ?? ''),
                (string) ($a['location'] ?? ''),
                (string) ($a['subLocation'] ?? ''),
                (string) ($a['idLocNo'] ?? ''),
            ]),
            implode(' ', [
                (string) ($b['zone'] ?? ''),
                (string) ($b['location'] ?? ''),
                (string) ($b['subLocation'] ?? ''),
                (string) ($b['idLocNo'] ?? ''),
            ]),
        );
    }

    private function compareCoverageValues(mixed $a, mixed $b): int
    {
        return $a <=> $b;
    }

    private function coverageZoneSortValue(string $zone): int
    {
        if (preg_match('/^zone\s+(\d+)/i', trim($zone), $match) === 1) {
            return (int) $match[1];
        }
        if (preg_match('/^\d+$/', trim($zone)) === 1) {
            return (int) $zone;
        }

        return PHP_INT_MAX;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function coverageInspectionStatus(array $row): string
    {
        if ($this->text($row['latestInspectionAt'] ?? '') === '') {
            return 'not-inspected';
        }
        if ((int) ($row['issueCount'] ?? 0) > 0) {
            return 'issues';
        }
        if ((int) ($row['duplicateCount'] ?? 0) > 1) {
            return 'duplicates';
        }

        return 'inspected';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function coverageCertificationStatus(array $row): string
    {
        if ($this->text($row['daysLeft'] ?? '') === '') {
            return 'unknown';
        }

        $days = (int) $row['daysLeft'];
        if ($days < 0) {
            return 'expired';
        }
        if ($days <= 20) {
            return 'expiring';
        }

        return 'valid';
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function coverageFilterOptions(?Collection $coverageRows = null): array
    {
        $rows = InspectionFireExtinguisher::query()
            ->where('is_active', true)
            ->get(['zone', 'main_location_name']);

        return [
            'zones' => $rows
                ->pluck('zone')
                ->map(fn ($value): string => $this->text($value))
                ->filter()
                ->unique()
                ->sort(fn (string $a, string $b): int => $this->compareCoverageValues(
                    $this->coverageZoneSortValue($a),
                    $this->coverageZoneSortValue($b),
                ) ?: strnatcasecmp($a, $b))
                ->values()
                ->all(),
            'locations' => $rows
                ->pluck('main_location_name')
                ->map(fn ($value): string => $this->text($value))
                ->filter()
                ->unique()
                ->sort(fn (string $a, string $b): int => strnatcasecmp($a, $b))
                ->values()
                ->all(),
            'inspectors' => ($coverageRows ?? collect())
                ->pluck('inspectedBy')
                ->map(fn ($value): string => $this->text($value))
                ->filter()
                ->unique()
                ->sort(fn (string $a, string $b): int => strnatcasecmp($a, $b))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param Collection<int, InspectionFireExtinguisher> $catalogRows
     * @return array<int, array<string, mixed>>
     */
    private function coverageRowsForCatalog(
        Collection $catalogRows,
        ?Carbon $periodStart = null,
        ?Carbon $periodEnd = null,
    ): array
    {
        $catalogIds = $catalogRows
            ->pluck('id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->values();

        if ($catalogIds->isEmpty()) {
            return [];
        }

        $query = InspectionCheckRow::query()
            ->with(['submittedBy:id,name', 'report:id,display_id,payload'])
            ->where('inspection_type_key', self::FIRE_EXTINGUISHER_INSPECTION_TYPE_KEY)
            ->where('source_payload_key', self::FIRE_EXTINGUISHER_SOURCE_PAYLOAD_KEY)
            ->whereIn('equipment_catalog_id', $catalogIds)
            ->whereNotNull('submitted_at');

        if ($periodStart) {
            $query->where('submitted_at', '>=', $periodStart);
        }
        if ($periodEnd) {
            $query->where('submitted_at', '<=', $periodEnd);
        }

        $rowsByCatalogId = $query
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (InspectionCheckRow $row): int => (int) $row->equipment_catalog_id);

        return $catalogRows
            ->mapWithKeys(function (InspectionFireExtinguisher $catalogRow) use ($rowsByCatalogId): array {
                $checkRows = $rowsByCatalogId->get((int) $catalogRow->id, collect());
                if ($checkRows->isEmpty()) {
                    return [(int) $catalogRow->id => null];
                }

                return [(int) $catalogRow->id => $this->buildCoverageData($catalogRow, $checkRows)];
            })
            ->filter()
            ->all();
    }

    /**
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function coveragePeriodRange(string $period, string $periodFrom = '', string $periodTo = ''): array
    {
        return match ($period) {
            'today' => [now()->startOfDay(), now()->endOfDay()],
            'thisweek', 'this-week', 'this_week' => [now()->startOfWeek(), now()->endOfWeek()],
            'thismonth', 'this-month', 'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
            'lastmonth', 'last-month', 'last_month' => [
                now()->subMonthNoOverflow()->startOfMonth(),
                now()->subMonthNoOverflow()->endOfMonth(),
            ],
            'last7', 'last-7', 'last_7' => [now()->startOfDay()->subDays(7), now()->endOfDay()],
            'last30', 'last-30', 'last_30' => [now()->startOfDay()->subDays(30), now()->endOfDay()],
            'last90', 'last-90', 'last_90' => [now()->startOfDay()->subDays(90), now()->endOfDay()],
            'custom' => $this->customCoveragePeriodRange($periodFrom, $periodTo),
            default => [null, null],
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function customCoveragePeriodRange(string $periodFrom, string $periodTo): array
    {
        $start = $this->coverageDateBoundary($periodFrom);
        $end = $this->coverageDateBoundary($periodTo, true);

        if (! $start || ! $end || $start->gt($end)) {
            throw ValidationException::withMessages([
                'period' => ['A valid custom period range is required.'],
            ]);
        }

        return [$start, $end];
    }

    private function coverageDateBoundary(string $date, bool $endOfDay = false): ?Carbon
    {
        $date = $this->text($date);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat('Y-m-d', $date);
        } catch (\Throwable) {
            return null;
        }

        if (! $parsed instanceof Carbon) {
            return null;
        }

        return $endOfDay ? $parsed->endOfDay() : $parsed->startOfDay();
    }

    /**
     * @param Collection<int, InspectionCheckRow> $checkRows
     * @return array<string, mixed>
     */
    private function buildCoverageData(InspectionFireExtinguisher $catalogRow, Collection $checkRows): array
    {
        /** @var InspectionCheckRow $latestRow */
        $latestRow = $checkRows->first();
        $latestSourceRowId = $this->text($latestRow->source_row_id ?? '');
        $latestGroup = $checkRows
            ->filter(function (InspectionCheckRow $row) use ($latestRow, $latestSourceRowId): bool {
                if ((int) $row->report_id !== (int) $latestRow->report_id) {
                    return false;
                }
                if ($latestSourceRowId === '') {
                    return true;
                }

                return $this->text($row->source_row_id ?? '') === $latestSourceRowId;
            })
            ->values();

        $payloadItem = $this->findCoveragePayloadItem($latestRow->report, $latestRow, $catalogRow);
        $checks = $this->formatCoverageChecks($latestGroup, $payloadItem);
        $remarks = $this->coverageRemarks($latestGroup, $payloadItem);
        $duplicateReports = $checkRows
            ->unique('report_id')
            ->map(fn (InspectionCheckRow $row): array => [
                'reportId' => (int) $row->report_id,
                'displayId' => (string) $row->display_id,
                'submittedAt' => $row->submitted_at?->toIso8601String(),
                'submittedBy' => (string) ($row->submittedBy?->name ?? ''),
            ])
            ->values()
            ->all();

        return [
            'latestRow' => $latestRow,
            'checks' => $checks,
            'remarks' => $remarks,
            'issueCount' => $latestGroup->where('has_defect', true)->count(),
            'evidenceCount' => $latestGroup->sum(fn (InspectionCheckRow $row): int => (int) $row->evidence_count),
            'duplicateCount' => count($duplicateReports),
            'duplicateReports' => $duplicateReports,
        ];
    }

    /**
     * @param Collection<int, InspectionCheckRow> $latestGroup
     * @param array<string, mixed> $payloadItem
     * @return array<int, array<string, mixed>>
     */
    private function formatCoverageChecks(Collection $latestGroup, array $payloadItem = []): array
    {
        $rowsByCheckKey = $latestGroup->keyBy('check_key');

        return collect(self::COVERAGE_CHECK_FIELDS)
            ->map(function (array $field, string $columnKey) use ($rowsByCheckKey, $payloadItem): array {
                /** @var InspectionCheckRow|null $row */
                $row = $rowsByCheckKey->get($field['checkKey']);
                $photos = $this->coveragePhotos($payloadItem[$field['photosKey']] ?? []);

                return [
                    'key' => $columnKey,
                    'checkKey' => $field['checkKey'],
                    'label' => $field['label'],
                    'value' => (string) ($row?->check_value ?? ($payloadItem[$field['payloadKey']] ?? '')),
                    'hasDefect' => (bool) ($row?->has_defect ?? false),
                    'remarks' => (string) ($row?->remarks ?? ($payloadItem[$field['remarksKey']] ?? '')),
                    'evidenceCount' => (int) ($row?->evidence_count ?? count($photos)),
                    'photos' => $photos,
                    'reportId' => $row ? (int) $row->report_id : null,
                    'displayId' => $row ? (string) $row->display_id : '',
                    'submittedAt' => $row?->submitted_at?->toIso8601String(),
                    'submittedBy' => (string) ($row?->submittedBy?->name ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function emptyCoverageChecks(): array
    {
        return collect(self::COVERAGE_CHECK_FIELDS)
            ->map(fn (array $field, string $columnKey): array => [
                'key' => $columnKey,
                'checkKey' => $field['checkKey'],
                'label' => $field['label'],
                'value' => '',
                'hasDefect' => false,
                'remarks' => '',
                'evidenceCount' => 0,
                'photos' => [],
                'reportId' => null,
                'displayId' => '',
                'submittedAt' => null,
                'submittedBy' => '',
            ])
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, InspectionCheckRow> $latestGroup
     * @param array<string, mixed> $payloadItem
     */
    private function coverageRemarks(Collection $latestGroup, array $payloadItem = []): string
    {
        $remarks = $latestGroup
            ->pluck('remarks')
            ->map(fn ($remark): string => $this->text($remark))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $generalRemark = $this->text($payloadItem['remarks'] ?? '');
        if ($generalRemark !== '') {
            $remarks[] = $generalRemark;
        }

        return collect($remarks)->unique()->implode('; ');
    }

    /**
     * @return array<string, mixed>
     */
    private function findCoveragePayloadItem(?Report $report, InspectionCheckRow $row, InspectionFireExtinguisher $catalogRow): array
    {
        $payload = is_array($report?->payload) ? $report->payload : [];
        $checks = $payload[self::FIRE_EXTINGUISHER_SOURCE_PAYLOAD_KEY] ?? $payload['fire_extinguisher_checks'] ?? [];
        if (! is_array($checks)) {
            return [];
        }

        $catalogId = (string) $catalogRow->id;
        $sourceRowId = $this->text($row->source_row_id ?? '');
        $idLocNo = $this->text($catalogRow->id_loc_no ?? '');
        $barcodeNo = $this->text($catalogRow->barcode_no ?? '');

        foreach ($checks as $item) {
            if (! is_array($item)) {
                continue;
            }

            $itemCatalogId = $this->text($item['catalogId'] ?? $item['catalog_id'] ?? '');
            $itemId = $this->text($item['id'] ?? '');
            $itemIdLocNo = $this->text($item['idLocNo'] ?? $item['id_loc_no'] ?? '');
            $itemBarcodeNo = $this->text($item['barcodeNo'] ?? $item['barcode_no'] ?? '');

            if ($itemCatalogId !== '' && $itemCatalogId === $catalogId) {
                return $item;
            }
            if ($sourceRowId !== '' && $itemId === $sourceRowId) {
                return $item;
            }
            if ($idLocNo !== '' && $itemIdLocNo === $idLocNo) {
                return $item;
            }
            if ($barcodeNo !== '' && $itemBarcodeNo === $barcodeNo) {
                return $item;
            }
        }

        return [];
    }

    /**
     * @return array<int, mixed>
     */
    private function coveragePhotos(mixed $photos): array
    {
        if (! is_array($photos)) {
            return [];
        }

        return collect($photos)
            ->filter(fn ($photo): bool => is_array($photo) || is_string($photo))
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed>|null $coverage
     * @return array<string, mixed>
     */
    private function formatCoverageRow(InspectionFireExtinguisher $row, ?array $coverage = null): array
    {
        $validity = $row->certification_validity;
        $latestRow = $coverage['latestRow'] ?? null;
        $checks = collect($coverage['checks'] ?? []);
        $reportCount = (int) ($coverage['duplicateCount'] ?? 0);

        $checkValue = fn (string $key): string => (string) ($checks->firstWhere('key', $key)['value'] ?? '');

        return [
            'id' => 'fe-coverage-'.$row->id,
            'catalogId' => $row->id,
            'canonicalAssetKey' => $this->canonicalAssetKey($row),
            'zone' => (string) ($row->zone ?? ''),
            'location' => $row->main_location_name,
            'mainLocation' => $row->main_location_name,
            'subLocation' => (string) ($row->sub_location_name ?? ''),
            'idLocNo' => (string) ($row->id_loc_no ?? ''),
            'feType' => $this->normalizeFeType($row->fe_type ?? ''),
            'barcodeNo' => (string) ($row->barcode_no ?? ''),
            'certificationValidity' => $validity ? $validity->format('Y-m-d') : '',
            'daysLeft' => $this->daysLeftToExpire($validity),
            'daysLeftToExpire' => $this->daysLeftToExpire($validity),
            'physical' => $checkValue('physical'),
            'signage' => $checkValue('signage'),
            'boxKey' => $checkValue('boxKey'),
            'boxGlass' => $checkValue('boxGlass'),
            'operational' => $checkValue('operational'),
            'inspectedBy' => $latestRow instanceof InspectionCheckRow ? (string) ($latestRow->submittedBy?->name ?? '') : '',
            'inspectionDate' => $latestRow instanceof InspectionCheckRow ? $latestRow->submitted_at?->toIso8601String() : null,
            'latestInspectionAt' => $latestRow instanceof InspectionCheckRow ? $latestRow->submitted_at?->toIso8601String() : null,
            'remarks' => (string) ($coverage['remarks'] ?? ''),
            'issueCount' => (int) ($coverage['issueCount'] ?? 0),
            'evidenceCount' => (int) ($coverage['evidenceCount'] ?? 0),
            'reportCount' => $reportCount,
            'repeatCount' => max(0, $reportCount - 1),
            'duplicateCount' => $reportCount,
            'latestReportId' => $latestRow instanceof InspectionCheckRow ? (string) $latestRow->display_id : '',
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function coverageSummary(Collection $rows): array
    {
        return [
            'total' => $rows->count(),
            'inspected' => $rows->filter(fn (array $row): bool => (string) ($row['latestInspectionAt'] ?? '') !== '')->count(),
            'notInspected' => $rows->filter(fn (array $row): bool => (string) ($row['latestInspectionAt'] ?? '') === '')->count(),
            'issues' => $rows->filter(fn (array $row): bool => (int) ($row['issueCount'] ?? 0) > 0)->count(),
            'duplicates' => $rows->filter(fn (array $row): bool => (int) ($row['duplicateCount'] ?? 0) > 1)->count(),
            'expired' => $rows->filter(fn (array $row): bool => (int) ($row['daysLeft'] ?? 0) < 0)->count(),
        ];
    }

    /**
     * @param Collection<int, InspectionFireExtinguisher> $rows
     * @return array<int, InspectionCheckRow>
     */
    private function latestInspectionsForRows(Collection $rows): array
    {
        $catalogIds = $rows
            ->pluck('id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->values();

        if ($catalogIds->isEmpty()) {
            return [];
        }

        return InspectionCheckRow::query()
            ->with('submittedBy:id,name')
            ->where('inspection_type_key', 'fire-extinguisher-inspection')
            ->whereIn('equipment_catalog_id', $catalogIds)
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get()
            ->unique('equipment_catalog_id')
            ->keyBy('equipment_catalog_id')
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatLastInspection(?InspectionCheckRow $row): ?array
    {
        if (! $row || ! $row->submitted_at) {
            return null;
        }

        $submittedAt = $row->submitted_at->toIso8601String();
        $submittedBy = (string) ($row->submittedBy?->name ?? '');

        return [
            'inspectedAt' => $submittedAt,
            'submittedAt' => $submittedAt,
            'inspectedBy' => $submittedBy,
            'submittedBy' => $submittedBy,
            'reportId' => (int) $row->report_id,
            'displayId' => (string) $row->display_id,
        ];
    }

    private function canonicalAssetKey(InspectionFireExtinguisher $row): string
    {
        if ($row->id) {
            return 'catalog:'.$row->id;
        }

        $activeIdentityKey = $this->text($row->active_identity_key ?? '');
        if ($activeIdentityKey !== '') {
            return 'identity:'.$activeIdentityKey;
        }

        $barcodeNo = $this->identityPart($row->barcode_no ?? '');
        if ($barcodeNo !== '') {
            return 'barcode:'.$barcodeNo;
        }

        $idLocNo = $this->identityPart($row->id_loc_no ?? '');
        $mainLocation = $this->identityPart($row->main_location_name ?? '');
        if ($idLocNo !== '' && $mainLocation !== '') {
            return 'location:'.hash('sha256', implode('|', [
                $this->identityPart($row->zone ?? ''),
                $mainLocation,
                $this->identityPart($row->sub_location_name ?? ''),
                $idLocNo,
            ]));
        }

        return '';
    }

    private function nextSortOrder(string $mainLocation): int
    {
        return ((int) InspectionFireExtinguisher::query()
            ->where('main_location_name', $this->text($mainLocation))
            ->max('sort_order')) + 1;
    }

    private function daysLeftToExpire(mixed $validity): string
    {
        if (! $validity) {
            return '';
        }

        $expiry = $validity instanceof Carbon ? $validity : Carbon::parse($validity);

        return (string) now()->startOfDay()->diffInDays($expiry->copy()->startOfDay(), false);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertUniqueActiveLocator(array $data, ?int $ignoreId = null, bool $lock = false): void
    {
        $locator = $this->locatorPart($data['barcodeNo'] ?? $data['barcode_no'] ?? '');
        if ($locator === '') {
            return;
        }

        $duplicateExists = InspectionFireExtinguisher::query()
            ->where('is_active', true)
            ->whereNotNull('barcode_no')
            ->whereRaw('LOWER(TRIM(barcode_no)) = ?', [$locator])
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->exists();

        if (! $duplicateExists) {
            return;
        }

        throw ValidationException::withMessages([
            'idLocNo' => 'An active fire extinguisher with the same S/N, QR, or barcode already exists.',
            'barcodeNo' => 'An active fire extinguisher with the same S/N, QR, or barcode already exists.',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function locatorChanged(InspectionFireExtinguisher $row, array $data): bool
    {
        return $this->locatorPart($row->barcode_no ?? '') !== $this->locatorPart($data['barcodeNo'] ?? $data['barcode_no'] ?? '');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertUniqueActiveIdentity(array $data, ?int $ignoreId = null, bool $lock = false): void
    {
        $identityKey = $this->activeIdentityKey($data);
        if (! $identityKey) {
            return;
        }

        $duplicateExists = InspectionFireExtinguisher::query()
            ->where('active_identity_key', $identityKey)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->exists();

        if (! $duplicateExists) {
            return;
        }

        throw ValidationException::withMessages([
            'idLocNo' => 'An active fire extinguisher with the same location, ID/location number, and barcode already exists.',
            'barcodeNo' => 'An active fire extinguisher with the same location, ID/location number, and barcode already exists.',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function activeIdentityKey(array $data): ?string
    {
        $mainLocation = $this->identityPart($data['mainLocation'] ?? $data['main_location'] ?? '');
        $subLocation = $this->identityPart($data['subLocation'] ?? $data['sub_location'] ?? '');
        $idLocNo = $this->identityPart($data['idLocNo'] ?? $data['id_loc_no'] ?? '');
        $barcodeNo = $this->identityPart($data['barcodeNo'] ?? $data['barcode_no'] ?? '');

        if ($mainLocation === '' || ($idLocNo === '' && $barcodeNo === '')) {
            return null;
        }

        return hash('sha256', implode('|', [$mainLocation, $subLocation, $idLocNo, $barcodeNo]));
    }

    private function identityPart(mixed $value): string
    {
        return Str::of($this->normalizeFeType($value))->squish()->lower()->toString();
    }

    private function locatorPart(mixed $value): string
    {
        $locator = Str::of((string) $value)->squish()->toString();
        $locator = preg_replace(
            '/^(?:s\s*\/?\s*n|serial(?:\s*(?:number|no\.?))?|barcode)\s*[:#-]?\s*/i',
            '',
            $locator,
        ) ?? $locator;

        return Str::of($locator)->squish()->lower()->toString();
    }

    private function ensureInspectionPermission(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! $this->authorizationService->hasPermission($user, 'reports.manage|reports.inspection.view')) {
            abort(403, 'Missing inspection report permission.');
        }
    }

    private function text(mixed $value): string
    {
        return Str::of((string) $value)->squish()->toString();
    }

    private function normalizeFeType(mixed $value): string
    {
        return str_replace(["CO\u{00B2}", "CO\u{FFFD}"], 'CO2', $this->text($value));
    }
}
