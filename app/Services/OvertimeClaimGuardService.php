<?php

namespace App\Services;

use App\Models\OvertimeRecord;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

class OvertimeClaimGuardService
{
    public const MAX_DURATION_MINUTES = 960;

    public function validateWindow(array $data, int $userId, ?int $excludingRecordId = null): void
    {
        $claimDate = CarbonImmutable::parse($data['claim_date'])->startOfDay();
        if ($claimDate->isAfter(CarbonImmutable::today())) {
            throw ValidationException::withMessages([
                'claim_date' => ['Overtime claims cannot be dated in the future.'],
            ]);
        }

        $startAt = CarbonImmutable::parse($claimDate->toDateString() . ' ' . $data['start_time']);
        $endAt = CarbonImmutable::parse($claimDate->toDateString() . ' ' . $data['end_time']);
        $isOvernight = (bool) ($data['is_overnight'] ?? false);
        $requiresOvernight = $endAt->lessThanOrEqualTo($startAt);

        if ($requiresOvernight !== $isOvernight) {
            throw ValidationException::withMessages([
                'is_overnight' => ['Confirm whether this overtime window ends on the following day.'],
            ]);
        }
        if ($isOvernight) {
            $endAt = $endAt->addDay();
        }

        $duration = (int) (($endAt->getTimestamp() - $startAt->getTimestamp()) / 60);
        if ($duration <= 0 || $duration > self::MAX_DURATION_MINUTES) {
            throw ValidationException::withMessages([
                'duration_minutes' => ['Overtime duration must be between 1 minute and 16 hours.'],
            ]);
        }
        if ((int) $data['duration_minutes'] !== $duration) {
            throw ValidationException::withMessages([
                'duration_minutes' => ['Overtime duration does not match the selected time window.'],
            ]);
        }

        $candidates = OvertimeRecord::query()
            ->where('user_id', $userId)
            ->whereNotIn('status', ['Cancelled', 'Rejected'])
            ->whereBetween('claim_date', [
                $claimDate->subDay()->toDateString(),
                $claimDate->addDay()->toDateString(),
            ])
            ->when($excludingRecordId, fn ($query) => $query->whereKeyNot($excludingRecordId))
            ->get(['id', 'claim_date', 'start_time', 'end_time', 'is_overnight']);

        foreach ($candidates as $record) {
            if (! $record->claim_date || ! $record->start_time || ! $record->end_time) {
                continue;
            }
            $recordStart = CarbonImmutable::parse($record->claim_date->toDateString() . ' ' . $record->start_time);
            $recordEnd = CarbonImmutable::parse($record->claim_date->toDateString() . ' ' . $record->end_time);
            if ((bool) $record->is_overnight) {
                $recordEnd = $recordEnd->addDay();
            }
            if ($startAt->lessThan($recordEnd) && $endAt->greaterThan($recordStart)) {
                throw new HttpResponseException(response()->json([
                    'code' => 'OT_WINDOW_CONFLICT',
                    'message' => 'This overtime window overlaps an existing active overtime claim.',
                    'conflictingRecordId' => $record->id,
                ], 409));
            }
        }
    }
}
