<?php

namespace App\Services;

use App\Models\User;

class HolidayGuidanceFeatureGate
{
    public function leaveEnabledForUser(?User $user): bool
    {
        return $this->enabledForUser(
            (bool) config('features.holiday_guidance_leave_enabled', true),
            $user,
        );
    }

    public function overtimeEnabledForUser(?User $user): bool
    {
        return $this->enabledForUser(
            (bool) config('features.holiday_guidance_overtime_enabled', true),
            $user,
        );
    }

    public function staffVisibilityEnabledForUser(?User $user): bool
    {
        return $this->enabledForUser(
            (bool) config('features.holiday_guidance_staff_visibility_enabled', false),
            $user,
        );
    }

    private function enabledForUser(bool $baseFlagEnabled, ?User $user): bool
    {
        if (!$baseFlagEnabled) {
            return false;
        }

        $cohortUserIds = (array) config('features.holiday_guidance_cohort_user_ids', []);
        $cohortEmails = collect((array) config('features.holiday_guidance_cohort_emails', []))
            ->map(fn ($value) => mb_strtolower(trim((string) $value)))
            ->filter()
            ->values();

        if (empty($cohortUserIds) && $cohortEmails->isEmpty()) {
            return true;
        }

        if (!$user) {
            return false;
        }

        $userId = (int) ($user->id ?? 0);
        if ($userId > 0 && in_array($userId, $cohortUserIds, true)) {
            return true;
        }

        $userEmail = mb_strtolower(trim((string) ($user->email ?? '')));
        return $userEmail !== '' && $cohortEmails->contains($userEmail);
    }
}

