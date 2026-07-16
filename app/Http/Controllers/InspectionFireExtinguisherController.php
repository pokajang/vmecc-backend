<?php

namespace App\Http\Controllers;

use App\Models\InspectionCheckRow;
use App\Models\InspectionFireExtinguisher;
use App\Models\InspectionFireExtinguisherIssue;
use App\Models\InspectionFireExtinguisherIssueOccurrence;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\InspectionFireExtinguisherBatchCreator;
use App\Services\InspectionFireExtinguishers\FireExtinguisherCoverageService;
use App\Services\InspectionFireExtinguishers\FireExtinguisherIssueWorkflowService;
use App\Services\InspectionSiteLocationCatalogService;
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
    private const DUPLICATE_LOCATOR_CODE = 'FIRE_EXTINGUISHER_DUPLICATE_LOCATOR';

    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
        private readonly InspectionFireExtinguisherBatchCreator $batchCreator,
        private readonly InspectionSiteLocationCatalogService $siteLocationCatalog,
        private readonly FireExtinguisherCoverageService $coverageService,
        private readonly FireExtinguisherIssueWorkflowService $issueWorkflow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureInspectionPermission($request);

        $zone = Str::of((string) $request->query('zone', ''))->squish()->toString();
        $mainLocation = Str::of((string) $request->query('mainLocation', ''))->squish()->toString();
        $subLocation = Str::of((string) $request->query('subLocation', ''))->squish()->toString();
        $search = Str::of((string) $request->query('search', ''))->squish()->toString();

        $lifecycle = strtolower($this->text($request->query('lifecycleStatus', 'active'))) ?: 'active';
        $query = InspectionFireExtinguisher::query()->withCount([
            'issues as open_issues_count' => fn ($builder) => $builder->whereIn('status', InspectionFireExtinguisherIssue::ACTIVE_STATUSES),
        ]);
        if ($lifecycle === 'all') {
            // Lifecycle management view includes retired assets.
        } elseif ($lifecycle === 'retired') {
            $query->where('lifecycle_status', 'retired');
        } elseif ($lifecycle === 'out_of_service') {
            $query->where('lifecycle_status', 'out_of_service');
        } else {
            $query->where('lifecycle_status', 'active')->where('is_active', true);
        }
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
        $result = $this->coverageService->build($request->query());
        $page = max(1, (int) $request->query('page', 1));
        $perPageQuery = Str::of((string) $request->query('perPage', $request->query('per_page', 10)))->squish()->lower()->toString();
        $perPage = $perPageQuery === 'all' ? 'all' : min(100, max(1, (int) $perPageQuery ?: 10));
        $sortedData = $result['rows'];
        $filteredCount = (int) $result['filtered'];
        $lastPage = $perPage === 'all' ? 1 : max(1, (int) ceil($filteredCount / $perPage));
        $page = min($page, $lastPage);
        $pagedData = $perPage === 'all'
            ? $sortedData->values()
            : $sortedData->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $pagedData,
            'meta' => [
                'source' => 'database',
                'period' => $result['filters']['period'],
                'periodFrom' => $result['periodStart']?->toDateString(),
                'periodTo' => $result['periodEnd']?->toDateString(),
                'zone' => $result['filters']['zone'],
                'mainLocation' => $result['filters']['location'],
                'search' => $result['filters']['search'],
                'status' => $result['filters']['status'],
                'issues' => $result['filters']['issues'],
                'certification' => $result['filters']['certification'],
                'inspectedBy' => $result['filters']['inspectedBy'],
                'duplicateScope' => $result['filters']['duplicateScope'],
                'sort' => $result['filters']['sort'],
                'direction' => $result['filters']['direction'],
                'page' => $page,
                'perPage' => $perPage,
                'lastPage' => $lastPage,
                'total' => $result['total'],
                'filtered' => $filteredCount,
                'summary' => $result['summary'],
                'options' => $result['options'],
            ],
        ]);
    }

    public function coverageDetail(Request $request, int $extinguisherId): JsonResponse
    {
        $this->ensureInspectionPermission($request);
        $row = InspectionFireExtinguisher::query()->findOrFail($extinguisherId);
        $result = $this->coverageService->detail($row, $request->query());

        return response()->json([
            'data' => $result['row'],
            'meta' => [
                'source' => 'database',
                'period' => $result['filters']['period'],
                'periodFrom' => $result['periodStart']?->toDateString(),
                'periodTo' => $result['periodEnd']?->toDateString(),
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
            ->where('lifecycle_status', 'active')
            ->where(function ($query) use ($locator): void {
                $query
                    ->whereRaw('LOWER(TRIM(barcode_no)) = ?', [$locator])
                    ->orWhereRaw('LOWER(TRIM(id_loc_no)) = ?', [$locator]);
            })
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
        $this->ensureCatalogManagePermission($request);

        $data = $request->validate($this->rules(requireCompleteLocation: true));
        $this->validateRequiredCatalogIdentity($data);
        $confirmDuplicate = (bool) ($data['confirmDuplicate'] ?? $data['confirm_duplicate'] ?? false);

        $result = DB::transaction(function () use ($confirmDuplicate, $data, $request): array {
            $this->validateRegisteredLocationPath($data, lock: true);
            $conflicts = $this->matchingActiveLocators($data, lock: true);
            if ($conflicts->isNotEmpty() && ! $confirmDuplicate) {
                return ['conflicts' => $conflicts];
            }

            $attributes = $this->payloadToAttributes($data, [
                'source' => 'custom',
                'created_by' => $request->user()?->id,
                'is_active' => true,
                'lifecycle_status' => 'active',
                'sort_order' => $this->nextSortOrder((string) ($data['mainLocation'] ?? $data['main_location'] ?? '')),
            ]);

            if ($confirmDuplicate && $this->activeIdentityExists($data, lock: true)) {
                $attributes['active_identity_key'] = null;
            } else {
                $this->assertUniqueActiveIdentity($data, null, true);
            }

            return ['row' => InspectionFireExtinguisher::query()->create($attributes)];
        });

        /** @var Collection<int, InspectionFireExtinguisher>|null $conflicts */
        $conflicts = $result['conflicts'] ?? null;
        if ($conflicts?->isNotEmpty()) {
            return response()->json([
                'code' => self::DUPLICATE_LOCATOR_CODE,
                'message' => 'One or more active fire extinguishers use this locator.',
                'data' => [
                    'matches' => $conflicts
                        ->map(fn (InspectionFireExtinguisher $row) => $this->formatRow($row, $request))
                        ->values(),
                ],
                'meta' => [
                    'count' => $conflicts->count(),
                ],
            ], Response::HTTP_CONFLICT);
        }

        /** @var InspectionFireExtinguisher $row */
        $row = $result['row'];

        AuditLogger::log($request, 'fire_extinguisher_created', null, ['fire_extinguisher_id' => $row->id]);

        return response()->json(['data' => $this->formatRow($row, $request)], 201);
    }

    public function storeBatch(Request $request): JsonResponse
    {
        $this->ensureCatalogManagePermission($request);

        $data = $request->validate($this->batchRules());
        $location = [
            'zone' => $data['zone'] ?? '',
            'zoneId' => $data['zoneId'] ?? $data['zone_id'] ?? null,
            'mainLocation' => $data['mainLocation'] ?? $data['main_location'] ?? '',
            'mainLocationId' => $data['mainLocationId'] ?? $data['main_location_id'] ?? null,
            'subLocation' => $data['subLocation'] ?? $data['sub_location'] ?? '',
            'subLocationId' => $data['subLocationId'] ?? $data['sub_location_id'] ?? null,
        ];
        $this->validateRequiredLocationPath($location);
        $items = collect($data['items'] ?? [])
            ->map(fn (array $item): array => [
                'idLocNo' => $item['idLocNo'] ?? $item['id_loc_no'] ?? '',
                'barcodeNo' => $item['barcodeNo'] ?? $item['barcode_no'] ?? '',
                'feType' => $item['feType'] ?? $item['fe_type'] ?? '',
                'certificationValidity' => $item['certificationValidity'] ?? $item['certification_validity'] ?? '',
                'confirmDuplicate' => (bool) ($item['confirmDuplicate'] ?? $item['confirm_duplicate'] ?? false),
            ])
            ->values()
            ->all();

        $locatorErrors = [];
        foreach ($items as $index => $item) {
            if ($this->locatorCandidates($item) === []) {
                $message = 'Enter an ID Loc. No. or barcode/S/N.';
                $locatorErrors["items.{$index}.idLocNo"] = $message;
                $locatorErrors["items.{$index}.barcodeNo"] = $message;
            }
        }
        if ($locatorErrors !== []) {
            throw ValidationException::withMessages($locatorErrors);
        }

        try {
            $result = $this->batchCreator->create($location, $items, $request->user()?->id);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['location' => $error->getMessage()]);
        }
        $conflicts = $result['conflicts'] ?? [];
        if ($conflicts !== []) {
            return response()->json([
                'code' => self::DUPLICATE_LOCATOR_CODE,
                'message' => 'One or more batch lines use a locator that is already in use.',
                'data' => [
                    'conflicts' => collect($conflicts)
                        ->map(fn (array $conflict): array => [
                            'index' => $conflict['index'],
                            'matches' => $conflict['matches']
                                ->map(fn (InspectionFireExtinguisher $row): array => $this->formatRow($row, $request))
                                ->values(),
                            'batchMatches' => $conflict['batchMatches']
                                ->map(fn (array $match): array => array_merge(
                                    ['index' => $match['index']],
                                    $this->formatPendingBatchRow($match['item']),
                                ))
                                ->values(),
                        ])
                        ->values(),
                ],
                'meta' => [
                    'count' => count($conflicts),
                ],
            ], Response::HTTP_CONFLICT);
        }

        /** @var Collection<int, InspectionFireExtinguisher> $rows */
        $rows = $result['rows'];

        AuditLogger::log($request, 'fire_extinguisher_batch_created', null, [
            'fire_extinguisher_ids' => $rows->pluck('id')->values()->all(),
            'count' => $rows->count(),
        ]);

        return response()->json([
            'data' => $rows
                ->map(fn (InspectionFireExtinguisher $row): array => $this->formatRow($row, $request))
                ->values(),
            'meta' => ['count' => $rows->count()],
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, int $extinguisherId): JsonResponse
    {
        $this->ensureCatalogManagePermission($request);
        $data = $request->validate($this->rules());
        [$row, $before] = DB::transaction(function () use ($data, $extinguisherId, $request): array {
            $row = $this->findActiveRow($extinguisherId, lock: true);
            $this->assertLifecycleVersion($request, $row);
            $attributes = $this->payloadToAttributes($data);
            if ($this->locatorChanged($row, $data)) {
                $this->assertUniqueActiveLocator($data, $row->id, lock: true);
            }
            if ($row->source === 'custom') {
                $this->assertUniqueActiveIdentity($data, $row->id, lock: true);
            } else {
                $attributes['active_identity_key'] = null;
            }
            $before = $row->only(['zone', 'main_location_name', 'sub_location_name', 'id_loc_no', 'barcode_no', 'fe_type', 'certification_validity']);
            $row->fill($attributes + [
                'updated_by' => $request->user()?->id,
                'lock_version' => $row->lock_version + 1,
            ])->save();

            return [$row->fresh(), $before];
        });

        AuditLogger::log($request, 'fire_extinguisher_updated', null, [
            'fire_extinguisher_id' => $row->id,
            'before' => $before,
            'after' => $row->only(array_keys($before)),
        ]);

        return response()->json(['data' => $this->formatRow($row, $request)]);
    }

    public function destroy(Request $request, int $extinguisherId): JsonResponse|Response
    {
        $this->ensureCatalogManagePermission($request);
        $this->retireRow($request, $extinguisherId, 'Retired through the legacy delete action.');

        return response()->noContent();
    }

    public function outOfService(Request $request, int $extinguisherId): JsonResponse
    {
        $this->ensureCatalogManagePermission($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
            'lockVersion' => ['required', 'integer', 'min:1'],
        ]);
        $row = DB::transaction(function () use ($data, $extinguisherId, $request): InspectionFireExtinguisher {
            $row = $this->findActiveRow($extinguisherId, lock: true);
            $this->assertLifecycleVersion($request, $row);
            if ($row->lifecycle_status !== 'active') {
                throw ValidationException::withMessages(['lifecycleStatus' => ['Only an active extinguisher can be taken out of service.']]);
            }
            $row->update([
                'lifecycle_status' => 'out_of_service',
                'out_of_service_at' => now(),
                'out_of_service_by' => $request->user()?->id,
                'out_of_service_reason' => trim($data['reason']),
                'updated_by' => $request->user()?->id,
                'lock_version' => $row->lock_version + 1,
            ]);

            return $row->fresh();
        });
        AuditLogger::log($request, 'fire_extinguisher_out_of_service', null, ['fire_extinguisher_id' => $row->id, 'reason' => $data['reason']]);

        return response()->json(['data' => $this->formatRow($row, $request)]);
    }

    public function returnToService(Request $request, int $extinguisherId): JsonResponse
    {
        $this->ensureCatalogManagePermission($request);
        $request->validate(['lockVersion' => ['required', 'integer', 'min:1']]);
        $row = DB::transaction(function () use ($extinguisherId, $request): InspectionFireExtinguisher {
            $row = $this->findActiveRow($extinguisherId, lock: true);
            $this->assertLifecycleVersion($request, $row);
            if ($row->lifecycle_status !== 'out_of_service') {
                throw ValidationException::withMessages(['lifecycleStatus' => ['Only an out-of-service extinguisher can return to service.']]);
            }
            $row->update([
                'lifecycle_status' => 'active',
                'out_of_service_at' => null,
                'out_of_service_by' => null,
                'out_of_service_reason' => null,
                'updated_by' => $request->user()?->id,
                'lock_version' => $row->lock_version + 1,
            ]);

            return $row->fresh();
        });
        AuditLogger::log($request, 'fire_extinguisher_returned_to_service', null, ['fire_extinguisher_id' => $row->id]);

        return response()->json(['data' => $this->formatRow($row, $request)]);
    }

    public function retire(Request $request, int $extinguisherId): JsonResponse
    {
        $this->ensureCatalogManagePermission($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000'], 'lockVersion' => ['required', 'integer', 'min:1']]);
        $row = $this->retireRow($request, $extinguisherId, trim($data['reason']));

        return response()->json(['data' => $this->formatRow($row, $request)]);
    }

    public function restore(Request $request, int $extinguisherId): JsonResponse
    {
        $this->ensureCatalogManagePermission($request);
        $request->validate(['lockVersion' => ['required', 'integer', 'min:1']]);
        $row = DB::transaction(function () use ($extinguisherId, $request): InspectionFireExtinguisher {
            $row = InspectionFireExtinguisher::query()->lockForUpdate()->findOrFail($extinguisherId);
            $this->assertLifecycleVersion($request, $row);
            if ($row->lifecycle_status !== 'retired') {
                throw ValidationException::withMessages(['lifecycleStatus' => ['Only a retired extinguisher can be restored.']]);
            }
            $identityData = [
                'mainLocation' => $row->main_location_name,
                'subLocation' => $row->sub_location_name,
                'idLocNo' => $row->id_loc_no,
                'barcodeNo' => $row->barcode_no,
            ];
            $identityKey = null;
            if ($row->source === 'custom') {
                $this->assertUniqueActiveIdentity($identityData, $row->id, lock: true);
                $identityKey = $this->activeIdentityKey($identityData);
            }
            $row->update([
                'lifecycle_status' => 'active',
                'is_active' => true,
                'active_identity_key' => $identityKey,
                'out_of_service_at' => null,
                'out_of_service_by' => null,
                'out_of_service_reason' => null,
                'retired_at' => null,
                'retired_by' => null,
                'retirement_reason' => null,
                'restored_at' => now(),
                'restored_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
                'lock_version' => $row->lock_version + 1,
            ]);

            return $row->fresh();
        });
        AuditLogger::log($request, 'fire_extinguisher_restored', null, ['fire_extinguisher_id' => $row->id]);

        return response()->json(['data' => $this->formatRow($row, $request)]);
    }

    public function inspectionHistory(Request $request, int $extinguisherId): JsonResponse
    {
        $this->ensureInspectionPermission($request);
        InspectionFireExtinguisher::query()->findOrFail($extinguisherId);
        $perPage = min(100, max(1, (int) $request->query('perPage', 25)));
        $reports = InspectionCheckRow::query()
            ->where('inspection_type_key', 'fire-extinguisher-inspection')
            ->where('equipment_catalog_id', $extinguisherId)
            ->whereNotNull('submitted_at')
            ->selectRaw('report_id, MAX(submitted_at) as submitted_at')
            ->groupBy('report_id')
            ->orderByDesc('submitted_at')
            ->paginate($perPage);
        $reportIds = collect($reports->items())->pluck('report_id')->map(fn ($id) => (int) $id);
        $rows = InspectionCheckRow::query()->with(['submittedBy:id,name', 'report:id,report_uid,display_id,payload'])
            ->where('equipment_catalog_id', $extinguisherId)->whereIn('report_id', $reportIds)->get()->groupBy('report_id');

        return response()->json([
            'data' => $reportIds->map(fn (int $id) => $this->formatHistoryRecord($rows->get($id, collect())))->values(),
            'meta' => ['page' => $reports->currentPage(), 'lastPage' => $reports->lastPage(), 'total' => $reports->total()],
        ]);
    }

    public function inspectionHistoryDetail(Request $request, int $extinguisherId, int $reportId): JsonResponse
    {
        $this->ensureInspectionPermission($request);
        InspectionFireExtinguisher::query()->findOrFail($extinguisherId);
        $rows = InspectionCheckRow::query()->with(['submittedBy:id,name', 'report:id,report_uid,display_id,payload'])
            ->where('inspection_type_key', 'fire-extinguisher-inspection')
            ->where('equipment_catalog_id', $extinguisherId)->where('report_id', $reportId)->get();
        abort_if($rows->isEmpty(), 404, 'Inspection history record was not found.');

        return response()->json(['data' => $this->formatHistoryRecord($rows)]);
    }

    private function retireRow(Request $request, int $extinguisherId, string $reason): InspectionFireExtinguisher
    {
        $row = DB::transaction(function () use ($extinguisherId, $request, $reason): InspectionFireExtinguisher {
            $row = $this->findActiveRow($extinguisherId, lock: true);
            $this->assertLifecycleVersion($request, $row);
            InspectionFireExtinguisherIssue::query()
                ->where('fire_extinguisher_id', $row->id)
                ->whereIn('status', InspectionFireExtinguisherIssue::ACTIVE_STATUSES)
                ->lockForUpdate()->get()
                ->each(fn ($issue) => $this->issueWorkflow->closeForRetirement($issue, (int) $request->user()->id, $reason));
            $row->update([
                'is_active' => false,
                'lifecycle_status' => 'retired',
                'active_identity_key' => null,
                'retired_at' => now(),
                'retired_by' => $request->user()?->id,
                'retirement_reason' => $reason,
                'updated_by' => $request->user()?->id,
                'lock_version' => $row->lock_version + 1,
            ]);

            return $row->fresh();
        });
        AuditLogger::log($request, 'fire_extinguisher_retired', null, ['fire_extinguisher_id' => $row->id, 'reason' => $reason]);

        return $row;
    }

    private function assertLifecycleVersion(Request $request, InspectionFireExtinguisher $row): void
    {
        $version = $request->input('lockVersion');
        if ($version !== null && (int) $version !== (int) $row->lock_version) {
            abort(409, 'The extinguisher was updated by another user. Refresh and try again.');
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(bool $requireCompleteLocation = false): array
    {
        return [
            'zone' => [$requireCompleteLocation ? 'required' : 'nullable', 'string', 'max:80'],
            'zoneId' => ['nullable', 'integer'],
            'zone_id' => ['nullable', 'integer'],
            'mainLocation' => ['required_without:main_location', 'string', 'max:190'],
            'main_location' => ['nullable', 'string', 'max:190'],
            'mainLocationId' => ['nullable', 'integer'],
            'main_location_id' => ['nullable', 'integer'],
            'subLocation' => [$requireCompleteLocation ? 'required_without:sub_location' : 'nullable', 'string', 'max:190'],
            'sub_location' => ['nullable', 'string', 'max:190'],
            'subLocationId' => ['nullable', 'integer'],
            'sub_location_id' => ['nullable', 'integer'],
            'idLocNo' => ['nullable', 'string', 'max:190'],
            'id_loc_no' => ['nullable', 'string', 'max:190'],
            'barcodeNo' => ['nullable', 'string', 'max:190'],
            'barcode_no' => ['nullable', 'string', 'max:190'],
            'feType' => ['nullable', 'string', 'max:120'],
            'fe_type' => ['nullable', 'string', 'max:120'],
            'certificationValidity' => ['nullable', 'date'],
            'certification_validity' => ['nullable', 'date'],
            'confirmDuplicate' => ['nullable', 'boolean'],
            'confirm_duplicate' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function batchRules(): array
    {
        return [
            'zone' => ['required', 'string', 'max:80'],
            'zoneId' => ['nullable', 'integer'],
            'zone_id' => ['nullable', 'integer'],
            'mainLocation' => ['required_without:main_location', 'string', 'max:190'],
            'main_location' => ['nullable', 'string', 'max:190'],
            'mainLocationId' => ['nullable', 'integer'],
            'main_location_id' => ['nullable', 'integer'],
            'subLocation' => ['required_without:sub_location', 'string', 'max:190'],
            'sub_location' => ['nullable', 'string', 'max:190'],
            'subLocationId' => ['nullable', 'integer'],
            'sub_location_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:25'],
            'items.*' => ['required', 'array'],
            'items.*.idLocNo' => ['nullable', 'string', 'max:190'],
            'items.*.id_loc_no' => ['nullable', 'string', 'max:190'],
            'items.*.barcodeNo' => ['nullable', 'string', 'max:190'],
            'items.*.barcode_no' => ['nullable', 'string', 'max:190'],
            'items.*.feType' => ['nullable', 'string', 'max:120'],
            'items.*.fe_type' => ['nullable', 'string', 'max:120'],
            'items.*.certificationValidity' => ['nullable', 'date'],
            'items.*.certification_validity' => ['nullable', 'date'],
            'items.*.confirmDuplicate' => ['nullable', 'boolean'],
            'items.*.confirm_duplicate' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function formatPendingBatchRow(array $item): array
    {
        return [
            'zone' => $this->text($item['zone'] ?? ''),
            'mainLocation' => $this->text($item['mainLocation'] ?? ''),
            'subLocation' => $this->text($item['subLocation'] ?? ''),
            'idLocNo' => $this->text($item['idLocNo'] ?? ''),
            'barcodeNo' => $this->text($item['barcodeNo'] ?? ''),
            'feType' => $this->normalizeFeType($item['feType'] ?? ''),
            'certificationValidity' => $this->text($item['certificationValidity'] ?? ''),
            'source' => 'batch',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateRequiredCatalogIdentity(array $data): void
    {
        $this->validateRequiredLocationPath($data);

        if ($this->locatorCandidates($data) === []) {
            throw ValidationException::withMessages([
                'idLocNo' => 'Enter an ID Loc. No. or barcode/S/N.',
                'barcodeNo' => 'Enter an ID Loc. No. or barcode/S/N.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateRequiredLocationPath(array $data): void
    {
        $errors = [];
        if ($this->text($data['zone'] ?? '') === '') {
            $errors['zone'] = 'Zone is required.';
        }
        if ($this->text($data['mainLocation'] ?? $data['main_location'] ?? '') === '') {
            $errors['mainLocation'] = 'Main location is required.';
        }
        if ($this->text($data['subLocation'] ?? $data['sub_location'] ?? '') === '') {
            $errors['subLocation'] = 'Sub-location is required.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->validateRegisteredLocationPath($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateRegisteredLocationPath(array $data, bool $lock = false): void
    {
        try {
            $this->siteLocationCatalog->assertCompletePath([
                'zone' => $data['zone'] ?? '',
                'zoneId' => $data['zoneId'] ?? $data['zone_id'] ?? null,
                'mainLocation' => $data['mainLocation'] ?? $data['main_location'] ?? '',
                'mainLocationId' => $data['mainLocationId'] ?? $data['main_location_id'] ?? null,
                'subLocation' => $data['subLocation'] ?? $data['sub_location'] ?? '',
                'subLocationId' => $data['subLocationId'] ?? $data['sub_location_id'] ?? null,
            ], $lock);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['location' => $error->getMessage()]);
        }
    }

    private function findActiveRow(int $id, bool $lock = false): InspectionFireExtinguisher
    {
        return InspectionFireExtinguisher::query()
            ->where('is_active', true)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $extra
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
    ): array {
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
            'lifecycleStatus' => (string) ($row->lifecycle_status ?: ($row->is_active ? 'active' : 'retired')),
            'outOfServiceAt' => $row->out_of_service_at?->toIso8601String(),
            'outOfServiceReason' => (string) ($row->out_of_service_reason ?? ''),
            'retiredAt' => $row->retired_at?->toIso8601String(),
            'retirementReason' => (string) ($row->retirement_reason ?? ''),
            'restoredAt' => $row->restored_at?->toIso8601String(),
            'lockVersion' => (int) ($row->lock_version ?: 1),
            'openIssueCount' => (int) ($row->open_issues_count ?? 0),
            'canEdit' => (bool) ($request->user() && $this->authorizationService->hasPermission($request->user(), 'reports.manage|reports.inspection.extinguishers.manage')),
            'canDelete' => (bool) ($request->user() && $this->authorizationService->hasPermission($request->user(), 'reports.manage|reports.inspection.extinguishers.manage')),
            'lastInspection' => $this->formatLastInspection($lastInspection),
        ];
    }

    /** @param Collection<int, InspectionCheckRow> $rows */
    private function formatHistoryRecord(Collection $rows): array
    {
        /** @var InspectionCheckRow|null $first */
        $first = $rows->sortByDesc('submitted_at')->first();
        if (! $first) {
            return [];
        }
        $issueByCheckRow = InspectionFireExtinguisherIssueOccurrence::query()
            ->with('issue:id,public_id,status,severity')
            ->whereIn('inspection_check_row_id', $rows->pluck('id'))
            ->get()->keyBy('inspection_check_row_id');
        $checks = $rows->sortBy('sort_order')->map(function (InspectionCheckRow $row) use ($issueByCheckRow): array {
            $occurrence = $issueByCheckRow->get($row->id);

            return [
                'key' => $this->coverageCheckColumnKey($row->check_key),
                'checkKey' => $row->check_key,
                'label' => $row->check_name,
                'value' => $row->check_value,
                'hasDefect' => (bool) $row->has_defect,
                'remarks' => (string) ($row->remarks ?? ''),
                'evidenceCount' => (int) $row->evidence_count,
                'photos' => $this->historyPhotos($row),
                'issue' => $occurrence?->issue ? [
                    'id' => $occurrence->issue->id,
                    'publicId' => $occurrence->issue->public_id,
                    'status' => $occurrence->issue->status,
                    'severity' => $occurrence->issue->severity,
                ] : null,
            ];
        })->values();

        return [
            'reportId' => (int) $first->report_id,
            'reportUid' => (string) $first->report_uid,
            'displayId' => (string) $first->display_id,
            'submittedAt' => $first->submitted_at?->toIso8601String(),
            'submittedBy' => (string) ($first->submittedBy?->name ?? ''),
            'status' => $checks->contains(fn (array $check): bool => $check['hasDefect']) ? 'issues' : 'checked',
            'issueCount' => $checks->where('hasDefect', true)->count(),
            'evidenceCount' => $checks->sum('evidenceCount'),
            'checks' => $checks,
        ];
    }

    private function coverageCheckColumnKey(string $checkKey): string
    {
        return match ($checkKey) {
            'physical-condition' => 'physical',
            'signage-condition' => 'signage',
            'box-key-availability' => 'boxKey',
            'box-glass-availability' => 'boxGlass',
            'operational-condition' => 'operational',
            default => $checkKey,
        };
    }

    private function historyPhotos(InspectionCheckRow $row): array
    {
        $payload = is_array($row->report?->payload) ? $row->report->payload : [];
        $items = $payload['fireExtinguisherChecks'] ?? $payload['fire_extinguisher_checks'] ?? [];
        if (! is_array($items)) {
            return [];
        }
        $photoKey = match ($row->check_key) {
            'physical-condition' => 'physicalConditionPhotos',
            'signage-condition' => 'signageConditionPhotos',
            'box-key-availability' => 'boxKeyAvailabilityPhotos',
            'box-glass-availability' => 'boxGlassAvailabilityPhotos',
            'operational-condition' => 'operationalConditionPhotos',
            default => '',
        };
        if ($photoKey === '') {
            return [];
        }
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            if ($this->text($item['catalogId'] ?? $item['catalog_id'] ?? '') === $this->text($row->equipment_catalog_id)
                || ($this->text($row->source_row_id) !== '' && $this->text($item['id'] ?? '') === $this->text($row->source_row_id))) {
                $photos = $item[$photoKey] ?? $item[Str::snake($photoKey)] ?? [];

                return is_array($photos) ? array_values($photos) : [];
            }
        }

        return [];
    }

    /**
     * @param  Collection<int, InspectionFireExtinguisher>  $rows
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
     * @param  array<string, mixed>  $data
     */
    private function assertUniqueActiveLocator(array $data, ?int $ignoreId = null, bool $lock = false): void
    {
        if ($this->matchingActiveLocators($data, $ignoreId, $lock)->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'idLocNo' => 'An active fire extinguisher with the same S/N, QR, or barcode already exists.',
            'barcodeNo' => 'An active fire extinguisher with the same S/N, QR, or barcode already exists.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, InspectionFireExtinguisher>
     */
    private function matchingActiveLocators(
        array $data,
        ?int $ignoreId = null,
        bool $lock = false,
    ): Collection {
        $locators = $this->locatorCandidates($data);
        if ($locators === []) {
            return collect();
        }

        return InspectionFireExtinguisher::query()
            ->where('is_active', true)
            ->where(function ($query) use ($locators): void {
                $query
                    ->whereIn(DB::raw('LOWER(TRIM(barcode_no))'), $locators)
                    ->orWhereIn(DB::raw('LOWER(TRIM(id_loc_no))'), $locators);
            })
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function locatorChanged(InspectionFireExtinguisher $row, array $data): bool
    {
        $previousLocators = $this->locatorCandidates([
            'barcodeNo' => $row->barcode_no ?? '',
            'idLocNo' => $row->id_loc_no ?? '',
        ]);
        $nextLocators = $this->locatorCandidates($data);

        return $previousLocators !== $nextLocators;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertUniqueActiveIdentity(array $data, ?int $ignoreId = null, bool $lock = false): void
    {
        if (! $this->activeIdentityExists($data, $ignoreId, $lock)) {
            return;
        }

        throw ValidationException::withMessages([
            'idLocNo' => 'An active fire extinguisher with the same location, ID/location number, and barcode already exists.',
            'barcodeNo' => 'An active fire extinguisher with the same location, ID/location number, and barcode already exists.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function activeIdentityExists(
        array $data,
        ?int $ignoreId = null,
        bool $lock = false,
    ): bool {
        $identityKey = $this->activeIdentityKey($data);
        if (! $identityKey) {
            return false;
        }

        return InspectionFireExtinguisher::query()
            ->where('active_identity_key', $identityKey)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $data
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function locatorCandidates(array $data): array
    {
        $locators = [
            $this->locatorPart($data['barcodeNo'] ?? $data['barcode_no'] ?? ''),
            $this->locatorPart($data['idLocNo'] ?? $data['id_loc_no'] ?? ''),
        ];
        $unique = [];
        foreach ($locators as $locator) {
            if ($locator !== '') {
                $unique[$locator] = $locator;
            }
        }

        $normalized = array_values($unique);
        sort($normalized);

        return $normalized;
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

    private function ensureCatalogManagePermission(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! $this->authorizationService->hasPermission($user, 'reports.manage|reports.inspection.extinguishers.manage')) {
            abort(403, 'Missing fire extinguisher catalogue management permission.');
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
