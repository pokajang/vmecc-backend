<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;

class WorkingDayCalculator
{
    public function __construct(
        private readonly HolidayResolver $holidayResolver,
    ) {
    }

    public function computeLeaveDays(
        User $user,
        string $startDate,
        string $endDate,
        ?string $startTimeSlot = null,
        ?string $endTimeSlot = null,
    ): float {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();
        if ($end->lt($start)) {
            return 0.0;
        }

        $holidayDates = $this->holidayResolver
            ->getApplicableHolidayDatesForUser($user, $start->toDateString(), $end->toDateString())
            ->flip();

        $businessDays = 0;
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $isoDate = $cursor->toDateString();
            if (!$cursor->isWeekend() && !$holidayDates->has($isoDate)) {
                $businessDays++;
            }
            $cursor->addDay();
        }

        if ($businessDays <= 0) {
            return 0.0;
        }

        $startUnits = $this->resolveStartBoundaryUnits($startTimeSlot);
        $endUnits = $this->resolveEndBoundaryUnits($endTimeSlot);

        if ($businessDays === 1 && $startUnits === 0.5 && $endUnits === 0.5) {
            return 0.0;
        }
        if ($businessDays === 1) {
            return ($startUnits === 1.0 && $endUnits === 1.0) ? 1.0 : 0.5;
        }

        $total = (float) $businessDays;
        $total -= 1 - $startUnits;
        $total -= 1 - $endUnits;
        if ($total < 0) {
            return 0.0;
        }

        return round($total, 1);
    }

    private function resolveStartBoundaryUnits(?string $slot): float
    {
        return $slot === 'midpoint' ? 0.5 : 1.0;
    }

    private function resolveEndBoundaryUnits(?string $slot): float
    {
        return $slot === 'midpoint' ? 0.5 : 1.0;
    }
}

