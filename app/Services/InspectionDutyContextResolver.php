<?php

namespace App\Services;

use App\Models\CustomShift;
use App\Models\Roster;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class InspectionDutyContextResolver
{
    public function resolve(User $user, ?Carbon $at = null): array
    {
        $timezone = (string) config('inspection_duty.site_timezone', 'Asia/Kuala_Lumpur');
        $localNow = ($at ?: now())->copy()->setTimezone($timezone);
        $dates = [$localNow->toDateString(), $localNow->copy()->subDay()->toDateString()];
        $memberships = TeamMember::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($dates): void {
                $query->whereNull('started_at')->orWhereDate('started_at', '<=', max($dates));
            })
            ->where(function ($query) use ($dates): void {
                $query->whereNull('ended_at')->orWhereDate('ended_at', '>=', min($dates));
            })
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->get()
            ->groupBy('team_id');

        $shiftWindows = $this->shiftWindows();
        $candidates = Roster::query()
            ->with('team:id,name')
            ->where('status', 'published')
            ->whereIn('date', $dates)
            ->whereIn('team_id', $memberships->keys())
            ->get()
            ->map(function (Roster $roster) use ($localNow, $shiftWindows, $memberships): ?array {
                $shiftKey = Str::slug(Str::lower(trim((string) $roster->shift)));
                $window = $shiftWindows[$shiftKey] ?? null;
                if (! $window) {
                    return null;
                }
                [$from, $until] = $this->effectiveWindow($roster->date->toDateString(), $window, $localNow->timezoneName);
                if ($localNow->lt($from) || ! $localNow->lt($until)) {
                    return null;
                }
                $rosterDate = $roster->date->toDateString();
                $membership = $memberships->get($roster->team_id)?->first(
                    fn (TeamMember $candidate) => (! $candidate->started_at || $candidate->started_at->toDateString() <= $rosterDate)
                        && (! $candidate->ended_at || $candidate->ended_at->toDateString() >= $rosterDate)
                );
                if (! $membership
                    || ($membership->started_at && $membership->started_at->toDateString() > $rosterDate)
                    || ($membership->ended_at && $membership->ended_at->toDateString() < $rosterDate)) {
                    return null;
                }

                return [
                    'rosterId' => (int) $roster->id,
                    'teamId' => (int) $roster->team_id,
                    'teamName' => (string) ($roster->team?->name ?? ''),
                    'shiftKey' => $shiftKey,
                    'effectiveFrom' => $from->toIso8601String(),
                    'effectiveUntil' => $until->toIso8601String(),
                    'rosterUpdatedAt' => optional($roster->updated_at)->toIso8601String(),
                    'membershipUpdatedAt' => optional($membership?->updated_at)->toIso8601String(),
                ];
            })
            ->filter()
            ->sortBy(fn (array $candidate) => [$candidate['effectiveFrom'], $candidate['teamId'], $candidate['shiftKey']])
            ->values()
            ->all();

        $status = count($candidates) === 1 ? 'assigned' : (count($candidates) > 1 ? 'ambiguous' : 'unmatched');
        $selected = $status === 'assigned' ? $candidates[0] : null;
        $sourcePayload = [
            'siteKey' => (string) config('inspection_duty.site_key', 'vmecc'),
            'timezone' => $timezone,
            'candidates' => $candidates,
            'shiftConfiguration' => $shiftWindows,
        ];
        $sourceHash = hash('sha256', json_encode($sourcePayload, JSON_UNESCAPED_SLASHES));
        $context = [
            'status' => $status,
            'confidence' => $status === 'assigned' ? 'high' : null,
            'siteKey' => $sourcePayload['siteKey'],
            'siteTimezone' => $timezone,
            'teamId' => $selected['teamId'] ?? null,
            'teamName' => $selected['teamName'] ?? null,
            'shiftKey' => $selected['shiftKey'] ?? null,
            'effectiveFrom' => $selected['effectiveFrom'] ?? null,
            'effectiveUntil' => $selected['effectiveUntil'] ?? null,
            'candidates' => $status === 'ambiguous' ? $candidates : [],
            'sourceVersion' => 'dsv1:'.substr($sourceHash, 0, 64),
        ];
        $context['contextVersion'] = 'dcv1:'.hash('sha256', json_encode($context, JSON_UNESCAPED_SLASHES));
        $context['allowedActions'] = [
            'draftSave' => true,
            'submit' => $status === 'assigned',
            'review' => $status === 'assigned',
            'approve' => $status === 'assigned',
            'reject' => $status === 'assigned',
        ];

        return $context;
    }

    private function shiftWindows(): array
    {
        $windows = (array) config('inspection_duty.built_in_shifts', []);
        CustomShift::query()->get(['name', 'start', 'end'])->each(function (CustomShift $shift) use (&$windows): void {
            $windows[Str::slug(Str::lower(trim($shift->name)))] = ['start' => $shift->start, 'end' => $shift->end];
        });

        return $windows;
    }

    private function effectiveWindow(string $date, array $window, string $timezone): array
    {
        $from = Carbon::parse($date.' '.$window['start'], $timezone);
        $until = Carbon::parse($date.' '.$window['end'], $timezone);
        if ($until->lte($from)) {
            $until->addDay();
        }

        return [$from, $until];
    }
}
