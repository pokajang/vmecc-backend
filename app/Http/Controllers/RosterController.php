<?php

namespace App\Http\Controllers;

use App\Models\CustomShift;
use App\Models\Roster;
use App\Models\Team;
use App\Models\User;
use App\Notifications\RosterPublishedNotification;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
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
    ) {
    }

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
            'date'     => ['sometimes', 'nullable', 'date'],
            'from'     => ['sometimes', 'nullable', 'date'],
            'to'       => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'status'   => ['sometimes', 'nullable', 'string', 'in:draft,published,unassigned'],
            'months'   => ['sometimes', 'nullable', 'array', 'max:24'],
            'months.*' => ['string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        if ($request->filled('from') && $request->filled('to')) {
            $diffDays = \Carbon\Carbon::parse($request->input('from'))
                ->diffInDays(\Carbon\Carbon::parse($request->input('to')));
            if ($diffDays > 366) {
                return response()->json([
                    'message' => 'Date range must not exceed 366 days.',
                    'errors'  => ['to' => ['Date range must not exceed 366 days.']],
                ], 422);
            }
        }

        $query = Roster::with('team')->orderBy('date')->orderBy('shift');

        if ($request->filled('date')) {
            $query->whereDate('date', $request->input('date'));
        }

        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('date', [$request->input('from'), $request->input('to')]);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('months')) {
            $months = $request->months;
            $query->where(function ($q) use ($months) {
                foreach ($months as $m) {
                    $monthStr = trim($m);
                    if ($monthStr === '') continue;
                    try {
                        $start = Carbon::createFromFormat('Y-m', $monthStr)->startOfMonth();
                        $end   = Carbon::createFromFormat('Y-m', $monthStr)->endOfMonth();
                        $q->orWhereBetween('date', [$start->toDateString(), $end->toDateString()]);
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            });
        }

        $rosters = $query->get()->groupBy(function ($item) {
            if ($item->date instanceof Carbon) return $item->date->toDateString();
            return substr((string) $item->date, 0, 10);
        })->map(function ($items, $date) {
            // Build a keyed map of all shifts present for this date
            $shiftsMap = [];
            foreach ($items as $row) {
                $shiftsMap[$row->shift] = [
                    'team_id' => $row->team_id,
                    'team'    => $row->team?->name,
                    'status'  => $row->status,
                ];
            }

            return [
                'date'   => $date,
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
            'entries'                => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.date'         => ['required', 'date'],
            'entries.*.shifts'       => ['required', 'array', 'min:1'],
            'entries.*.shifts.*.shift'   => ['required', 'string', Rule::in($validSlugs)],
            'entries.*.shifts.*.team_id' => ['nullable', Rule::exists('teams', 'id')],
        ]);

        foreach ($data['entries'] as $entry) {
            if ($error = $this->detectSameTeamConflict($entry['shifts'])) {
                return response()->json([
                    'message' => 'A team cannot be assigned to more than one shift on the same date.',
                    'errors'  => ['entries' => ["Conflict on {$entry['date']}: {$error}"]],
                ], 422);
            }
        }

        $userId = Auth::id();

        DB::transaction(function () use ($data, $userId) {
            foreach ($data['entries'] as $entry) {
                foreach ($entry['shifts'] as $shiftRow) {
                    $shift   = $shiftRow['shift'];
                    $teamId  = $shiftRow['team_id'];
                    if ($teamId !== null) {
                        Roster::updateOrCreate(
                            ['date' => $entry['date'], 'shift' => $shift],
                            ['team_id' => $teamId, 'status' => 'draft', 'created_by' => $userId]
                        );
                    } else {
                        Roster::where('date', $entry['date'])->where('shift', $shift)->delete();
                    }
                }
            }
        });

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
            'entries'                => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.date'         => ['required', 'date'],
            'entries.*.shifts'       => ['required', 'array', 'min:1'],
            'entries.*.shifts.*.shift'   => ['required', 'string', Rule::in($validSlugs)],
            'entries.*.shifts.*.team_id' => ['nullable', Rule::exists('teams', 'id')],
            'scope_label'            => ['required', 'string', 'max:100'],
        ]);

        foreach ($data['entries'] as $entry) {
            if ($error = $this->detectSameTeamConflict($entry['shifts'])) {
                return response()->json([
                    'message' => 'A team cannot be assigned to more than one shift on the same date.',
                    'errors'  => ['entries' => ["Conflict on {$entry['date']}: {$error}"]],
                ], 422);
            }
        }

        $userId     = Auth::id();
        $scopeLabel = $data['scope_label'];
        $now        = Carbon::now();
        $teamShifts = [];

        DB::transaction(function () use ($data, $userId, $now, &$teamShifts) {
            foreach ($data['entries'] as $entry) {
                foreach ($entry['shifts'] as $shiftRow) {
                    $shift  = $shiftRow['shift'];
                    $teamId = $shiftRow['team_id'];
                    if ($teamId !== null) {
                        Roster::updateOrCreate(
                            ['date' => $entry['date'], 'shift' => $shift],
                            [
                                'team_id'      => $teamId,
                                'status'       => 'published',
                                'created_by'   => $userId,
                                'published_by' => $userId,
                                'published_at' => $now,
                            ]
                        );
                        $teamShifts[$teamId][] = ['date' => $entry['date'], 'shift' => $shift];
                    } else {
                        Roster::where('date', $entry['date'])->where('shift', $shift)->delete();
                    }
                }
            }
        });

        $teamIds = array_keys($teamShifts);
        $rosterEmailEnabled = config('mail.workflow_notifications.enabled', false)
            && (bool) config('mail.workflow_notifications.modules.roster', false);

        if (!empty($teamIds) && $rosterEmailEnabled) {
            $teams = Team::with(['members' => fn ($q) => $q->whereNull('ended_at')])
                ->whereIn('id', $teamIds)
                ->get();
            foreach ($teams as $team) {
                $shifts      = $teamShifts[$team->id] ?? [];
                $memberUsers = User::whereIn(
                    'id',
                    $team->members->pluck('user_id')->filter()->values()->all()
                )->get()->keyBy('id');

                foreach ($team->members as $member) {
                    if (! $member->user_id) continue;
                    $user = $memberUsers->get($member->user_id);
                    if ($user && $user->email) {
                        $user->notify(new RosterPublishedNotification($scopeLabel, $shifts, $team->name));
                    }
                }
            }
        }

        AuditLogger::log($request, 'roster_published', null, [
            'scope_label' => $scopeLabel,
            'entry_count' => count($data['entries']),
            'teams_count' => count($teamIds),
        ]);

        return response()->json(['message' => 'Roster published and teams notified.']);
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
            if ($row['team_id'] === null) continue;
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
        if (empty($statuses)) return 'unassigned';
        if (in_array('draft', $statuses, true)) return 'draft';
        return 'published';
    }
}
