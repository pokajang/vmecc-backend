<?php

namespace App\Http\Controllers;

use App\Models\DeletedTeam;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\RoleCatalog;
use App\Services\TeamMemberSyncService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{
    public function __construct(
        private readonly TeamMemberSyncService $teamMemberSync,
        private readonly AssignmentAuthorizationService $authorizationService,
    ) {}

    private function teamPayload(Team $team, bool $withMembers = false): array
    {
        $payload = [
            'id' => $team->id,
            'name' => $team->name,
            'group' => $team->group,
            'status' => $team->status,
            'lead_name' => $team->lead_name,
            'lead_id' => $team->lead_id,
            'image_url' => $team->image_url
                ? (str_starts_with($team->image_url, 'preset:')
                    ? $team->image_url
                    : Storage::disk($this->publicUploadsDisk())->url($team->image_url))
                : null,
            'created_at' => $team->created_at,
            'updated_at' => $team->updated_at,
        ];

        if ($withMembers) {
            $payload['members'] = $team->members
                ->filter(fn ($m) => $m->ended_at === null)
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'role' => $m->role,
                    'user_id' => $m->user_id,
                    'is_primary' => (bool) $m->is_primary,
                    'started_at' => $m->started_at?->toDateString(),
                    'ended_at' => $m->ended_at?->toDateString(),
                    ...$this->workflowEligibilityPayload($m),
                ])->values();

            $payload['past_members'] = $team->members
                ->filter(fn ($m) => $m->ended_at !== null)
                ->sortByDesc('ended_at')
                ->take(50)
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'role' => $m->role,
                    'user_id' => $m->user_id,
                    'is_primary' => (bool) $m->is_primary,
                    'started_at' => $m->started_at?->toDateString(),
                    'ended_at' => $m->ended_at?->toDateString(),
                ])->values();
        } else {
            $payload['members'] = [];
            $payload['past_members'] = [];
        }

        return $payload;
    }

    private function loadMembers(Team $team): void
    {
        $team->load([
            'members' => function ($query) {
                $query->orderByDesc('is_primary')->orderBy('name');
            },
            'members.user.roleAssignments.role',
        ]);
    }

    /**
     * Return all teams with their members.
     */
    public function index(): JsonResponse
    {
        $teams = Team::with([
            'members' => function ($query) {
                $query->orderByDesc('is_primary')->orderBy('name');
            },
            'members.user.roleAssignments.role',
        ])
            ->orderBy('name')
            ->get()
            ->map(fn (Team $team) => $this->teamPayload($team, true));

        return response()->json(['data' => $teams]);
    }

    public function memberOptions(): JsonResponse
    {
        $teamMap = TeamMember::with('team')->whereNull('ended_at')->get()->groupBy('user_id');

        $users = User::query()
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($teamMap) {
                $teamEntry = $teamMap?->get($user->id)?->first();
                $team = $teamEntry?->team;

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status,
                    'roles' => $this->authorizationService->getActiveRoleNames($user)->values()->all(),
                    'role_assignments' => $this->authorizationService->getRoleAssignmentsPayload($user),
                    'team' => $team?->name ?? $user->team,
                    'team_status' => $team?->status,
                    'profile_image_url' => $this->resolveProfileImageUrl($user->profile_image_url),
                ];
            });

        return response()->json(['data' => $users]);
    }

    /**
     * Return a single team with members.
     */
    public function show(Team $team): JsonResponse
    {
        $this->loadMembers($team);

        return response()->json(['data' => $this->teamPayload($team, true)]);
    }

    /**
     * Create a new team.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:teams,name',
            'group' => 'sometimes|nullable|string|max:100',
            'status' => 'sometimes|string|max:50',
        ]);

        $team = Team::create([
            'name' => $data['name'],
            'group' => $data['group'] ?? null,
            'status' => $data['status'] ?? config('team.default_status', 'On Duty'),
        ]);

        AuditLogger::log($request, 'team_created', null, [
            'team_id' => $team->id,
            'team_name' => $team->name,
        ]);

        return response()->json(['data' => $this->teamPayload($team)], 201);
    }

    /**
     * Upload a team profile image.
     * POST /teams/{team}/image
     */
    public function uploadImage(Request $request, Team $team): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'max:4096', 'mimes:jpeg,png,webp,gif'],
        ]);

        $disk = $this->publicUploadsDisk();
        $oldImage = ($team->image_url && ! str_starts_with($team->image_url, 'preset:'))
            ? $team->image_url
            : null;
        $path = $request->file('image')->store('teams', ['disk' => $disk]);
        try {
            $team->update(['image_url' => $path]);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
        if ($oldImage && $oldImage !== $path) {
            Storage::disk($disk)->delete($oldImage);
        }

        return response()->json([
            'data' => [
                'image_url' => Storage::disk($disk)->url($path),
            ],
        ]);
    }

    /**
     * Update team meta and members.
     */
    public function update(Request $request, Team $team): JsonResponse
    {
        // When the request arrives as multipart/form-data (atomic image+members path),
        // the members array is JSON-encoded in a single field. Decode it back so that
        // the rest of the method works identically regardless of transport.
        $rawMembers = $request->input('members');
        if (is_string($rawMembers)) {
            $request->merge(['members' => json_decode($rawMembers, true) ?? []]);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255|unique:teams,name,'.$team->id,
            'group' => 'sometimes|nullable|string|max:100',
            'status' => 'sometimes|nullable|string|max:50',
            'image_url' => ['sometimes', 'nullable', 'string', 'max:500', 'regex:/^(preset:[a-z]+|teams\/.+)$/'],
            'members' => 'array',
            'members.*.name' => 'required|string|max:255',
            'members.*.role' => 'nullable|string|max:255',
            'members.*.user_id' => 'nullable|exists:users,id',
            'members.*.is_primary' => 'boolean',
            'members.*.started_at' => 'nullable|date',
        ]);
        $this->validateWorkflowMemberIdentities($data['members'] ?? [], $team->id);
        if (array_key_exists('members', $data)) {
            $this->validateOperationalMemberRemovals($team, $data['members']);
        }

        // When a file was uploaded as part of the atomic multipart request, store it now
        // and treat it exactly like a regular image_url update for the rest of the method.
        $uploadedImagePath = null;
        if ($request->hasFile('image')) {
            $request->validate([
                'image' => ['file', 'image', 'max:4096', 'mimes:jpeg,png,webp,gif'],
            ]);
            $disk = $this->publicUploadsDisk();
            $uploadedImagePath = $request->file('image')->store('teams', ['disk' => $disk]);
            $data['image_url'] = $uploadedImagePath;
        }

        // Prevent assigning a user who is already an active member of a different team.
        if (! empty($data['members'])) {
            $incomingUserIds = collect($data['members'])->pluck('user_id')->filter()->unique()->values();
            $conflicts = TeamMember::query()
                ->whereIn('user_id', $incomingUserIds)
                ->where('team_id', '!=', $team->id)
                ->whereNull('ended_at')
                ->with('team:id,name')
                ->get();

            if ($conflicts->isNotEmpty()) {
                $messages = $conflicts->map(fn ($m) => "{$m->name} is already an active member of {$m->team->name}")->join(', ');

                return response()->json([
                    'message' => 'One or more members are already assigned to another team.',
                    'errors' => ['members' => [$messages]],
                ], 422);
            }
        }

        // image_url in update payload:
        //   null        → clear (delete uploaded file if any)
        //   "preset:x"  → store key as-is
        //   absent      → leave image_url unchanged
        $updateFields = ['name' => $data['name'], 'group' => $data['group'] ?? $team->group];
        $oldImageToDelete = null;
        if (array_key_exists('image_url', $data)) {
            $newImageUrl = $data['image_url'];
            if (
                $team->image_url
                && ! str_starts_with($team->image_url, 'preset:')
                && $newImageUrl !== $team->image_url
            ) {
                $oldImageToDelete = $team->image_url;
            }
            $updateFields['image_url'] = $newImageUrl;
        }
        $newUserIds = collect();
        $membersForLookup = [];

        try {
            DB::transaction(function () use ($team, $updateFields, $data, &$newUserIds, &$membersForLookup) {
                $team->update($updateFields);

                if (isset($data['members'])) {
                    $this->linkUnassignedScopedRoles($data['members'], $team->id);
                    $incoming = collect($data['members']);
                    $existing = $team->members()->get()->keyBy(fn ($m) => $m->user_id ?? 'name:'.$m->name);
                    $incomingKeys = $incoming->map(fn ($m) => $m['user_id'] ?? ('name:'.$m['name']))->toArray();

                    // Track which user_ids are genuinely new (not currently active members)
                    $activeUserIds = $team->members()->whereNull('ended_at')->pluck('user_id')->filter()->values();
                    $newUserIds = collect($data['members'])
                        ->pluck('user_id')
                        ->filter()
                        ->diff($activeUserIds)
                        ->values();
                    $membersForLookup = $data['members'];

                    $team->members()
                        ->whereNull('ended_at')
                        ->get()
                        ->each(function ($member) use ($incomingKeys) {
                            $key = $member->user_id ?? 'name:'.$member->name;
                            if (! in_array($key, $incomingKeys, true)) {
                                $member->update(['ended_at' => now()]);
                            }
                        });

                    $incoming->each(function ($member) use ($team, $existing) {
                        $key = $member['user_id'] ?? ('name:'.$member['name']);
                        $current = $existing->get($key);
                        $team->members()->updateOrCreate(
                            [
                                'team_id' => $team->id,
                                'user_id' => $member['user_id'] ?? null,
                                'name' => $member['name'],
                            ],
                            [
                                'role' => $member['role'] ?? null,
                                'is_primary' => $member['is_primary'] ?? false,
                                'started_at' => $member['started_at'] ?? ($current?->started_at?->toDateString() ?? now()->toDateString()),
                                'ended_at' => null,
                            ],
                        );
                    });
                }
            });
        } catch (\Throwable $exception) {
            if ($uploadedImagePath) {
                Storage::disk($this->publicUploadsDisk())->delete($uploadedImagePath);
            }
            throw $exception;
        }

        // Delete old image file after DB commit so an upload failure doesn't orphan the file reference
        if ($oldImageToDelete) {
            Storage::disk($this->publicUploadsDisk())->delete($oldImageToDelete);
        }

        // Send notifications outside the transaction so the DB is fully consistent if mail fails.
        // Eager-load all new-member users in one query instead of N individual finds.
        if ($newUserIds->isNotEmpty()) {
            $newMemberUsers = User::whereIn('id', $newUserIds)->get()->keyBy('id');
            foreach ($newUserIds as $userId) {
                $newMember = $newMemberUsers->get($userId);
                if (! $newMember) {
                    continue;
                }
                $roleName = collect($membersForLookup)->firstWhere('user_id', $userId)['role'] ?? '';
                $this->teamMemberSync->fireNewMemberNotifications($newMember, $team->id, $roleName);
            }
        }

        $this->loadMembers($team);

        AuditLogger::log($request, 'team_updated', null, [
            'team_id' => $team->id,
            'team_name' => $team->name,
            'added_users' => $newUserIds->values()->all(),
            'member_count' => $team->members->count(),
        ]);

        return response()->json(['data' => $this->teamPayload($team, true)]);
    }

    private function validateWorkflowMemberIdentities(array $members, ?int $teamId = null): void
    {
        $errors = [];
        $today = now()->toDateString();
        foreach ($members as $index => $member) {
            $role = RoleCatalog::canonicalRoleName($member['role'] ?? null);
            if (
                $role !== null
                && RoleCatalog::isScopedRole($role)
                && empty($member['user_id'])
            ) {
                $isExistingLegacyRow = $teamId
                    && TeamMember::query()
                        ->where('team_id', $teamId)
                        ->whereNull('user_id')
                        ->whereNull('ended_at')
                        ->where('name', $member['name'] ?? '')
                        ->whereRaw('LOWER(TRIM(COALESCE(role, \'\'))) = ?', [strtolower($role)])
                        ->exists();
                if (! $isExistingLegacyRow) {
                    $errors["members.{$index}.user_id"] = [
                        "{$role} is an operational role and must be linked to an active user account.",
                    ];
                }
            } elseif (
                $role !== null
                && RoleCatalog::isScopedRole($role)
                && $teamId
                && ! TeamMember::query()
                    ->where('user_id', $member['user_id'])
                    ->where('team_id', '!=', $teamId)
                    ->whereNull('ended_at')
                    ->exists()
                && ! UserRoleAssignment::query()
                    ->where('user_id', $member['user_id'])
                    ->where('scope_type', RoleCatalog::scopeForRole($role))
                    ->where(fn ($query) => $query->where('team_id', $teamId)->orWhereNull('team_id'))
                    ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
                    ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
                    ->whereHas(
                        'role',
                        fn ($query) => $query->whereRaw(
                            'LOWER(TRIM(name)) = ?',
                            [strtolower($role)],
                        ),
                    )
                    ->exists()
            ) {
                $errors["members.{$index}.role"] = [
                    "{$role} must have a matching active scoped role assignment for this team.",
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function linkUnassignedScopedRoles(array $members, int $teamId): void
    {
        $today = now()->toDateString();
        foreach ($members as $member) {
            $userId = (int) ($member['user_id'] ?? 0);
            $role = RoleCatalog::canonicalRoleName($member['role'] ?? null);
            if ($userId <= 0 || $role === null || ! RoleCatalog::isScopedRole($role)) {
                continue;
            }

            $assignment = UserRoleAssignment::query()
                ->where('user_id', $userId)
                ->where('scope_type', RoleCatalog::scopeForRole($role))
                ->whereNull('team_id')
                ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
                ->whereHas(
                    'role',
                    fn ($query) => $query->whereRaw(
                        'LOWER(TRIM(name)) = ?',
                        [strtolower($role)],
                    ),
                )
                ->lockForUpdate()
                ->first();

            if ($assignment) {
                $assignment->update(['team_id' => $teamId]);
            }
        }
    }

    private function validateOperationalMemberRemovals(Team $team, array $incomingMembers): void
    {
        $incomingUserIds = collect($incomingMembers)
            ->pluck('user_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();
        $today = now()->toDateString();
        $protected = $team->members()
            ->whereNull('ended_at')
            ->whereNotNull('user_id')
            ->whereNotIn('user_id', $incomingUserIds)
            ->get()
            ->filter(function (TeamMember $member) use ($today) {
                $role = RoleCatalog::canonicalRoleName($member->role);
                if ($role === null || ! RoleCatalog::isScopedRole($role)) {
                    return false;
                }

                return UserRoleAssignment::query()
                    ->where('user_id', $member->user_id)
                    ->where('team_id', $member->team_id)
                    ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
                    ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
                    ->whereHas(
                        'role',
                        fn ($query) => $query->whereRaw(
                            'LOWER(TRIM(name)) = ?',
                            [strtolower((string) $role)],
                        ),
                    )
                    ->exists();
            });

        if ($protected->isNotEmpty()) {
            throw ValidationException::withMessages([
                'members' => [
                    'Active operational assignments cannot be removed from the Team Directory. '
                    .'Use Permanent team transfer or update the scoped role assignment.',
                ],
            ]);
        }
    }

    private function workflowEligibilityPayload(TeamMember $member): array
    {
        $role = RoleCatalog::canonicalRoleName($member->role);
        if ($role === null || ! RoleCatalog::isScopedRole($role)) {
            return [
                'workflow_eligible' => false,
                'workflow_block_reason' => 'informational_member',
            ];
        }
        if (! $member->user_id) {
            return [
                'workflow_eligible' => false,
                'workflow_block_reason' => 'unlinked_user',
            ];
        }

        $today = now()->toDateString();
        $user = $member->user;
        $eligible = $user
            && $user->deleted_at === null
            && (
                $user->status === null
                || strtolower(trim((string) $user->status)) === 'active'
            )
            && ($user->relationLoaded('roleAssignments')
                ? $user->roleAssignments->contains(
                    fn (UserRoleAssignment $assignment) => (
                        (int) $assignment->team_id === (int) $member->team_id
                        && (! $assignment->start_date || $assignment->start_date->toDateString() <= $today)
                        && (! $assignment->end_date || $assignment->end_date->toDateString() >= $today)
                        && strcasecmp((string) $assignment->role?->name, $role) === 0
                    ),
                )
                : UserRoleAssignment::query()
                    ->where('user_id', $member->user_id)
                    ->where('team_id', $member->team_id)
                    ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
                    ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
                    ->whereHas(
                        'role',
                        fn ($query) => $query->whereRaw(
                            'LOWER(TRIM(name)) = ?',
                            [strtolower($role)],
                        ),
                    )
                    ->exists());

        return [
            'workflow_eligible' => $eligible,
            'workflow_block_reason' => $eligible ? null : 'missing_team_role_assignment',
        ];
    }

    /** Delete only an empty, unreferenced team and retain a recovery snapshot. */
    public function destroy(Request $request, Team $team): JsonResponse
    {
        if (! $this->authorizationService->hasOrganizationWidePermission($request->user(), 'teams.manage')) {
            abort(403, 'Only an organization-wide team manager may delete a team.');
        }

        $confirmation = $request->validate([
            'confirm_name' => ['required', 'string', 'max:255'],
            'expected_updated_at' => ['required', 'date'],
        ]);
        $deleted = DB::transaction(function () use ($team, $confirmation, $request): array {
            $lockedTeam = Team::query()->lockForUpdate()->findOrFail($team->id);
            if (! hash_equals($lockedTeam->name, trim((string) $confirmation['confirm_name']))) {
                throw ValidationException::withMessages([
                    'confirm_name' => ['The confirmation name does not match the selected team.'],
                ]);
            }
            if (! $lockedTeam->updated_at || ! $lockedTeam->updated_at->equalTo($confirmation['expected_updated_at'])) {
                throw new HttpResponseException(response()->json([
                    'message' => 'This team changed after it was loaded. Refresh before deleting it.',
                    'code' => 'team_delete_version_conflict',
                ], 409));
            }

            $dependencies = $this->teamDeletionDependencies($lockedTeam);
            if (collect($dependencies)->sum() > 0) {
                throw new HttpResponseException(response()->json([
                    'message' => 'This team is still referenced by operational or historical records and cannot be deleted.',
                    'code' => 'team_delete_dependencies_exist',
                    'dependencies' => $dependencies,
                ], 409));
            }

            DeletedTeam::create([
                'original_team_id' => $lockedTeam->id,
                'name' => $lockedTeam->name,
                'group' => $lockedTeam->group,
                'status' => $lockedTeam->status,
                'image_url' => $lockedTeam->image_url,
                'lead_id' => $lockedTeam->lead_id,
                'lead_name' => $lockedTeam->lead_name,
                'members_snapshot' => [],
                'dependencies_snapshot' => $dependencies,
                'deleted_by_user_id' => $request->user()?->id,
                'deleted_at' => now(),
            ]);

            $deleted = [
                'name' => $lockedTeam->name,
                'image' => ($lockedTeam->image_url && ! str_starts_with($lockedTeam->image_url, 'preset:'))
                    ? $lockedTeam->image_url
                    : null,
            ];
            $lockedTeam->delete();

            return $deleted;
        });

        if ($deleted['image']) {
            Storage::disk($this->publicUploadsDisk())->delete($deleted['image']);
        }

        AuditLogger::log($request, 'team_deleted', null, [
            'team_name' => $deleted['name'],
            'member_count' => 0,
            'members' => [],
        ]);

        return response()->json(null, 204);
    }

    private function teamDeletionDependencies(Team $team): array
    {
        $teamId = (int) $team->id;
        $count = static function (string $table, string|array $columns) use ($teamId): int {
            if (! Schema::hasTable($table)) {
                return 0;
            }
            $columns = (array) $columns;
            $available = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($table, $column)));
            if ($available === []) {
                return 0;
            }

            return (int) DB::table($table)
                ->where(function ($query) use ($available, $teamId): void {
                    foreach ($available as $index => $column) {
                        $index === 0
                            ? $query->where($column, $teamId)
                            : $query->orWhere($column, $teamId);
                    }
                })
                ->count();
        };

        return [
            'team_members' => $count('team_members', 'team_id'),
            'role_assignments' => $count('user_role_assignments', 'team_id'),
            'rosters' => $count('rosters', 'team_id'),
            'duty_coverage' => $count('duty_coverage_assignments', ['home_team_id', 'acting_team_id']),
            'reports' => $count('reports', 'scope_team_id'),
            'report_routing_events' => $count('report_routing_events', 'team_id'),
            'team_role_transfers' => $count('team_role_transfers', ['from_team_id', 'to_team_id']),
            'fitness_shift_groups' => $count('fitness_test_shift_groups', 'team_id'),
            'overtime_workflows' => $count('overtime_records', 'workflow_team_id'),
            'leave_workflows' => $count('leaves', 'workflow_team_id'),
        ];
    }

    private function publicUploadsDisk(): string
    {
        return (string) config('filesystems.public_uploads_disk', 'public');
    }

    private function resolveProfileImageUrl(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return Storage::disk($this->publicUploadsDisk())->url($value);
    }
}
