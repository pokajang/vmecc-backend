<?php

namespace App\Services;

use App\Models\InspectionSession;
use App\Models\InspectionSessionScopeClaim;
use App\Models\Roster;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InspectionSessionResolverService
{
    public function __construct(
        private readonly string $inspectionType = 'Fire Extinguisher Inspection',
        private readonly string $inspectionTypeKey = 'fire-extinguisher-inspection',
    ) {}

    public function resolveScope(array $scope, ?User $user = null): array
    {
        $scopeVersion = strtolower($this->text($scope['scopeVersion'] ?? 'legacy'));
        if ($scopeVersion !== 'v2') {
            return [
                'inspectionType' => $this->inspectionType,
                'scopeVersion' => 'legacy',
                'scopeKey' => null,
                'zone' => '',
                'mainLocation' => '',
                'subLocation' => '',
            ];
        }
        if (! config('inspection.session_scope_v2_enabled', false)) {
            throw ValidationException::withMessages([
                'scopeVersion' => ['Versioned inspection session scope is not enabled.'],
            ]);
        }

        if (! $user) {
            throw ValidationException::withMessages([
                'scopeVersion' => ['An authenticated user is required for V2 session scope.'],
            ]);
        }
        $inspectionDate = $this->text($scope['inspectionDate'] ?? '');
        $teamId = $this->activeTeamId($user, $inspectionDate, (int) ($scope['teamId'] ?? 0));
        $shiftKey = $this->activeShiftKey(
            $inspectionDate,
            $teamId,
            $this->slug($scope['shiftKey'] ?? ''),
        );
        $dimensions = [
            'siteKey' => $this->slug(config('inspection.site_key', 'vmecc')),
            'inspectionDate' => $inspectionDate,
            'shiftKey' => $shiftKey,
            'batchKey' => $this->slug($scope['batchKey'] ?? '') ?: 'team-'.$teamId,
            'teamId' => $teamId,
        ];
        if ($dimensions['siteKey'] === '' || $dimensions['inspectionDate'] === '' || $dimensions['teamId'] <= 0) {
            throw ValidationException::withMessages([
                'scopeVersion' => ['V2 scope requires a site, inspection date, and active team assignment.'],
            ]);
        }

        return [
            'inspectionType' => $this->inspectionType,
            'scopeVersion' => 'v2',
            'scopeKey' => 'v2:'.hash('sha256', json_encode($dimensions, JSON_UNESCAPED_SLASHES)),
            ...$dimensions,
            'zone' => '',
            'mainLocation' => '',
            'subLocation' => '',
        ];
    }

    public function findActive(array $scope, ?User $user = null): ?InspectionSession
    {
        $resolved = array_key_exists('scopeKey', $scope) && isset($scope['scopeVersion'])
            ? $scope
            : $this->resolveScope($scope, $user);
        $query = InspectionSession::query()
            ->where('inspection_type_key', $this->inspectionTypeKey)
            ->where('status', 'active');
        if ($resolved['scopeVersion'] === 'v2') {
            $query->where('scope_version', 'v2')->where('scope_key', $resolved['scopeKey']);
        } else {
            $query->where(function ($legacy): void {
                $legacy->whereNull('scope_version')->orWhere('scope_version', 'legacy');
            })->where('scope_zone', '')->where('scope_main_location', '');
        }

        return $query->orderByDesc('updated_at')->first();
    }

    public function create(array $scope, int $userId, ?array $dutyContext = null): InspectionSession
    {
        return DB::transaction(function () use ($scope, $userId, $dutyContext): InspectionSession {
            $session = InspectionSession::query()->create([
                'session_uid' => 'inspection-session-'.Str::uuid()->toString(),
                'inspection_type' => $this->inspectionType,
                'inspection_type_key' => $this->inspectionTypeKey,
                'status' => 'active',
                'scope_version' => $scope['scopeVersion'],
                'scope_key' => $scope['scopeKey'],
                'scope_zone' => $scope['zone'],
                'scope_main_location' => $scope['mainLocation'],
                'scope' => $scope,
                'started_by_user_id' => $userId,
                'duty_context_status' => $dutyContext['status'] ?? null,
                'duty_context_version' => $dutyContext['contextVersion'] ?? null,
                'duty_source_version' => $dutyContext['sourceVersion'] ?? null,
                'duty_context_snapshot' => $dutyContext,
            ]);
            if ($scope['scopeVersion'] === 'v2') {
                InspectionSessionScopeClaim::query()->create([
                    'scope_key' => $scope['scopeKey'],
                    'inspection_session_id' => $session->id,
                ]);
            }

            return $session;
        });
    }

    public function logOutcome(InspectionSession $session, string $outcome): void
    {
        Log::info('inspection_session_resolver_outcome', [
            'session_uid' => $session->session_uid,
            'scope_version' => $session->scope_version ?: 'legacy',
            'scope_key_hash' => $session->scope_key ? hash('sha256', $session->scope_key) : null,
            'outcome' => $outcome,
        ]);
    }

    public function userBelongsToScope(User $user, InspectionSession $session): bool
    {
        if (($session->scope_version ?: 'legacy') !== 'v2') {
            return true;
        }
        $teamId = (int) data_get($session->scope, 'teamId', 0);
        $inspectionDate = $this->text(data_get($session->scope, 'inspectionDate', '')) ?: now()->toDateString();

        return $teamId > 0 && $this->hasActiveTeamMembership($user, $teamId, $inspectionDate);
    }

    private function activeTeamId(User $user, string $inspectionDate, int $requestedTeamId): int
    {
        $query = TeamMember::query()
            ->where('user_id', $user->id)
            ->where(function ($membership) use ($inspectionDate): void {
                $membership->whereNull('started_at')->orWhereDate('started_at', '<=', $inspectionDate);
            })
            ->where(function ($membership) use ($inspectionDate): void {
                $membership->whereNull('ended_at')->orWhereDate('ended_at', '>=', $inspectionDate);
            });
        if ($requestedTeamId > 0) {
            $query->where('team_id', $requestedTeamId);
        }
        $teamId = (int) ($query->orderByDesc('is_primary')->orderByDesc('id')->value('team_id') ?? 0);
        if ($teamId <= 0) {
            throw ValidationException::withMessages([
                'teamId' => ['An active team assignment is required for this inspection date.'],
            ]);
        }

        return $teamId;
    }

    private function activeShiftKey(string $inspectionDate, int $teamId, string $requestedShift): string
    {
        $query = Roster::query()->whereDate('date', $inspectionDate)->where('team_id', $teamId);
        if ($requestedShift !== '') {
            $query->whereRaw('LOWER(TRIM(shift)) = ?', [$requestedShift]);
        }
        $shift = $this->slug($query->orderBy('shift')->value('shift') ?? '');

        return $shift !== '' ? $shift : ($requestedShift ?: 'unrostered');
    }

    private function hasActiveTeamMembership(User $user, int $teamId, string $date): bool
    {
        return TeamMember::query()
            ->where('user_id', $user->id)
            ->where('team_id', $teamId)
            ->where(function ($membership) use ($date): void {
                $membership->whereNull('started_at')->orWhereDate('started_at', '<=', $date);
            })
            ->where(function ($membership) use ($date): void {
                $membership->whereNull('ended_at')->orWhereDate('ended_at', '>=', $date);
            })
            ->exists();
    }

    private function text(mixed $value): string
    {
        return Str::of((string) $value)->squish()->toString();
    }

    private function slug(mixed $value): string
    {
        return Str::slug(Str::of((string) $value)->squish()->lower()->toString());
    }
}
