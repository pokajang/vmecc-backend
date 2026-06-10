<?php

namespace App\Http\Controllers;

use App\Models\OvertimeRecord;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\HolidayGuidanceFeatureGate;
use App\Services\HolidayResolver;
use App\Services\OvertimeDateClassifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OvertimeManagementController extends Controller
{
    private const KNOWN_OVERTIME_TYPES = ['weekday', 'weekend', 'publicHoliday'];

    public function __construct(
        private readonly OvertimeDateClassifier $overtimeDateClassifier,
        private readonly HolidayResolver $holidayResolver,
        private readonly HolidayGuidanceFeatureGate $guidanceGate,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));
        $status = trim((string) $request->input('status', 'All'));
        $overtimeType = trim((string) $request->input('overtime_type', 'All'));
        $team = trim((string) $request->input('team', 'All'));
        $period = trim((string) $request->input('period', 'all'));
        $requestedSort = trim((string) $request->input('sort', 'appliedAt:desc'));
        [$sortField, $sortDirection, $sortValue] = $this->normalizeSort($requestedSort);

        $page = max(1, (int) $request->input('page', 1));
        $perPage = (int) $request->input('per_page', 5);
        if ($perPage <= 0) {
            $perPage = 5;
        }
        $perPage = min($perPage, 100);

        $baseQuery = $this->baseQuery();
        $filteredQuery = $this->baseQuery();
        $this->applyFilters($filteredQuery, [
            'search' => $search,
            'status' => $status,
            'overtime_type' => $overtimeType,
            'team' => $team,
            'period' => $period,
        ]);

        $totalCount = (clone $baseQuery)->count();
        $filteredCount = (clone $filteredQuery)->count();
        $this->applySort($filteredQuery, $sortField, $sortDirection);

        $paginator = $filteredQuery->paginate($perPage, ['*'], 'page', $page);
        $pageRows = $paginator->getCollection();
        $teamByUserId = $this->resolveCanonicalTeamByUserId(
            $pageRows
                ->pluck('user_id')
                ->map(fn ($value) => (int) $value)
                ->all(),
        );

        $rows = $pageRows->map(fn (OvertimeRecord $row) => $this->formatManagementRecord($row, $teamByUserId));
        $filterOptions = $this->buildFilterOptions();

        return response()->json([
            'data' => $rows->values()->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total_count' => $totalCount,
                'filtered_count' => $filteredCount,
                'returned_count' => $rows->count(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'sort' => $sortValue,
                'query' => [
                    'search' => $search,
                    'status' => $status,
                    'overtime_type' => $overtimeType,
                    'team' => $team,
                    'period' => $period,
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                ],
            ],
            'filters' => $filterOptions,
        ]);
    }

    public function show(Request $request, int $ownerId, int $recordId): JsonResponse
    {
        $row = OvertimeRecord::query()
            ->where('user_id', $ownerId)
            ->with(['user', 'attachment'])
            ->findOrFail($recordId);

        $teamByUserId = $this->resolveCanonicalTeamByUserId([(int) $row->user_id]);
        $base = $this->formatManagementRecord($row, $teamByUserId);
        if ($this->guidanceGate->staffVisibilityEnabledForUser($request->user())) {
            $derivedType = $this->overtimeDateClassifier->classify($row->user, (string) $row->claim_date);
            $effectiveState = $this->holidayResolver->resolveEmployeeState($row->user);
            $submittedType = trim((string) ($row->overtime_type ?? ''));
            $base['guidance_meta'] = [
                'derived_overtime_type' => $derivedType,
                'effective_state' => $effectiveState,
                'overtime_type_adjusted_message' => $submittedType !== '' && $submittedType !== $derivedType
                    ? "Recommended overtime type based on claim date/public holiday rules is {$derivedType}."
                    : null,
            ];
        }

        return response()->json(['data' => $base]);
    }

    private function baseQuery(): Builder
    {
        return OvertimeRecord::query()
            ->with(['user', 'attachment'])
            ->where('status', '!=', 'Draft');
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $status = trim((string) ($filters['status'] ?? 'All'));
        if ($status !== '' && strcasecmp($status, 'All') !== 0) {
            $query->where('status', $status);
        }

        $overtimeType = trim((string) ($filters['overtime_type'] ?? 'All'));
        if ($overtimeType !== '' && strcasecmp($overtimeType, 'All') !== 0) {
            $query->where('overtime_type', $overtimeType);
        }

        $period = trim((string) ($filters['period'] ?? 'all'));
        if ($period !== '' && strcasecmp($period, 'all') !== 0) {
            if (preg_match('/^\d+$/', $period)) {
                $days = (int) $period;
                if ($days > 0) {
                    $cutoff = now()->subDays($days)->startOfDay();
                    $query->where('applied_at', '>=', $cutoff);
                }
            } elseif (preg_match('/^\d{4}-\d{2}$/', $period)) {
                [$year, $month] = array_map('intval', explode('-', $period));
                if ($year > 0 && $month >= 1 && $month <= 12) {
                    $start = now()->setDate($year, $month, 1)->startOfMonth()->startOfDay();
                    $end = now()->setDate($year, $month, 1)->endOfMonth()->endOfDay();
                    $query->whereBetween('applied_at', [$start, $end]);
                }
            }
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $today = now()->toDateString();
            $query->where(function (Builder $builder) use ($search, $needle, $today) {
                $builder
                    ->where('display_id', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('overtime_type', 'like', "%{$search}%")
                    ->orWhereHas('user', function (Builder $userQuery) use ($search, $needle) {
                        $userQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhereRaw('LOWER(COALESCE(team, \'\')) LIKE ?', ["%{$needle}%"]);
                    })
                    ->orWhereHas('user.roleAssignments', function (Builder $assignmentQuery) use (
                        $needle,
                        $today
                    ) {
                        $assignmentQuery->whereNotNull('team_id');
                        $this->applyActiveAssignmentWindow($assignmentQuery, $today);
                        $assignmentQuery->whereHas('team', function (Builder $teamQuery) use ($needle) {
                            $teamQuery->whereRaw('LOWER(name) LIKE ?', ["%{$needle}%"]);
                        });
                    });
            });
        }

        $team = trim((string) ($filters['team'] ?? 'All'));
        if ($team !== '' && strcasecmp($team, 'All') !== 0) {
            $this->applyTeamFilter($query, $team);
        }
    }

    private function applySort(Builder $query, string $field, string $direction): void
    {
        $columnMap = [
            'appliedAt' => 'applied_at',
            'applied_at' => 'applied_at',
            'durationMinutes' => 'duration_minutes',
            'duration_minutes' => 'duration_minutes',
            'claimDate' => 'claim_date',
            'claim_date' => 'claim_date',
            'status' => 'status',
        ];
        $column = $columnMap[$field] ?? 'applied_at';
        $query->orderBy($column, $direction)->orderByDesc('id');
    }

    private function normalizeSort(string $requestedSort): array
    {
        $parts = explode(':', $requestedSort, 2);
        $field = trim((string) ($parts[0] ?? 'appliedAt'));
        $direction = strtolower(trim((string) ($parts[1] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $field = $field !== '' ? $field : 'appliedAt';

        return [$field, $direction, "{$field}:{$direction}"];
    }

    private function applyTeamFilter(Builder $query, string $team): void
    {
        $today = now()->toDateString();
        $normalized = mb_strtolower(trim($team));
        if ($normalized === '') {
            return;
        }

        if ($normalized === 'unassigned') {
            $query
                ->whereDoesntHave('user.roleAssignments', function (Builder $assignmentQuery) use ($today) {
                    $assignmentQuery->whereNotNull('team_id');
                    $this->applyActiveAssignmentWindow($assignmentQuery, $today);
                })
                ->whereHas('user', function (Builder $userQuery) {
                    $userQuery->where(function (Builder $teamQuery) {
                        $teamQuery->whereNull('team')->orWhere('team', '');
                    });
                });
            return;
        }

        $query->where(function (Builder $builder) use ($normalized, $today) {
            $builder
                ->whereHas('user.roleAssignments', function (Builder $assignmentQuery) use (
                    $normalized,
                    $today
                ) {
                    $assignmentQuery->whereNotNull('team_id');
                    $this->applyActiveAssignmentWindow($assignmentQuery, $today);
                    $assignmentQuery->whereHas('team', function (Builder $teamQuery) use ($normalized) {
                        $teamQuery->whereRaw('LOWER(name) = ?', [$normalized]);
                    });
                })
                ->orWhere(function (Builder $fallbackQuery) use ($normalized, $today) {
                    $fallbackQuery
                        ->whereDoesntHave('user.roleAssignments', function (Builder $assignmentQuery) use (
                            $today
                        ) {
                            $assignmentQuery->whereNotNull('team_id');
                            $this->applyActiveAssignmentWindow($assignmentQuery, $today);
                        })
                        ->whereHas('user', function (Builder $userQuery) use ($normalized) {
                            $userQuery->whereRaw('LOWER(COALESCE(team, \'\')) = ?', [$normalized]);
                        });
                });
        });
    }

    private function applyActiveAssignmentWindow(Builder $query, string $today): void
    {
        $query
            ->where(function (Builder $windowQuery) use ($today) {
                $windowQuery->whereNull('start_date')->orWhereDate('start_date', '<=', $today);
            })
            ->where(function (Builder $windowQuery) use ($today) {
                $windowQuery->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            });
    }

    private function resolveCanonicalTeamByUserId(array $userIds): array
    {
        $normalizedIds = collect($userIds)
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->unique()
            ->values();
        if ($normalizedIds->isEmpty()) {
            return [];
        }

        $today = now()->toDateString();
        $assignmentRows = UserRoleAssignment::query()
            ->with('team:id,name')
            ->whereIn('user_id', $normalizedIds->all())
            ->whereNotNull('team_id')
            ->where(function (Builder $query) use ($today) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today);
            })
            ->where(function (Builder $query) use ($today) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            })
            ->orderBy('user_id')
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'team_id', 'is_primary', 'start_date', 'end_date']);

        $assignmentTeamMap = [];
        foreach ($assignmentRows as $assignment) {
            $userId = (int) ($assignment->user_id ?? 0);
            if ($userId <= 0 || array_key_exists($userId, $assignmentTeamMap)) {
                continue;
            }
            $teamName = trim((string) ($assignment->team?->name ?? ''));
            if ($teamName === '') {
                continue;
            }
            $assignmentTeamMap[$userId] = $teamName;
        }

        $fallbackTeamMap = User::query()
            ->whereIn('id', $normalizedIds->all())
            ->pluck('team', 'id')
            ->map(fn ($value) => trim((string) ($value ?? '')))
            ->all();

        $resolved = [];
        foreach ($normalizedIds as $userId) {
            $teamName = $assignmentTeamMap[$userId] ?? '';
            if ($teamName === '') {
                $teamName = $fallbackTeamMap[$userId] ?? '';
            }
            $resolved[(int) $userId] = $teamName !== '' ? $teamName : 'Unassigned';
        }

        return $resolved;
    }

    private function formatManagementRecord(OvertimeRecord $row, array $teamByUserId): array
    {
        $base = OvertimeController::formatRecord($row);
        $ownerId = (int) ($row->user_id ?? 0);
        $base['owner_user_id'] = $ownerId;
        $base['employee'] = $row->user?->name ?? '';
        $base['employee_email'] = $row->user?->email ?? '';
        $base['avatar_url'] = $this->resolveProfileImageUrl($row->user?->profile_image_url);
        $base['team'] = $teamByUserId[$ownerId] ?? 'Unassigned';
        $base['record_key'] = $ownerId . '::' . $row->id;

        return $base;
    }

    private function buildFilterOptions(): array
    {
        $baseQuery = $this->baseQuery();
        $statusValues = (clone $baseQuery)
            ->select('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->filter(fn ($value) => trim((string) ($value ?? '')) !== '')
            ->values()
            ->all();

        $distinctTypes = (clone $baseQuery)
            ->select('overtime_type')
            ->distinct()
            ->orderBy('overtime_type')
            ->pluck('overtime_type')
            ->filter(fn ($value) => trim((string) ($value ?? '')) !== '')
            ->values()
            ->all();

        $overtimeTypeValues = collect(array_merge(self::KNOWN_OVERTIME_TYPES, $distinctTypes))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->unique()
            ->values()
            ->all();

        $ownerIds = (clone $baseQuery)
            ->select('user_id')
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($value) => (int) $value)
            ->all();
        $teamValues = collect($this->resolveCanonicalTeamByUserId($ownerIds))
            ->values()
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'status' => array_map(
                fn ($value) => ['value' => $value, 'label' => $value],
                $statusValues,
            ),
            'overtime_type' => array_map(
                fn ($value) => [
                    'value' => $value,
                    'label' => $this->formatOvertimeTypeLabel($value),
                ],
                $overtimeTypeValues,
            ),
            'team' => array_map(
                fn ($value) => ['value' => $value, 'label' => $value],
                $teamValues,
            ),
        ];
    }

    private function formatOvertimeTypeLabel(string $type): string
    {
        $normalized = trim($type);
        if ($normalized === 'weekend') {
            return 'Weekend';
        }
        if ($normalized === 'publicHoliday') {
            return 'Public Holiday';
        }
        if ($normalized === 'weekday') {
            return 'Weekday';
        }
        return $normalized !== '' ? ucfirst($normalized) : 'Unknown';
    }

    private function resolveProfileImageUrl(?string $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return $raw;
        }
        return Storage::disk(config('filesystems.default', 'local'))->url($raw);
    }
}
