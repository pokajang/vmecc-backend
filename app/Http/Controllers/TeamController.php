<?php

namespace App\Http\Controllers;

use App\Models\DeletedTeam;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Notifications\TeamDisbandedNotification;
use App\Services\AuditLogger;
use App\Services\TeamMemberSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TeamController extends Controller
{
    public function __construct(
        private readonly TeamMemberSyncService $teamMemberSync,
        private readonly \App\Services\WorkflowNotificationService $workflowNotifications,
    ) {
    }

    private function teamPayload(Team $team, bool $withMembers = false): array
    {
        $payload = [
            'id'         => $team->id,
            'name'       => $team->name,
            'group'      => $team->group,
            'status'     => $team->status,
            'lead_name'  => $team->lead_name,
            'lead_id'    => $team->lead_id,
            'image_url'  => $team->image_url
                ? (str_starts_with($team->image_url, 'preset:')
                    ? $team->image_url
                    : Storage::disk($this->publicUploadsDisk())->url($team->image_url))
                : null,
            'created_at' => $team->created_at,
            'updated_at' => $team->updated_at,
        ];

        if ($withMembers) {
            $payload['members'] = $team->members
                ->filter(fn($m) => $m->ended_at === null)
                ->map(fn($m) => [
                    'id'         => $m->id,
                    'name'       => $m->name,
                    'role'       => $m->role,
                    'user_id'    => $m->user_id,
                    'is_primary' => (bool) $m->is_primary,
                    'started_at' => $m->started_at?->toDateString(),
                    'ended_at'   => $m->ended_at?->toDateString(),
                ])->values();

            $payload['past_members'] = $team->members
                ->filter(fn($m) => $m->ended_at !== null)
                ->sortByDesc('ended_at')
                ->take(50)
                ->map(fn($m) => [
                    'id'         => $m->id,
                    'name'       => $m->name,
                    'role'       => $m->role,
                    'user_id'    => $m->user_id,
                    'is_primary' => (bool) $m->is_primary,
                    'started_at' => $m->started_at?->toDateString(),
                    'ended_at'   => $m->ended_at?->toDateString(),
                ])->values();
        } else {
            $payload['members']      = [];
            $payload['past_members'] = [];
        }

        return $payload;
    }

    private function loadMembers(Team $team): void
    {
        $team->load(['members' => function ($query) {
            $query->orderByDesc('is_primary')->orderBy('name');
        }]);
    }

    /**
     * Return all teams with their members.
     */
    public function index(): JsonResponse
    {
        $teams = Team::with(['members' => function ($query) {
            $query->orderByDesc('is_primary')->orderBy('name');
        }])
            ->orderBy('name')
            ->get()
            ->map(fn(Team $team) => $this->teamPayload($team, true));

        return response()->json(['data' => $teams]);
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
            'name'   => 'required|string|max:255|unique:teams,name',
            'group'  => 'sometimes|nullable|string|max:100',
            'status' => 'sometimes|string|max:50',
        ]);

        $team = Team::create([
            'name'   => $data['name'],
            'group'  => $data['group'] ?? null,
            'status' => $data['status'] ?? config('team.default_status', 'On Duty'),
        ]);

        AuditLogger::log($request, 'team_created', null, [
            'team_id'   => $team->id,
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

        // Remove old uploaded image if present (presets are not stored on disk)
        if ($team->image_url && !str_starts_with($team->image_url, 'preset:')) {
            Storage::disk($disk)->delete($team->image_url);
        }

        $path = $request->file('image')->store('teams', ['disk' => $disk]);

        $team->update(['image_url' => $path]);

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
            'name'                  => 'required|string|max:255|unique:teams,name,' . $team->id,
            'group'                 => 'sometimes|nullable|string|max:100',
            'status'                => 'sometimes|nullable|string|max:50',
            'image_url'             => ['sometimes', 'nullable', 'string', 'max:500', 'regex:/^(preset:[a-z]+|teams\/.+)$/'],
            'members'               => 'array',
            'members.*.name'        => 'required|string|max:255',
            'members.*.role'        => 'nullable|string|max:255',
            'members.*.user_id'     => 'nullable|exists:users,id',
            'members.*.is_primary'  => 'boolean',
            'members.*.started_at'  => 'nullable|date',
        ]);

        // When a file was uploaded as part of the atomic multipart request, store it now
        // and treat it exactly like a regular image_url update for the rest of the method.
        if ($request->hasFile('image')) {
            $request->validate([
                'image' => ['file', 'image', 'max:4096', 'mimes:jpeg,png,webp,gif'],
            ]);
            $disk = $this->publicUploadsDisk();
            if ($team->image_url && !str_starts_with($team->image_url, 'preset:')) {
                Storage::disk($disk)->delete($team->image_url);
            }
            $data['image_url'] = $request->file('image')->store('teams', ['disk' => $disk]);
        }

        // Prevent assigning a user who is already an active member of a different team.
        if (!empty($data['members'])) {
            $incomingUserIds = collect($data['members'])->pluck('user_id')->filter()->unique()->values();
            $conflicts = \App\Models\TeamMember::query()
                ->whereIn('user_id', $incomingUserIds)
                ->where('team_id', '!=', $team->id)
                ->whereNull('ended_at')
                ->with('team:id,name')
                ->get();

            if ($conflicts->isNotEmpty()) {
                $messages = $conflicts->map(fn($m) => "{$m->name} is already an active member of {$m->team->name}")->join(', ');
                return response()->json([
                    'message' => 'One or more members are already assigned to another team.',
                    'errors'  => ['members' => [$messages]],
                ], 422);
            }
        }

        // image_url in update payload:
        //   null        → clear (delete uploaded file if any)
        //   "preset:x"  → store key as-is
        //   absent      → leave image_url unchanged
        $updateFields   = ['name' => $data['name'], 'group' => $data['group'] ?? $team->group];
        $oldImageToDelete = null;
        if (array_key_exists('image_url', $data)) {
            $newImageUrl = $data['image_url'];
            if ($newImageUrl === null && $team->image_url && !str_starts_with($team->image_url, 'preset:')) {
                $oldImageToDelete = $team->image_url; // delete after DB commit
            }
            $updateFields['image_url'] = $newImageUrl;
        }
        $newUserIds       = collect();
        $membersForLookup = [];

        DB::transaction(function () use ($team, $updateFields, $data, &$newUserIds, &$membersForLookup) {
            $team->update($updateFields);

            if (isset($data['members'])) {
                $incoming     = collect($data['members']);
                $existing     = $team->members()->get()->keyBy(fn($m) => $m->user_id ?? 'name:' . $m->name);
                $incomingKeys = $incoming->map(fn($m) => $m['user_id'] ?? ('name:' . $m['name']))->toArray();

                // Track which user_ids are genuinely new (not currently active members)
                $activeUserIds = $team->members()->whereNull('ended_at')->pluck('user_id')->filter()->values();
                $newUserIds    = collect($data['members'])
                    ->pluck('user_id')
                    ->filter()
                    ->diff($activeUserIds)
                    ->values();
                $membersForLookup = $data['members'];

                $team->members()
                    ->whereNull('ended_at')
                    ->get()
                    ->each(function ($member) use ($incomingKeys) {
                        $key = $member->user_id ?? 'name:' . $member->name;
                        if (!in_array($key, $incomingKeys, true)) {
                            $member->update(['ended_at' => now()]);
                        }
                    });

                $incoming->each(function ($member) use ($team, $existing) {
                    $key     = $member['user_id'] ?? ('name:' . $member['name']);
                    $current = $existing->get($key);
                    $team->members()->updateOrCreate(
                        [
                            'team_id' => $team->id,
                            'user_id' => $member['user_id'] ?? null,
                            'name'    => $member['name'],
                        ],
                        [
                            'role'       => $member['role'] ?? null,
                            'is_primary' => $member['is_primary'] ?? false,
                            'started_at' => $member['started_at'] ?? ($current?->started_at?->toDateString() ?? now()->toDateString()),
                            'ended_at'   => null,
                        ],
                    );
                });
            }
        });

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
                if (! $newMember) continue;
                $roleName = collect($membersForLookup)->firstWhere('user_id', $userId)['role'] ?? '';
                $this->teamMemberSync->fireNewMemberNotifications($newMember, $team->id, $roleName);
            }
        }

        $this->loadMembers($team);

        AuditLogger::log($request, 'team_updated', null, [
            'team_id'      => $team->id,
            'team_name'    => $team->name,
            'added_users'  => $newUserIds->values()->all(),
            'member_count' => $team->members->count(),
        ]);

        return response()->json(['data' => $this->teamPayload($team, true)]);
    }

    /**
     * Delete a team:
     * 1. Snapshot active members to deleted_teams for sysadmin audit.
     * 2. Null out team_id on related user_role_assignments (keep roles intact).
     * 3. Notify each active member with a user account.
     * 4. Delete uploaded cover image from storage.
     * 5. Delete the team (cascades team_members).
     * 6. Write an audit log entry.
     */
    public function destroy(Request $request, Team $team): JsonResponse
    {
        // Load active members before deletion
        $team->load(['members' => fn($q) => $q->whereNull('ended_at')]);

        $activeMembers   = $team->members;
        $imageToDelete   = ($team->image_url && !str_starts_with($team->image_url, 'preset:'))
            ? $team->image_url
            : null;

        // Steps 1–2 + 5 are DB operations — wrap in a transaction so the snapshot,
        // role-assignment nulling, and team deletion are all-or-nothing.
        DB::transaction(function () use ($team, $activeMembers, $request) {
            // 1. Snapshot active members for sysadmin audit trail
            DeletedTeam::create([
                'name'               => $team->name,
                'status'             => $team->status,
                'image_url'          => $team->image_url,
                'lead_id'            => $team->lead_id,
                'lead_name'          => $team->lead_name,
                'members_snapshot'   => $activeMembers->map(fn($m) => [
                    'user_id'    => $m->user_id,
                    'name'       => $m->name,
                    'role'       => $m->role,
                    'is_primary' => $m->is_primary,
                    'started_at' => $m->started_at?->toDateString(),
                ])->values()->toArray(),
                'deleted_by_user_id' => $request->user()?->id,
                'deleted_at'         => now(),
            ]);

            // 2. Null out team_id on role assignments so users keep their role
            UserRoleAssignment::where('team_id', $team->id)->update(['team_id' => null]);

            // 3. Delete team (cascades team_members)
            $team->delete();
        });

        // 4. Delete uploaded cover image after DB commit (skip preset tokens)
        if ($imageToDelete) {
            Storage::disk($this->publicUploadsDisk())->delete($imageToDelete);
        }

        // 5. Notify each member that has a linked user account — outside the transaction
        //    so a mail failure cannot roll back the deletion.
        $memberUserIds = $activeMembers->pluck('user_id')->filter()->unique()->values();
        if ($memberUserIds->isNotEmpty()) {
            $memberUsers = User::whereIn('id', $memberUserIds)->get()->keyBy('id');
            $teamEmailEnabled = config('mail.workflow_notifications.enabled', false)
                && (bool) config('mail.workflow_notifications.modules.team', false);
            if ($teamEmailEnabled) {
                $activeMembers->each(function ($member) use ($team, $memberUsers) {
                    if (! $member->user_id) return;
                    $user = $memberUsers->get($member->user_id);
                    if ($user) {
                        $user->notify(new TeamDisbandedNotification($team->name, $member->role ?? ''));
                    }
                });
            }

            // In-app notification fanned out to all affected members at once
            $actor = $request->user()
                ? ['userId' => $request->user()->id, 'name' => $request->user()->name, 'email' => $request->user()->email ?? '']
                : ['userId' => null, 'name' => 'System', 'email' => ''];

            $this->workflowNotifications->emit(
                module: 'team',
                eventType: 'team_disbanded',
                recordType: 'team',
                recordId: null,
                recordDisplayId: $team->name,
                ownerUserId: $memberUserIds->first(),
                actor: $actor,
                targetUserIds: $memberUserIds->all(),
                metadata: [
                    'teamName'    => $team->name,
                    'memberCount' => $activeMembers->count(),
                ],
            );
        }

        // 6. Audit log
        AuditLogger::log($request, 'team_deleted', null, [
            'team_name'      => $team->name,
            'member_count'   => $activeMembers->count(),
            'members'        => $activeMembers->map(fn($m) => [
                'name' => $m->name,
                'role' => $m->role,
            ])->values()->toArray(),
        ]);

        return response()->json(null, 204);
    }

    private function publicUploadsDisk(): string
    {
        return (string) config('filesystems.public_uploads_disk', 'public');
    }
}
