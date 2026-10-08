<?php

namespace App\Http\Controllers;

use App\Models\CustomShift;
use App\Models\Roster;
use App\Models\Team;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\LeaveRosterImpactService;
use App\Services\WorkflowNotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RosterController extends Controller
{
    // Built-in shifts that always exist regardless of custom_shifts table content.
    private const BUILT_IN_SHIFTS = ['day', 'night'];

    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
        private readonly WorkflowNotificationService $workflowNotifications,
        private readonly LeaveRosterImpactService $rosterImpactService,
    ) {}

    /**
     * Return the full ordered list of valid shift slugs:
     * built-ins first (day, night), then custom shifts by sort_order.
     */
    private function allShiftSlugs(): array
    {
        $custom = CustomShift::orderBy('sort_order')->orderBy('name')->pluck('name')->toArray();

        return array_values(array_unique(array_merge(self::BUILT_IN_SHIFTS, $custom)));
    }

    /**
     * List rosters grouped by date, returning all shifts as a keyed map.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canManageRosters = $user && $this->authorizationService->hasPermission($user, 'rosters.manage');
        $scopePermission = $canManageRosters ? 'rosters.manage' : 'teams.view';
        $permittedTeamIds = $this->authorizationService->permittedTeamIds($user, $scopePermission);
        if (! $canManageRosters) {
            $requestedStatus = strtolower(trim((string) $request->input('status', '')));
            if ($requestedStatus !== '' && $requestedStatus !== 'published') {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            // Team viewers can only read published roster data.
            $request->merge(['status' => 'published']);
        }

        if ($request->filled('months') && is_string($request->months)) {
            $request->merge(['months' => array_filter(array_map('trim', explode(',', $request->months)))]);
        }

        $request->validate([
            'date' => ['sometimes', 'nullable', 'date'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'status' => ['sometimes', 'nullable', 'string', 'in:draft,published,unassigned'],
            'attention' => ['sometimes', 'nullable', 'string', 'in:draft'],
            'months' => ['sometimes', 'nullable', 'array', 'max:24'],
            'months.*' => ['string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        if ($request->filled('from') && $request->filled('to')) {
            $diffDays = \Carbon\Carbon::parse($request->input('from'))
                ->diffInDays(\Carbon\Carbon::parse($request->input('to')));
            if ($diffDays > 366) {
                return response()->json([
                    'message' => 'Date range must not exceed 366 days.',
                    'errors' => ['to' => ['Date range must not exceed 366 days.']],
                ], 422);
            }
        }

        $query = Roster::with('team')
            ->when(
                $permittedTeamIds !== null,
                fn ($builder) => $builder->whereIn('team_id', $permittedTeamIds->all()),
            )
            ->orderBy('date')
            ->orderBy('shift');

        if ($request->filled('date')) {
            $query->whereDate('date', $request->input('date'));
        }

        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('date', [$request->input('from'), $request->input('to')]);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->input('attention') === 'draft') {
            $query->whereIn('date', Roster::query()->select('date')->where('status', 'draft'));
        }

        if ($request->filled('months')) {
            $months = $request->months;
            $query->where(function ($q) use ($months) {
                foreach ($months as $m) {
                    $monthStr = trim($m);
                    if ($monthStr === '') {
                        continue;
                    }
                    try {
                        $start = Carbon::createFromFormat('Y-m', $monthStr)->startOfMonth();
                        $end = Carbon::createFromFormat('Y-m', $monthStr)->endOfMonth();
                        $q->orWhereBetween('date', [$start->toDateString(), $end->toDateString()]);
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            });
        }

        $rosterRows = $query->get();
        $markersByRosterId = $this->rosterImpactService->markersForRosters($rosterRows, $canManageRosters);
        $rosters = $rosterRows->groupBy(function ($item) {
            if ($item->date instanceof Carbon) {
                return $item->date->toDateString();
            }

            return substr((string) $item->date, 0, 10);
        })->map(function ($items, $date) use ($markersByRosterId) {
            // Build a keyed map of all shifts present for this date
            $shiftsMap = [];
            foreach ($items as $row) {
                $shiftsMap[$row->shift] = [
                    'team_id' => $row->team_id,
                    'team' => $row->team?->name,
                    'status' => $row->status,
                    'updated_at' => $row->updated_at?->toIso8601String(),
                    'leave_marker' => $markersByRosterId[$row->id] ?? [
                        'requested_count' => 0,
                        'approved_count' => 0,
                        'people' => [],
                    ],
                ];
            }

            return [
                'date' => $date,
                'status' => $this->resolveRowStatus($items),
                'shifts' => $shiftsMap,
            ];
        })->values();

        return response()->json(['data' => $rosters]);
    }

    /**
     * Create or update roster entries as DRAFT (bulk, N-shift model).
     *
     * Payload:
     *   entries: [{ date, shifts: [{ shift, team_id }] }]
     */
    public function store(Request $request): JsonResponse
    {
        $validSlugs = $this->allShiftSlugs();

        $data = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.date' => ['required', 'date'],
            'entries.*.shifts' => ['required', 'array', 'min:1'],
            'entries.*.shifts.*.shift' => ['required', 'string', Rule::in($validSlugs)],
            'entries.*.shifts.*.team_id' => ['nullable', Rule::exists('teams', 'id')],
            'entries.*.shifts.*.expected_updated_at' => ['present', 'nullable', 'date'],
        ]);

        foreach ($data['entries'] as $entry) {
            if ($error = $this->detectSameTeamConflict($entry['shifts'])) {
                return response()->json([
                    'message' => 'A team cannot be assigned to more than one shift on the same date.',
                    'errors' => ['entries' => ["Conflict on {$entry['date']}: {$error}"]],
                ], 422);
            }
        }

        $userId = Auth::id();
        $permittedTeamIds = $this->authorizationService
            ->permittedTeamIds($request->user(), 'rosters.manage')
            ?->all();

        try {
            DB::transaction(fn () => $this->applyRosterPatch(
                $data['entries'],
                'draft',
                $userId,
                permittedTeamIds: $permittedTeamIds,
            ));
        } catch (QueryException $exception) {
            $this->throwRosterWriteConflict($exception);
        }

        AuditLogger::log($request, 'roster_draft_saved', null, [
            'entry_count' => count($data['entries']),
        ]);

        return response()->json(['message' => 'Roster draft saved.']);
    }

    /**
     * Publish roster entries and notify affected team members.
     */
    public function publish(Request $request): JsonResponse
    {
        $validSlugs = $this->allShiftSlugs();

        $data = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.date' => ['required', 'date'],
            'entries.*.shifts' => ['required', 'array', 'min:1'],
            'entries.*.shifts.*.shift' => ['required', 'string', Rule::in($validSlugs)],
            'entries.*.shifts.*.team_id' => ['nullable', Rule::exists('teams', 'id')],
            'entries.*.shifts.*.expected_updated_at' => ['present', 'nullable', 'date'],
            'scope_label' => ['required', 'string', 'max:100'],
        ]);

        foreach ($data['entries'] as $entry) {
            if ($error = $this->detectSameTeamConflict($entry['shifts'])) {
                return response()->json([
                    'message' => 'A team cannot be assigned to more than one shift on the same date.',
                    'errors' => ['entries' => ["Conflict on {$entry['date']}: {$error}"]],
                ], 422);
            }
        }

        $userId = Auth::id();
        $permittedTeamIds = $this->authorizationService
            ->permittedTeamIds($request->user(), 'rosters.manage')
            ?->all();
        $scopeLabel = $data['scope_label'];
        $now = Carbon::now();
        try {
            $teamShifts = DB::transaction(
                fn () => $this->applyRosterPatch(
                    $data['entries'],
                    'published',
                    $userId,
                    $now,
                    $permittedTeamIds,
                ),
            );
        } catch (QueryException $exception) {
            $this->throwRosterWriteConflict($exception);
        }

        $teamIds = array_keys($teamShifts);

        if (! empty($teamIds)) {
            $teams = Team::with(['members' => fn ($q) => $q->whereNull('ended_at')])
                ->whereIn('id', $teamIds)
                ->get();
            foreach ($teams as $team) {
                $shifts = $teamShifts[$team->id] ?? [];
                $memberIds = $team->members->pluck('user_id')->filter()->values()->all();
                if (empty($memberIds)) {
                    continue;
                }

                $actor = $request->user()
                    ? [
                        'userId' => $request->user()->id,
                        'name' => $request->user()->name,
                        'email' => $request->user()->email ?? '',
                    ]
                    : ['userId' => null, 'name' => 'System', 'email' => ''];

                $this->workflowNotifications->emit(
                    module: 'roster',
                    eventType: 'published',
                    recordType: 'roster',
                    recordId: (int) $team->id,
                    recordDisplayId: $scopeLabel,
                    ownerUserId: (int) $memberIds[0],
                    actor: $actor,
                    targetUserIds: $memberIds,
                    metadata: [
                        'scopeLabel' => $scopeLabel,
                        'teamId' => $team->id,
                        'teamName' => $team->name,
                        'shiftCount' => count($shifts),
                        'shifts' => $shifts,
                        'detailRouteKey' => 'roster',
                        'status' => 'Published',
                        'workflowStage' => 'done',
                    ],
                );
            }
        }

        AuditLogger::log($request, 'roster_published', null, [
            'scope_label' => $scopeLabel,
            'entry_count' => count($data['entries']),
            'teams_count' => count($teamIds),
        ]);

        return response()->json(['message' => 'Roster published and teams notified.']);
    }

    private function applyRosterPatch(
        array $entries,
        string $status,
        int $userId,
        ?Carbon $publishedAt = null,
        ?array $permittedTeamIds = null,
    ): array {
        $this->validatePatchedTeamConflicts($entries);
        $teamShifts = [];
        foreach ($entries as $entry) {
            foreach ($entry['shifts'] as $shiftRow) {
                $shift = $shiftRow['shift'];
                $teamId = $shiftRow['team_id'];
                $expectedUpdatedAt = $shiftRow['expected_updated_at'];
                $existing = Roster::query()
                    ->whereDate('date', $entry['date'])
                    ->where('shift', $shift)
                    ->lockForUpdate()
                    ->first();

                $existingTeamId = $existing?->team_id ? (int) $existing->team_id : null;
                $targetTeamId = $teamId !== null ? (int) $teamId : null;
                if ($permittedTeamIds !== null && (
                    ($existingTeamId !== null && ! in_array($existingTeamId, $permittedTeamIds, true))
                    || ($targetTeamId !== null && ! in_array($targetTeamId, $permittedTeamIds, true))
                )) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'You cannot change a roster assignment outside your team scope.',
                        'code' => 'roster_team_scope_forbidden',
                    ], 403));
                }

                $versionMatches = $existing
                    ? $expectedUpdatedAt !== null
                        && $existing->updated_at?->equalTo($expectedUpdatedAt)
                    : $expectedUpdatedAt === null;
                if (! $versionMatches) {
                    throw new HttpResponseException(response()->json([
                        'message' => "The {$shift} roster assignment for {$entry['date']} changed after it was loaded.",
                        'code' => 'roster_version_conflict',
                        'date' => $entry['date'],
                        'shift' => $shift,
                    ], 409));
                }

                if ($teamId === null) {
                    $existing?->delete();

                    continue;
                }

                $values = [
                    'team_id' => $teamId,
                    'status' => $status,
                    'created_by' => $userId,
                ];
                if ($status === 'published') {
                    $values['published_by'] = $userId;
                    $values['published_at'] = $publishedAt;
                } else {
                    $values['published_by'] = null;
                    $values['published_at'] = null;
                }

                if ($existing) {
                    $existing->update($values);
                } else {
                    Roster::query()->create([
                        'date' => $entry['date'],
                        'shift' => $shift,
                        ...$values,
                    ]);
                }
                $teamShifts[$teamId][] = ['date' => $entry['date'], 'shift' => $shift];
            }
        }

        return $teamShifts;
    }

    private function validatePatchedTeamConflicts(array $entries): void
    {
        foreach (collect($entries)->groupBy('date') as $date => $dateEntries) {
            $resultingShifts = Roster::query()
                ->whereDate('date', $date)
                ->lockForUpdate()
                ->pluck('team_id', 'shift')
                ->map(fn ($teamId) => $teamId !== null ? (int) $teamId : null)
                ->all();

            foreach ($dateEntries as $entry) {
                foreach ($entry['shifts'] as $shiftRow) {
                    if ($shiftRow['team_id'] === null) {
                        unset($resultingShifts[$shiftRow['shift']]);
                    } else {
                        $resultingShifts[$shiftRow['shift']] = (int) $shiftRow['team_id'];
                    }
                }
            }

            $seen = [];
            foreach ($resultingShifts as $shift => $teamId) {
                if ($teamId === null) {
                    continue;
                }
                if (isset($seen[$teamId])) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'A team cannot be assigned to more than one shift on the same date.',
                        'errors' => ['entries' => [
                            "Conflict on {$date}: team {$teamId} assigned to both '{$seen[$teamId]}' and '{$shift}'",
                        ]],
                    ], 422));
                }
                $seen[$teamId] = $shift;
            }
        }
    }

    private function throwRosterWriteConflict(QueryException $exception): never
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        if (! in_array($sqlState, ['23000', '23505'], true)) {
            throw $exception;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'A roster assignment changed while this update was being saved. Refresh and retry.',
            'code' => 'roster_version_conflict',
        ], 409));
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Detect if the same team_id appears in more than one shift slot for a date.
     * Returns a description string on conflict, null if clean.
     */
    private function detectSameTeamConflict(array $shifts): ?string
    {
        $seen = [];
        foreach ($shifts as $row) {
            if ($row['team_id'] === null) {
                continue;
            }
            $id = (string) $row['team_id'];
            if (isset($seen[$id])) {
                return "team {$id} assigned to both '{$seen[$id]}' and '{$row['shift']}'";
            }
            $seen[$id] = $row['shift'];
        }

        return null;
    }

    /**
     * Resolve the aggregate publish status for a group of roster rows on one date.
     */
    private function resolveRowStatus($items): string
    {
        $statuses = $items->pluck('status')->filter()->unique()->values()->toArray();
        if (empty($statuses)) {
            return 'unassigned';
        }
        if (in_array('draft', $statuses, true)) {
            return 'draft';
        }

        return 'published';
    }
}
