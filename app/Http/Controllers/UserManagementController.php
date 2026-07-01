<?php

namespace App\Http\Controllers;

use App\Models\LoginAttempt;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Models\UserSession;
use App\Notifications\AdminResetPasswordNotification;
use App\Notifications\UserInvitationNotification;
use App\Services\AssignmentAuthorizationService;
use App\Services\AuditLogger;
use App\Services\RoleCatalog;
use App\Services\TeamMemberSyncService;
use App\Support\MalaysiaStateCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserManagementController extends Controller
{
    public function __construct(
        private readonly AssignmentAuthorizationService $authorizationService,
        private readonly TeamMemberSyncService $teamMemberSync,
    ) {
    }

    public function sessions(Request $request, int $id): JsonResponse
    {
        $user = User::withTrashed()->findOrFail($id);

        $sessions = UserSession::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(function (UserSession $session) {
                $active = ! $session->revoked_at
                    && ! $session->logged_out_at
                    && $session->expires_at
                    && $session->expires_at->isFuture();

                return [
                    'id' => $session->id,
                    'ip_address' => $session->ip_address,
                    'user_agent' => $session->user_agent,
                    'device_id' => $session->device_id,
                    'client_mode' => $session->client_mode,
                    'created_at' => optional($session->created_at)->toIso8601String(),
                    'last_seen_at' => optional($session->last_seen_at)->toIso8601String(),
                    'expires_at' => optional($session->expires_at)->toIso8601String(),
                    'revoked_at' => optional($session->revoked_at)->toIso8601String(),
                    'logged_out_at' => optional($session->logged_out_at)->toIso8601String(),
                    'active' => $active,
                ];
            });

        return response()->json(['data' => $sessions]);
    }

    public function revokeSession(Request $request, int $id, string $sessionId): JsonResponse
    {
        $user = User::withTrashed()->findOrFail($id);
        $session = UserSession::where('user_id', $user->id)->where('id', $sessionId)->firstOrFail();

        if (! $session->revoked_at && ! $session->logged_out_at) {
            $session->forceFill([
                'revoked_at' => now(),
                'logged_out_at' => now(),
                'revoked_by' => $request->user()?->id,
                'revoke_reason' => $request->input('reason'),
                'remember_token_hash' => null,
                'remember_expires_at' => null,
            ])->save();

            AuditLogger::log($request, 'user_session_revoked', $user, [
                'session_id' => $session->id,
            ]);
        }

        return response()->json(['message' => 'Session revoked.']);
    }

    public function revokeAllSessions(Request $request, int $id): JsonResponse
    {
        $user = User::withTrashed()->findOrFail($id);

        $updated = UserSession::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->whereNull('logged_out_at')
            ->update([
                'revoked_at' => now(),
                'logged_out_at' => now(),
                'revoked_by' => $request->user()?->id,
                'revoke_reason' => $request->input('reason'),
                'remember_token_hash' => null,
                'remember_expires_at' => null,
            ]);

        AuditLogger::log($request, 'user_sessions_revoked_all', $user, [
            'count' => $updated,
        ]);

        return response()->json(['message' => 'All sessions revoked.']);
    }

    public function index(Request $request): JsonResponse
    {
        $teamMap = TeamMember::with('team')->get()->groupBy('user_id');
        $includeDeleted = request()->boolean('include_deleted');
        $canViewSensitiveManagementFields = $this->authorizationService->hasPermission(
            $request->user(),
            'users.manage'
        );

        $query = User::query();
        if ($includeDeleted) {
            $query->withTrashed();
        }

        $users = $query
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->formatUserPayload($user, $teamMap, $canViewSensitiveManagementFields));

        return response()->json(['data' => $users]);
    }

    public function stateQualityReport(): JsonResponse
    {
        $allowedStates = MalaysiaStateCatalog::values();
        $allowedLower = array_map(fn ($state) => mb_strtolower($state), $allowedStates);

        $invalidUsers = User::query()
            ->select(['id', 'name', 'email', 'state'])
            ->whereNotNull('state')
            ->whereRaw('TRIM(state) <> ?', [''])
            ->get()
            ->filter(function (User $user) use ($allowedLower) {
                return !in_array(mb_strtolower(trim((string) $user->state)), $allowedLower, true);
            })
            ->values()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'state' => $user->state,
                ];
            })
            ->all();

        $missingCount = User::query()
            ->where(function ($query) {
                $query->whereNull('state')->orWhereRaw('TRIM(state) = ?', ['']);
            })
            ->count();

        return response()->json([
            'data' => [
                'allowed_states' => $allowedStates,
                'missing_state_count' => $missingCount,
                'invalid_state_count' => count($invalidUsers),
                'invalid_state_users' => $invalidUsers,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['nullable', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'role_assignments' => ['nullable', 'array', 'min:1'],
            'role_assignments.*.role' => ['required_with:role_assignments', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'role_assignments.*.scope_type' => ['nullable', Rule::in([RoleCatalog::GLOBAL, RoleCatalog::OFFICE, RoleCatalog::SITE, RoleCatalog::CLIENT_SITE])],
            'role_assignments.*.team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'role_assignments.*.start_date' => ['nullable', 'date'],
            'role_assignments.*.end_date' => ['nullable', 'date'],
            'role_assignments.*.is_primary' => ['nullable', 'boolean'],
        ]);

        if (! $request->filled('role') && empty($data['role_assignments'])) {
            throw ValidationException::withMessages([
                'role' => ['Provide role or role_assignments.'],
            ]);
        }

        $user = null;
        DB::transaction(function () use (&$user, $data, $request) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make(Str::random(32)),
                'status' => 'Active',
            ]);

            $incoming = $data['role_assignments'] ?? [];
            if (empty($incoming) && ! empty($data['role'])) {
                $incoming = [[
                    'role' => $data['role'],
                ]];
            }

            $assignments = $this->normalizeIncomingAssignments($incoming, $user, true);
            $this->authorizationService->replaceAssignments($user, $assignments);
            $this->syncLegacySpatieRoles($user, $assignments);
            // During initial onboarding, invitation email already includes team context.
            // Suppress duplicate "assigned to team" email for the same user.
            $this->teamMemberSync->syncForUser($user, false, true);
        });

        $frontendUrl = config('app.frontend_url', config('app.url'));
        $user->notify(new UserInvitationNotification($frontendUrl));

        AuditLogger::log($request, 'user_created', $user, [
            'roles' => $this->authorizationService->getActiveRoleNames($user)->values()->all(),
        ]);

        return response()->json([
            'message' => 'User created and invitation sent',
            'user' => $this->formatUserPayload($user),
        ], 201);
    }

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'string', 'in:Active,Inactive'],
        ]);

        $user = User::withTrashed()->findOrFail($id);
        if ($user->trashed()) {
            return response()->json([
                'message' => 'Cannot change status of a deleted user.',
            ], 422);
        }
        $previousStatus = $user->status;
        $user->status = $request->input('status');
        $user->save();

        AuditLogger::log($request, 'user_status_changed', $user, [
            'from' => $previousStatus,
            'to' => $user->status,
        ]);

        if ($user->status === 'Inactive') {
            $this->revokeActiveSessions($request, $user, 'status_inactive');
        }

        return response()->json([
            'message' => 'Status updated.',
            'user' => [
                'id' => $user->id,
                'status' => $user->status,
            ],
        ]);
    }

    public function updateRole(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ]);

        $user = User::withTrashed()->findOrFail($id);
        if ($user->trashed()) {
            return response()->json([
                'message' => 'Cannot change role of a deleted user.',
            ], 422);
        }

        $previousRoles = $this->authorizationService->getActiveRoleNames($user)->values()->all();
        $assignments = $this->normalizeIncomingAssignments([
            ['role' => $data['role']],
        ], $user, true);

        $this->authorizationService->replaceAssignments($user, $assignments);
        $this->syncLegacySpatieRoles($user, $assignments);

        AuditLogger::log($request, 'user_role_changed', $user, [
            'from_roles' => $previousRoles,
            'to_roles' => $this->authorizationService->getActiveRoleNames($user)->values()->all(),
        ]);

        return response()->json([
            'message' => 'Role updated.',
            'user' => [
                'id' => $user->id,
                'roles' => $this->authorizationService->getActiveRoleNames($user)->values()->all(),
                'role_assignments' => $this->authorizationService->getRoleAssignmentsPayload($user),
            ],
        ]);
    }

    public function replaceRoleAssignments(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'role_assignments' => ['required', 'array', 'min:1'],
            'role_assignments.*.role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'role_assignments.*.scope_type' => ['nullable', Rule::in([RoleCatalog::GLOBAL, RoleCatalog::OFFICE, RoleCatalog::SITE, RoleCatalog::CLIENT_SITE])],
            'role_assignments.*.team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'role_assignments.*.start_date' => ['nullable', 'date'],
            'role_assignments.*.end_date' => ['nullable', 'date'],
            'role_assignments.*.is_primary' => ['nullable', 'boolean'],
        ]);

        $user = User::withTrashed()->findOrFail($id);
        if ($user->trashed()) {
            return response()->json([
                'message' => 'Cannot change role of a deleted user.',
            ], 422);
        }

        $previousRoles = $this->authorizationService->getActiveRoleNames($user)->values()->all();
        $assignments = $this->normalizeIncomingAssignments($data['role_assignments'], $user, false);

        $this->authorizationService->replaceAssignments($user, $assignments);
        $this->syncLegacySpatieRoles($user, $assignments);
        $this->teamMemberSync->syncForUser($user);

        AuditLogger::log($request, 'user_role_changed', $user, [
            'from_roles' => $previousRoles,
            'to_roles' => $this->authorizationService->getActiveRoleNames($user)->values()->all(),
        ]);

        return response()->json([
            'message' => 'Role assignments replaced.',
            'user' => [
                'id' => $user->id,
                'roles' => $this->authorizationService->getActiveRoleNames($user)->values()->all(),
                'role_assignments' => $this->authorizationService->getRoleAssignmentsPayload($user),
            ],
        ]);
    }

    public function addRoleAssignments(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'role_assignments' => ['required', 'array', 'min:1'],
            'role_assignments.*.role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'role_assignments.*.scope_type' => ['nullable', Rule::in([RoleCatalog::GLOBAL, RoleCatalog::OFFICE, RoleCatalog::SITE, RoleCatalog::CLIENT_SITE])],
            'role_assignments.*.team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'role_assignments.*.start_date' => ['nullable', 'date'],
            'role_assignments.*.end_date' => ['nullable', 'date'],
            'role_assignments.*.is_primary' => ['nullable', 'boolean'],
        ]);

        $user = User::withTrashed()->findOrFail($id);
        if ($user->trashed()) {
            return response()->json([
                'message' => 'Cannot change role of a deleted user.',
            ], 422);
        }

        $previousRoles = $this->authorizationService->getActiveRoleNames($user)->values()->all();
        $assignments = $this->normalizeIncomingAssignments($data['role_assignments'], $user, false);
        $this->authorizationService->addAssignments($user, $assignments);
        $this->syncLegacySpatieRoles($user, $this->activeAssignmentsForSync($user));
        $this->teamMemberSync->syncForUser($user);

        AuditLogger::log($request, 'user_role_changed', $user, [
            'from_roles' => $previousRoles,
            'to_roles' => $this->authorizationService->getActiveRoleNames($user)->values()->all(),
        ]);

        return response()->json([
            'message' => 'Role assignments added.',
            'user' => [
                'id' => $user->id,
                'roles' => $this->authorizationService->getActiveRoleNames($user)->values()->all(),
                'role_assignments' => $this->authorizationService->getRoleAssignmentsPayload($user),
            ],
        ]);
    }

    public function updateRoleAssignment(Request $request, int $id, int $assignmentId): JsonResponse
    {
        $data = $request->validate([
            'scope_type' => ['nullable', Rule::in([RoleCatalog::GLOBAL, RoleCatalog::OFFICE, RoleCatalog::SITE, RoleCatalog::CLIENT_SITE])],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $user = User::withTrashed()->findOrFail($id);
        if ($user->trashed()) {
            return response()->json([
                'message' => 'Cannot change role of a deleted user.',
            ], 422);
        }

        $assignment = UserRoleAssignment::query()->where('user_id', $user->id)->findOrFail($assignmentId);
        $roleName = $assignment->role?->name;
        $nextScope = $data['scope_type'] ?? $assignment->scope_type;
        $canonicalScope = RoleCatalog::scopeForRole($roleName);
        if ($canonicalScope !== $nextScope) {
            throw ValidationException::withMessages([
                'scope_type' => ["Role {$roleName} requires scope_type {$canonicalScope}."],
            ]);
        }

        $teamId = array_key_exists('team_id', $data) ? $data['team_id'] : $assignment->team_id;
        if (RoleCatalog::isScopedRole($roleName) && ! $teamId) {
            throw ValidationException::withMessages([
                'team_id' => ["Role {$roleName} requires a team_id scope."],
            ]);
        }
        if (! RoleCatalog::isScopedRole($roleName)) {
            $teamId = null;
        }

        $assignment->fill([
            'scope_type' => $nextScope,
            'team_id' => $teamId,
            'start_date' => $data['start_date'] ?? $assignment->start_date,
            'end_date' => $data['end_date'] ?? $assignment->end_date,
            'is_primary' => array_key_exists('is_primary', $data) ? (bool) $data['is_primary'] : $assignment->is_primary,
        ]);
        $assignment->save();

        $this->syncLegacySpatieRoles($user, $this->activeAssignmentsForSync($user));
        $this->teamMemberSync->syncForUser($user);

        return response()->json([
            'message' => 'Role assignment updated.',
            'user' => [
                'id' => $user->id,
                'roles' => $this->authorizationService->getActiveRoleNames($user)->values()->all(),
                'role_assignments' => $this->authorizationService->getRoleAssignmentsPayload($user),
            ],
        ]);
    }

    public function deleteRoleAssignment(Request $request, int $id, int $assignmentId): JsonResponse
    {
        $user = User::withTrashed()->findOrFail($id);
        if ($user->trashed()) {
            return response()->json([
                'message' => 'Cannot change role of a deleted user.',
            ], 422);
        }

        $assignment = UserRoleAssignment::query()->where('user_id', $user->id)->findOrFail($assignmentId);
        if ($assignment->start_date && $assignment->start_date->isFuture()) {
            $assignment->delete();
        } else {
            $assignment->end_date = now()->toDateString();
            $assignment->save();
        }

        $this->syncLegacySpatieRoles($user, $this->activeAssignmentsForSync($user));
        $this->teamMemberSync->syncForUser($user);

        return response()->json([
            'message' => 'Role assignment removed.',
            'user' => [
                'id' => $user->id,
                'roles' => $this->authorizationService->getActiveRoleNames($user)->values()->all(),
                'role_assignments' => $this->authorizationService->getRoleAssignmentsPayload($user),
            ],
        ]);
    }

    public function sendResetLink(Request $request, int $id): JsonResponse
    {
        $user = User::withTrashed()->findOrFail($id);
        if ($user->trashed()) {
            return response()->json([
                'message' => 'Cannot reset password for a deleted user.',
            ], 422);
        }
        $admin = $request->user();
        $status = Password::broker()->sendResetLink(['email' => $user->email], function ($notifiable, $token) use ($admin) {
            $notifiable->notify(new AdminResetPasswordNotification(
                $token,
                $admin?->name,
                $admin?->email,
            ));
        });

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        AuditLogger::log($request, 'password_reset_sent', $user, [
            'method' => 'admin',
        ]);

        return response()->json(['message' => __($status)]);
    }

    public function lock(Request $request, int $id): JsonResponse
    {
        $user = User::withTrashed()->findOrFail($id);
        if ($user->trashed()) {
            return response()->json([
                'message' => 'Cannot lock a deleted user.',
            ], 422);
        }
        if ($request->user() && $request->user()->id === $user->id) {
            return response()->json([
                'message' => 'You cannot lock your own account.',
            ], 422);
        }

        if (! $user->locked_at) {
            $user->forceFill([
                'locked_at' => now(),
                'locked_by' => $request->user()?->id,
                'lock_reason' => $request->input('reason'),
            ])->save();

            $this->revokeActiveSessions($request, $user, $request->input('reason') ?: 'admin_lock');

            AuditLogger::log($request, 'user_locked', $user, [
                'reason' => $request->input('reason'),
            ]);
        }

        return response()->json([
            'message' => 'User locked.',
            'user' => [
                'id' => $user->id,
                'locked_at' => optional($user->locked_at)->toIso8601String(),
                'lock_reason' => $user->lock_reason,
                'failed_login_count' => (int) ($user->failed_login_count ?? 0),
            ],
        ]);
    }

    public function unlock(Request $request, int $id): JsonResponse
    {
        $user = User::withTrashed()->findOrFail($id);
        if ($user->trashed()) {
            return response()->json([
                'message' => 'Cannot unlock a deleted user.',
            ], 422);
        }

        $user->forceFill([
            'locked_at' => null,
            'locked_by' => null,
            'lock_reason' => null,
            'failed_login_count' => 0,
        ])->save();

        AuditLogger::log($request, 'user_unlocked', $user);

        return response()->json([
            'message' => 'User unlocked.',
            'user' => [
                'id' => $user->id,
                'locked_at' => null,
                'lock_reason' => null,
                'failed_login_count' => 0,
            ],
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::withTrashed()->findOrFail($id);
        $force = $request->boolean('force');
        if ($request->user() && $request->user()->id === $user->id) {
            return response()->json([
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        if ($force) {
            if (! $user->trashed()) {
                return response()->json([
                    'message' => 'User must be deleted before permanent delete.',
                ], 422);
            }

            AuditLogger::log($request, 'user_permanently_deleted', $user);
            $user->forceDelete();

            return response()->json(['message' => 'User permanently deleted.']);
        }

        if ($user->trashed()) {
            return response()->json(['message' => 'User already deleted.'], 200);
        }

        AuditLogger::log($request, 'user_deleted', $user);
        $user->delete();
        $this->revokeActiveSessions($request, $user, 'terminated');

        return response()->json(['message' => 'User deleted.']);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $user = User::withTrashed()->findOrFail($id);
        if (! $user->trashed()) {
            return response()->json(['message' => 'User is already active.'], 200);
        }

        $user->restore();
        AuditLogger::log($request, 'user_restored', $user);

        return response()->json([
            'message' => 'User restored.',
            'user' => [
                'id' => $user->id,
                'status' => $user->status,
                'deleted_at' => null,
                'roles' => $this->authorizationService->getActiveRoleNames($user)->values()->all(),
                'role_assignments' => $this->authorizationService->getRoleAssignmentsPayload($user),
            ],
        ]);
    }

    private function normalizeIncomingAssignments(array $incoming, User $user, bool $legacyMode): array
    {
        $rows = [];
        foreach ($incoming as $index => $row) {
            $roleName = trim((string) ($row['role'] ?? ''));
            if ($roleName === 'Client') {
                $roleName = 'Representative';
            }
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if (! $role) {
                throw ValidationException::withMessages([
                    "role_assignments.{$index}.role" => ['Invalid role.'],
                ]);
            }

            $canonicalScope = RoleCatalog::scopeForRole($roleName);
            $scopeType = $row['scope_type'] ?? $canonicalScope;
            if ($scopeType !== $canonicalScope) {
                throw ValidationException::withMessages([
                    "role_assignments.{$index}.scope_type" => ["Role {$roleName} requires scope_type {$canonicalScope}."],
                ]);
            }

            $teamId = $row['team_id'] ?? null;
            if ($teamId && ! RoleCatalog::isScopedRole($roleName)) {
                // Non-scoped roles don't use team context
                $teamId = null;
            }
            if (! $teamId && RoleCatalog::isScopedRole($roleName) && $legacyMode) {
                // Try to resolve from existing team membership (legacy import path only)
                $teamId = $this->resolveLegacyTeamId($user);
            }
            // team_id is now optional for scoped roles — team assignment happens operationally

            $rows[] = [
                'role_id' => $role->id,
                'scope_type' => $scopeType,
                'team_id' => $teamId,
                'start_date' => $row['start_date'] ?? now()->toDateString(),
                'end_date' => $row['end_date'] ?? null,
                'is_primary' => (bool) ($row['is_primary'] ?? false),
            ];
        }

        if (! collect($rows)->contains(fn (array $row) => $row['is_primary'])) {
            $rows[0]['is_primary'] = true;
        }

        return $rows;
    }

    private function resolveLegacyTeamId(User $user): ?int
    {
        $activeTeamId = TeamMember::query()
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->value('team_id');
        if ($activeTeamId) {
            return (int) $activeTeamId;
        }

        $teamName = trim((string) $user->team);
        if ($teamName === '') {
            return null;
        }

        $teamId = Team::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($teamName)])->value('id');
        return $teamId ? (int) $teamId : null;
    }

    private function syncLegacySpatieRoles(User $user, array $assignments): void
    {
        $roleIds = collect($assignments)->pluck('role_id')->filter()->unique()->values()->all();
        if (empty($roleIds)) {
            $user->syncRoles([]);
            return;
        }

        $roleNames = Role::query()->whereIn('id', $roleIds)->pluck('name')->values()->all();
        $user->syncRoles($roleNames);
    }

    private function activeAssignmentsForSync(User $user): array
    {
        $today = now()->toDateString();

        return $user->roleAssignments()
            ->where(function ($query) use ($today) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today);
            })
            ->where(function ($query) use ($today) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            })
            ->get()
            ->map(function (UserRoleAssignment $row) {
                return [
                    'role_id' => $row->role_id,
                    'scope_type' => $row->scope_type,
                    'team_id' => $row->team_id,
                    'start_date' => optional($row->start_date)->toDateString(),
                    'end_date' => optional($row->end_date)->toDateString(),
                    'is_primary' => $row->is_primary,
                ];
            })
            ->all();
    }

    private function formatUserPayload(User $user, $teamMap = null, bool $includeSensitiveManagementFields = true): array
    {
        $teamEntry = $teamMap?->get($user->id)?->first();
        $teamName = $teamEntry?->team?->name ?? $user->team;
        $teamStatus = $teamEntry?->team?->status ?? null;

        $payload = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'state' => MalaysiaStateCatalog::normalize($user->state),
            'profile_image_url' => $this->resolveProfileImageUrl($user->profile_image_url),
            'team' => $teamName,
            'team_status' => $teamStatus,
            'status' => $user->status,
            'created_at' => optional($user->created_at)->toIso8601String(),
            'deleted_at' => optional($user->deleted_at)->toIso8601String(),
            'roles' => $this->authorizationService->getActiveRoleNames($user)->values()->all(),
        ];

        if (! $includeSensitiveManagementFields) {
            return $payload;
        }

        $lastLogin = $user->last_login_at;
        if (! $lastLogin) {
            $lastLogin = UserSession::where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->value('created_at');
        }
        $loginRecords = LoginAttempt::where('user_id', $user->id)
            ->latest()
            ->limit(10)
            ->get(['created_at as timestamp', 'status', 'reason', 'ip_address', 'user_agent', 'device_id', 'device_info', 'client_mode'])
            ->map(function ($record) {
                return [
                    'timestamp' => $record->timestamp,
                    'status' => $record->status,
                    'reason' => $record->reason,
                    'ip_address' => $record->ip_address,
                    'user_agent' => $record->user_agent,
                    'device_id' => $record->device_id,
                    'device_info' => $record->device_info,
                    'client_mode' => $record->client_mode,
                ];
            });

        return array_merge($payload, [
            'ic_number' => $user->ic_number,
            'address' => $user->address,
            'last_login_at' => $lastLogin,
            'failed_login_count' => (int) ($user->failed_login_count ?? 0),
            'locked_at' => optional($user->locked_at)->toIso8601String(),
            'lock_reason' => $user->lock_reason,
            'login_records' => $loginRecords,
            'emergency_contact' => $user->emergency_contact,
            'banking_info' => $user->banking_info,
            'statutory_info' => $user->statutory_info,
            'medical_info' => $user->medical_info,
            'permissions' => $this->authorizationService->getActivePermissionNames($user)->values()->all(),
            'role_assignments' => $this->authorizationService->getRoleAssignmentsPayload($user),
        ]);
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
        return Storage::disk(config('filesystems.public_uploads_disk', 'public'))->url($raw);
    }

    private function revokeActiveSessions(Request $request, User $user, string $reason): void
    {
        $updated = UserSession::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->whereNull('logged_out_at')
            ->update([
                'logged_out_at' => now(),
                'revoked_at' => now(),
                'revoked_by' => $request->user()?->id,
                'revoke_reason' => $reason,
                'remember_token_hash' => null,
                'remember_expires_at' => null,
            ]);

        AuditLogger::log($request, 'user_sessions_revoked_all', $user, [
            'count' => $updated,
            'reason' => $reason,
        ]);
    }
}
