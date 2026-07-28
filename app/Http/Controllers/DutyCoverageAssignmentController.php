<?php

namespace App\Http\Controllers;

use App\Models\DutyCoverageAssignment;
use App\Models\Roster;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\ReportRoutingReconciliationService;
use App\Services\RoleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class DutyCoverageAssignmentController extends Controller
{
    public function __construct(
        private readonly ReportRoutingReconciliationService $routingReconciliation,
        private readonly AssignmentAuthorizationService $authorization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'userId' => ['nullable', 'integer', 'exists:users,id'],
            'teamId' => ['nullable', 'integer', 'exists:teams,id'],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date', 'after:from'],
            'status' => ['nullable', Rule::in(['active', 'scheduled', 'expired', 'cancelled'])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $now = now();
        $permittedTeamIds = $this->authorization->permittedTeamIds(
            $request->user(),
            'teams.manage|rosters.manage',
        );
        if (
            isset($data['teamId'])
            && $permittedTeamIds !== null
            && ! $permittedTeamIds->contains((int) $data['teamId'])
        ) {
            abort(403, 'You are not authorized to manage duty coverage for this team.');
        }

        $query = DutyCoverageAssignment::query()
            ->with([
                'user:id,name,status',
                'actingTeam:id,name',
                'homeTeam:id,name',
                'actingRole:id,name',
                'replacesUser:id,name',
                'roster:id,date,shift,team_id,status',
            ])
            ->when(
                isset($data['userId']),
                fn ($builder) => $builder->where('user_id', $data['userId']),
            )
            ->when(
                isset($data['teamId']),
                fn ($builder) => $builder->where('acting_team_id', $data['teamId']),
            )
            ->when(
                $permittedTeamIds !== null,
                fn ($builder) => $builder->whereIn('acting_team_id', $permittedTeamIds->all()),
            )
            ->when(
                isset($data['from']),
                fn ($builder) => $builder->where(
                    'effective_until',
                    '>',
                    Carbon::parse($data['from']),
                ),
            )
            ->when(
                isset($data['until']),
                fn ($builder) => $builder->where(
                    'effective_from',
                    '<',
                    Carbon::parse($data['until']),
                ),
            );

        match ($data['status'] ?? null) {
            'active' => $query->whereNull('cancelled_at')
                ->where('effective_from', '<=', $now)
                ->where('effective_until', '>', $now),
            'scheduled' => $query->whereNull('cancelled_at')->where('effective_from', '>', $now),
            'expired' => $query->whereNull('cancelled_at')->where('effective_until', '<=', $now),
            'cancelled' => $query->whereNotNull('cancelled_at'),
            default => null,
        };

        $page = $query
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->paginate((int) ($data['perPage'] ?? 50));

        return response()->json([
            'data' => collect($page->items())->map(fn ($assignment) => $this->payload($assignment)),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->validationRules());
        $this->ensureTeamPermission($request, (int) $data['acting_team_id']);
        $actorId = (int) $request->user()->id;
        $prepared = $this->prepareAssignment($data, $actorId);

        $assignment = DB::transaction(function () use ($prepared, $actorId) {
            $this->ensureNoOverlap($prepared);

            $assignment = DutyCoverageAssignment::query()->create($prepared);
            $this->reconcileIfEffective($assignment, $actorId);

            return $assignment;
        });

        AuditLogger::log($request, 'duty_coverage_created', null, [
            'assignment_id' => $assignment->id,
            'user_id' => $assignment->user_id,
            'acting_team_id' => $assignment->acting_team_id,
            'acting_role_id' => $assignment->acting_role_id,
            'effective_from' => $assignment->effective_from?->toIso8601String(),
            'effective_until' => $assignment->effective_until?->toIso8601String(),
        ]);

        return response()->json([
            'data' => $this->payload($assignment->load($this->relations())),
        ], 201);
    }

    public function update(
        Request $request,
        DutyCoverageAssignment $dutyCoverageAssignment,
    ): JsonResponse {
        if ($dutyCoverageAssignment->cancelled_at !== null) {
            throw ValidationException::withMessages([
                'status' => ['Cancelled duty coverage cannot be edited.'],
            ]);
        }
        $this->ensureTeamPermission(
            $request,
            (int) $dutyCoverageAssignment->acting_team_id,
        );

        $current = [
            'user_id' => $dutyCoverageAssignment->user_id,
            'acting_team_id' => $dutyCoverageAssignment->acting_team_id,
            'acting_role' => $dutyCoverageAssignment->actingRole()->value('name'),
            'replaces_user_id' => $dutyCoverageAssignment->replaces_user_id,
            'roster_id' => $dutyCoverageAssignment->roster_id,
            'shift_key' => $dutyCoverageAssignment->shift_key,
            'effective_from' => $dutyCoverageAssignment->effective_from?->toIso8601String(),
            'effective_until' => $dutyCoverageAssignment->effective_until?->toIso8601String(),
            'reason' => $dutyCoverageAssignment->reason,
        ];
        $data = Validator::make(
            array_merge($current, $request->only(array_keys($this->validationRules()))),
            $this->validationRules(),
        )->validate();
        $this->ensureTeamPermission($request, (int) $data['acting_team_id']);
        $prepared = $this->prepareAssignment(
            $data,
            (int) $request->user()->id,
            $dutyCoverageAssignment,
        );

        DB::transaction(function () use ($dutyCoverageAssignment, $prepared, $request) {
            $this->ensureNoOverlap($prepared, (int) $dutyCoverageAssignment->id);
            $dutyCoverageAssignment->update($prepared);
            $this->reconcileIfEffective(
                $dutyCoverageAssignment->fresh(),
                (int) $request->user()->id,
            );
        });

        AuditLogger::log($request, 'duty_coverage_updated', null, [
            'assignment_id' => $dutyCoverageAssignment->id,
        ]);

        return response()->json([
            'data' => $this->payload($dutyCoverageAssignment->fresh($this->relations())),
        ]);
    }

    public function cancel(
        Request $request,
        DutyCoverageAssignment $dutyCoverageAssignment,
    ): JsonResponse {
        $this->ensureTeamPermission(
            $request,
            (int) $dutyCoverageAssignment->acting_team_id,
        );
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($dutyCoverageAssignment, $request, $data): void {
            if ($dutyCoverageAssignment->cancelled_at !== null) {
                return;
            }
            $dutyCoverageAssignment->update([
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $request->user()->id,
                'cancellation_reason' => trim($data['reason']),
            ]);
            $this->routingReconciliation->reconcile(
                (int) $dutyCoverageAssignment->acting_team_id,
                (string) $dutyCoverageAssignment->actingRole()->value('name'),
                (int) $request->user()->id,
            );
        });

        AuditLogger::log($request, 'duty_coverage_cancelled', null, [
            'assignment_id' => $dutyCoverageAssignment->id,
            'reason' => trim($data['reason']),
        ]);

        return response()->json([
            'data' => $this->payload($dutyCoverageAssignment->fresh($this->relations())),
        ]);
    }

    private function validationRules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'acting_team_id' => ['required', 'integer', 'exists:teams,id'],
            'acting_role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'replaces_user_id' => ['nullable', 'integer', 'different:user_id', 'exists:users,id'],
            'roster_id' => ['nullable', 'integer', 'exists:rosters,id'],
            'shift_key' => ['nullable', 'string', 'max:80'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['required', 'date', 'after:effective_from'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function ensureTeamPermission(Request $request, int $teamId): void
    {
        if (! $this->authorization->hasPermission(
            $request->user(),
            'teams.manage|rosters.manage',
            $teamId,
        )) {
            abort(403, 'You are not authorized to manage duty coverage for this team.');
        }
    }

    private function reconcileIfEffective(
        DutyCoverageAssignment $assignment,
        int $actorUserId,
    ): void {
        if (! $assignment->effective_from?->lte(now())
            || ! $assignment->effective_until?->gt(now())
            || $assignment->cancelled_at !== null) {
            return;
        }

        $this->routingReconciliation->reconcile(
            (int) $assignment->acting_team_id,
            (string) $assignment->actingRole()->value('name'),
            $actorUserId,
        );
    }

    private function prepareAssignment(
        array $data,
        int $actorId,
        ?DutyCoverageAssignment $existing = null,
    ): array {
        $roleName = trim((string) ($data['acting_role'] ?? ''));
        $role = Role::query()
            ->where('guard_name', 'web')
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($roleName)])
            ->firstOrFail();
        $canonicalRole = RoleCatalog::canonicalRoleName($role->name);
        if (
            $canonicalRole === null
            || RoleCatalog::scopeForRole($canonicalRole) !== RoleCatalog::SITE
        ) {
            throw ValidationException::withMessages([
                'acting_role' => ['Duty coverage is limited to team-scoped operational roles.'],
            ]);
        }
        $user = User::query()->findOrFail((int) $data['user_id']);
        if (
            $user->status !== null
            && strtolower(trim((string) $user->status)) !== 'active'
        ) {
            throw ValidationException::withMessages([
                'user_id' => ['Duty coverage requires an active linked user.'],
            ]);
        }
        if (! $this->userIsQualifiedForRole($user, (int) $role->id)) {
            throw ValidationException::withMessages([
                'acting_role' => [
                    "{$user->name} does not have an active {$role->name} qualification.",
                ],
            ]);
        }
        $actingTeamId = (int) $data['acting_team_id'];
        $replacesUserId = isset($data['replaces_user_id'])
            ? (int) $data['replaces_user_id']
            : null;
        if (
            $replacesUserId
            && ! $this->userHasActiveTeamRole($replacesUserId, (int) $role->id, $actingTeamId)
        ) {
            throw ValidationException::withMessages([
                'replaces_user_id' => [
                    'The replaced user must currently hold the acting role on the acting team.',
                ],
            ]);
        }
        $rosterId = isset($data['roster_id']) ? (int) $data['roster_id'] : null;
        if (
            $rosterId
            && ! Roster::query()->whereKey($rosterId)->where('team_id', $actingTeamId)->exists()
        ) {
            throw ValidationException::withMessages([
                'roster_id' => ['The selected roster must belong to the acting team.'],
            ]);
        }

        $homeTeamId = UserRoleAssignment::query()
            ->where('user_id', $user->id)
            ->whereNotNull('team_id')
            ->orderByDesc('is_primary')
            ->value('team_id')
            ?? TeamMember::query()
                ->where('user_id', $user->id)
                ->whereNull('ended_at')
                ->orderByDesc('is_primary')
                ->value('team_id');

        return [
            'user_id' => (int) $user->id,
            'acting_team_id' => $actingTeamId,
            'home_team_id' => $homeTeamId ? (int) $homeTeamId : null,
            'acting_role_id' => (int) $role->id,
            'replaces_user_id' => $replacesUserId,
            'roster_id' => $rosterId,
            'shift_key' => trim((string) ($data['shift_key'] ?? '')) ?: null,
            'effective_from' => Carbon::parse($data['effective_from'])->utc(),
            'effective_until' => Carbon::parse($data['effective_until'])->utc(),
            'reason' => trim((string) ($data['reason'] ?? '')) ?: null,
            'approved_by_user_id' => $existing?->approved_by_user_id ?? $actorId,
            'created_by_user_id' => $existing?->created_by_user_id ?? $actorId,
        ];
    }

    private function ensureNoOverlap(array $data, ?int $excludeId = null): void
    {
        $overlap = DutyCoverageAssignment::query()
            ->where('user_id', $data['user_id'])
            ->whereNull('cancelled_at')
            ->where('effective_from', '<', $data['effective_until'])
            ->where('effective_until', '>', $data['effective_from'])
            ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))
            ->lockForUpdate()
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'effective_from' => [
                    'This user already has duty coverage during part of the selected window.',
                ],
            ]);
        }
    }

    private function userIsQualifiedForRole(User $user, int $roleId): bool
    {
        $today = now()->toDateString();

        return UserRoleAssignment::query()
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->where(function ($query) use ($today) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today);
            })
            ->where(function ($query) use ($today) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            })
            ->exists();
    }

    private function userHasActiveTeamRole(int $userId, int $roleId, int $teamId): bool
    {
        $today = now()->toDateString();

        return UserRoleAssignment::query()
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->where('team_id', $teamId)
            ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
            ->whereHas(
                'user',
                fn ($query) => $query->whereNull('deleted_at')
                    ->where(fn ($userQuery) => $userQuery
                        ->whereNull('status')
                        ->orWhereRaw("LOWER(TRIM(status)) = 'active'")),
            )
            ->exists();
    }

    private function payload(DutyCoverageAssignment $assignment): array
    {
        $now = now();
        $status = $assignment->cancelled_at
            ? 'cancelled'
            : ($assignment->effective_from->isFuture()
                ? 'scheduled'
                : ($assignment->effective_until->lte($now) ? 'expired' : 'active'));

        return [
            'id' => (int) $assignment->id,
            'user' => [
                'id' => (int) $assignment->user_id,
                'name' => (string) ($assignment->user?->name ?? ''),
            ],
            'homeTeam' => $assignment->home_team_id ? [
                'id' => (int) $assignment->home_team_id,
                'name' => (string) ($assignment->homeTeam?->name ?? ''),
            ] : null,
            'actingTeam' => [
                'id' => (int) $assignment->acting_team_id,
                'name' => (string) ($assignment->actingTeam?->name ?? ''),
            ],
            'actingRole' => (string) ($assignment->actingRole?->name ?? ''),
            'replacesUser' => $assignment->replaces_user_id ? [
                'id' => (int) $assignment->replaces_user_id,
                'name' => (string) ($assignment->replacesUser?->name ?? ''),
            ] : null,
            'rosterId' => $assignment->roster_id ? (int) $assignment->roster_id : null,
            'shiftKey' => $assignment->shift_key,
            'effectiveFrom' => $assignment->effective_from?->toIso8601String(),
            'effectiveUntil' => $assignment->effective_until?->toIso8601String(),
            'status' => $status,
            'reason' => $assignment->reason,
            'cancelledAt' => $assignment->cancelled_at?->toIso8601String(),
            'cancellationReason' => $assignment->cancellation_reason,
        ];
    }

    private function relations(): array
    {
        return [
            'user:id,name,status',
            'actingTeam:id,name',
            'homeTeam:id,name',
            'actingRole:id,name',
            'replacesUser:id,name',
            'roster:id,date,shift,team_id,status',
        ];
    }
}
