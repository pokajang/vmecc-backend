<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\User;
use App\Support\MalaysiaStateCatalog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class HolidayResolver
{
    public function resolveEmployeeState(?User $user): ?string
    {
        return MalaysiaStateCatalog::normalize($user?->state);
    }

    public function getApplicableHolidayDatesForUser(
        User $user,
        string $startDate,
        ?string $endDate = null,
    ): Collection {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate ?: $startDate)->endOfDay();
        if ($end->lt($start)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        $state = $this->resolveEmployeeState($user);

        $rows = Holiday::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where(function ($query) use ($state) {
                $query->where('scope', 'National')
                    ->orWhere(function ($stateScoped) use ($state) {
                        $stateScoped->where('scope', 'State');
                        if ($state) {
                            $stateScoped->where(function ($stateFilter) use ($state) {
                                $stateFilter->whereRaw('LOWER(state) = ?', [strtolower($state)])
                                    ->orWhereRaw('LOWER(state) = ?', ['all states']);
                            });
                        } else {
                            // If employee state is unknown, apply national holidays only.
                            $stateScoped->whereRaw('1 = 0');
                        }
                    });
            })
            ->pluck('date');

        return $rows
            ->map(fn ($value) => Carbon::parse((string) $value)->toDateString())
            ->unique()
            ->values();
    }

    public function isPublicHolidayForUser(User $user, string $date): bool
    {
        return $this->getApplicableHolidayDatesForUser($user, $date)->contains($date);
    }
}
