<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;

class OvertimeDateClassifier
{
    public function __construct(
        private readonly HolidayResolver $holidayResolver,
    ) {
    }

    public function classify(User $user, string $claimDate): string
    {
        $date = Carbon::parse($claimDate)->toDateString();

        if ($this->holidayResolver->isPublicHolidayForUser($user, $date)) {
            return 'publicHoliday';
        }

        return Carbon::parse($date)->isWeekend() ? 'weekend' : 'weekday';
    }
}

