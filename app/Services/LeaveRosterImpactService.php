<?php

namespace App\Services;

use App\Models\CustomShift;
use App\Models\Leave;
use App\Models\Roster;
use App\Models\Setting;
use App\Models\TeamMember;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LeaveRosterImpactService
{
    private ?array $shiftWindows = null;

    private ?array $customShiftWindows = null;

    private const DEFAULT_WINDOWS = [
        'normal_start' => '08:00',
        'normal_end' => '17:00',
        'day_start' => '07:00',
        'day_end' => '19:00',
        'night_start' => '19:00',
        'night_end' => '07:00',
    ];

    public function forLeave(User $user, array|Leave $leave, bool $publishedOnly = true): array
    {
        $range = $this->leaveRange($leave);
        if (! $range) {
            return $this->emptyImpact();
        }

        $memberships = TeamMember::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($range) {
                $query->whereNull('started_at')->orWhereDate('started_at', '<=', $range['end']->toDateString());
            })
            ->where(function ($query) use ($range) {
                $query->whereNull('ended_at')->orWhereDate('ended_at', '>=', $range['start']->toDateString());
            })
            ->get();
        $teamIds = $memberships->pluck('team_id')->unique()->values();

        if ($teamIds->isEmpty()) {
            return $this->emptyImpact();
        }

        $rosters = Roster::query()
            ->with('team:id,name')
            ->whereIn('team_id', $teamIds)
            ->whereBetween('date', [$range['start']->copy()->subDay()->toDateString(), $range['end']->toDateString()])
            ->when($publishedOnly, fn ($query) => $query->where('status', 'published'))
            ->get();

        $items = $rosters
            ->filter(function (Roster $roster) use ($memberships, $range) {
                return $memberships->where('team_id', $roster->team_id)
                    ->contains(fn (TeamMember $membership) => $this->membershipIsActiveOn($membership, $roster->date->toDateString()))
                    && $this->intervalsOverlap($range, $this->rosterRange($roster));
            })
            ->map(fn (Roster $roster) => $this->formatImpactItem($roster, $range))
            ->sortBy(fn (array $item) => $item['date'].':'.$item['shift'])
            ->values()
            ->all();

        return $this->impactFromItems($items, $publishedOnly ? 'published_roster' : 'roster');
    }

    public function snapshotForLeave(User $user, array|Leave $leave): array
    {
        $impact = $this->forLeave($user, $leave, true);

        return [
            'observed_at' => now()->toIso8601String(),
            'source' => 'published_roster',
            'items' => $impact['items'],
            'summary' => $impact['summary'],
        ];
    }

    public function markersForRosters(Collection $rosters, bool $includePeople): array
    {
        if ($rosters->isEmpty()) {
            return [];
        }

        $minDate = $rosters->min(fn (Roster $roster) => $roster->date->toDateString());
        $maxDate = $rosters->max(fn (Roster $roster) => $roster->date->toDateString());
        $teamIds = $rosters->pluck('team_id')->unique()->values();
        $memberships = TeamMember::query()
            ->whereIn('team_id', $teamIds)
            ->where(function ($query) use ($maxDate) {
                $query->whereNull('started_at')->orWhereDate('started_at', '<=', $maxDate);
            })
            ->where(function ($query) use ($minDate) {
                $query->whereNull('ended_at')->orWhereDate('ended_at', '>=', $minDate);
            })
            ->get()
            ->groupBy('team_id');
        $memberUserIds = $memberships->flatten(1)->pluck('user_id')->unique()->values();
        $leavesByUser = Leave::query()
            ->with('user:id,name')
            ->whereIn('user_id', $memberUserIds)
            ->whereIn('status', ['Pending', 'Approved'])
            ->whereDate('start_date', '<=', $maxDate)
            ->whereDate('end_date', '>=', Carbon::parse($minDate)->subDay()->toDateString())
            ->get()
            ->groupBy('user_id');

        return $rosters->mapWithKeys(function (Roster $roster) use ($leavesByUser, $memberships, $includePeople) {
            $people = [];
            foreach ($memberships->get($roster->team_id, collect()) as $membership) {
                if (! $this->membershipIsActiveOn($membership, $roster->date->toDateString())) {
                    continue;
                }
                foreach ($leavesByUser->get($membership->user_id, collect()) as $leave) {
                    $range = $this->leaveRange($leave);
                    if (! $range || ! $this->intervalsOverlap($range, $this->rosterRange($roster))) {
                        continue;
                    }
                    $people[$leave->user_id] = [
                        'user_id' => (int) $leave->user_id,
                        'name' => (string) ($leave->user?->name ?? 'Staff member'),
                        'state' => $leave->status === 'Approved' ? 'approved' : 'requested',
                    ];
                }
            }
            $people = array_values($people);
            $requested = count(array_filter($people, fn (array $person) => $person['state'] === 'requested'));
            $approved = count(array_filter($people, fn (array $person) => $person['state'] === 'approved'));

            return [$roster->id => [
                'requested_count' => $requested,
                'approved_count' => $approved,
                'people' => $includePeople ? $people : [],
            ]];
        })->all();
    }

    private function leaveRange(array|Leave $leave): ?array
    {
        $startDate = data_get($leave, 'start_date');
        $endDate = data_get($leave, 'end_date');
        if (! $startDate || ! $endDate) {
            return null;
        }
        $startDate = Carbon::parse($startDate)->toDateString();
        $endDate = Carbon::parse($endDate)->toDateString();
        $workShift = match ((string) data_get($leave, 'work_shift', 'normal')) {
            'day' => 'day12',
            'night' => 'night12',
            default => (string) data_get($leave, 'work_shift', 'normal'),
        };
        $slots = [
            'normal' => ['start' => '08:30', 'midpoint' => '13:00', 'end' => '17:30', 'overnight' => false],
            'day12' => ['start' => '07:00', 'midpoint' => '13:00', 'end' => '19:00', 'overnight' => false],
            'night12' => ['start' => '19:00', 'midpoint' => '01:00', 'end' => '07:00', 'overnight' => true],
        ];
        $config = $slots[$workShift] ?? $slots['normal'];
        $startTime = data_get($leave, 'start_time_slot') === 'midpoint' ? $config['midpoint'] : $config['start'];
        $start = Carbon::parse($startDate.' '.$startTime);
        $endTime = data_get($leave, 'end_time_slot') === 'midpoint' ? $config['midpoint'] : $config['end'];
        $end = Carbon::parse($endDate.' '.$endTime);
        if ($config['overnight'] || $end->lte($start)) {
            $end->addDay();
        }

        return ['start' => $start, 'end' => $end];
    }

    private function rosterRange(Roster $roster): array
    {
        [$startTime, $endTime] = $this->shiftWindow((string) $roster->shift);
        $start = Carbon::parse($roster->date->toDateString().' '.$startTime);
        $end = Carbon::parse($roster->date->toDateString().' '.$endTime);
        if ($end->lte($start)) {
            $end->addDay();
        }

        return ['start' => $start, 'end' => $end];
    }

    private function shiftWindow(string $shift): array
    {
        $windows = $this->shiftWindows ??= (Setting::query()->where('key', 'shift_windows')->first()?->value ?: self::DEFAULT_WINDOWS);
        if ($shift === 'day') {
            return [$windows['day_start'] ?? '07:00', $windows['day_end'] ?? '19:00'];
        }
        if ($shift === 'night') {
            return [$windows['night_start'] ?? '19:00', $windows['night_end'] ?? '07:00'];
        }
        $customWindows = $this->customShiftWindows ??= CustomShift::query()
            ->get(['name', 'start', 'end'])
            ->mapWithKeys(fn (CustomShift $custom) => [$custom->name => [$custom->start, $custom->end]])
            ->all();

        return $customWindows[$shift] ?? ['00:00', '00:00'];
    }

    private function intervalsOverlap(array $left, array $right): bool
    {
        return $left['start']->lt($right['end']) && $left['end']->gt($right['start']);
    }

    private function membershipIsActiveOn(TeamMember $membership, string $date): bool
    {
        return (! $membership->started_at || $membership->started_at->lte($date))
            && (! $membership->ended_at || $membership->ended_at->gte($date));
    }

    private function formatImpactItem(Roster $roster, array $range): array
    {
        return [
            'date' => $roster->date->toDateString(),
            'shift' => $roster->shift,
            'shift_label' => ucfirst($roster->shift),
            'team_id' => (int) $roster->team_id,
            'team_name' => (string) ($roster->team?->name ?? ''),
            'roster_status' => $roster->status,
            'overlap' => $range['start']->lte($this->rosterRange($roster)['start']) && $range['end']->gte($this->rosterRange($roster)['end']) ? 'full' : 'partial',
        ];
    }

    private function impactFromItems(array $items, string $source): array
    {
        return ['source' => $source, 'items' => $items, 'summary' => [
            'duty_count' => count($items),
            'team_names' => array_values(array_unique(array_filter(array_column($items, 'team_name')))),
        ]];
    }

    private function emptyImpact(): array
    {
        return $this->impactFromItems([], 'published_roster');
    }
}
