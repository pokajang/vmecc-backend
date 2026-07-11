<?php

namespace App\Services;

use App\Models\Leave;
use App\Models\LeaveAttachment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

class LeaveClaimGuardService
{
    private const WORK_SHIFTS = ['normal', 'day12', 'night12'];
    private const START_SLOTS = ['shift-start', 'midpoint'];
    private const END_SLOTS = ['midpoint', 'shift-end'];

    public function __construct(
        private readonly LeavePolicyService $policyService,
        private readonly WorkingDayCalculator $workingDayCalculator,
    ) {
    }

    /**
     * Returns a normalized payload whose days value is calculated by the server.
     */
    public function validate(array $data, User $user, ?Leave $existingLeave = null): array
    {
        $leaveType = trim((string) ($data['leave_type'] ?? ''));
        $this->policyService->assertSupported($leaveType);

        $start = CarbonImmutable::parse((string) $data['start_date'])->startOfDay();
        $end = CarbonImmutable::parse((string) $data['end_date'])->startOfDay();
        if ($end->lessThan($start)) {
            throw ValidationException::withMessages([
                'end_date' => ['End date must be on or after start date.'],
            ]);
        }
        if ($start->year !== $end->year) {
            throw ValidationException::withMessages([
                'end_date' => ['Leave requests cannot span entitlement years. Submit one request per year.'],
            ]);
        }

        $workShift = (string) ($data['work_shift'] ?? 'normal');
        $startSlot = (string) ($data['start_time_slot'] ?? 'shift-start');
        $endSlot = (string) ($data['end_time_slot'] ?? 'shift-end');
        if (! in_array($workShift, self::WORK_SHIFTS, true)
            || ! in_array($startSlot, self::START_SLOTS, true)
            || ! in_array($endSlot, self::END_SLOTS, true)) {
            throw ValidationException::withMessages([
                'schedule' => ['The selected leave schedule is invalid.'],
            ]);
        }

        $days = $this->workingDayCalculator->computeLeaveDays(
            $user,
            $start->toDateString(),
            $end->toDateString(),
            $startSlot,
            $endSlot,
        );
        if ($days <= 0) {
            throw ValidationException::withMessages([
                'schedule' => ['No working leave days were found in the selected date range.'],
            ]);
        }

        if ($this->policyService->requiresCoverage($leaveType, $days)
            && trim((string) ($data['cover_by'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'cover_by' => ['Coverage By is required for this leave request.'],
            ]);
        }

        $attachmentId = $data['attachment_id'] ?? null;
        if ($this->policyService->requiresEvidence($leaveType) && ! $attachmentId) {
            throw ValidationException::withMessages([
                'attachment_id' => ['Supporting evidence is required for this leave type.'],
            ]);
        }
        if ($attachmentId) {
            $attachment = LeaveAttachment::query()->find($attachmentId);
            $allowedLeaveId = $existingLeave?->id;
            if (! $attachment
                || (int) $attachment->user_id !== (int) $user->id
                || ($attachment->leave_id !== null && (int) $attachment->leave_id !== (int) $allowedLeaveId)) {
                throw ValidationException::withMessages([
                    'attachment_id' => ['The selected attachment is unavailable for this leave request.'],
                ]);
            }
        }

        $this->assertNoActiveOverlap(
            userId: (int) $user->id,
            start: $start,
            end: $end,
            startSlot: $startSlot,
            endSlot: $endSlot,
            excludingLeaveId: $existingLeave?->id,
        );

        $data['leave_type'] = $leaveType;
        $data['work_shift'] = $workShift;
        $data['start_time_slot'] = $startSlot;
        $data['end_time_slot'] = $endSlot;
        $data['days'] = $days;

        return $data;
    }

    private function assertNoActiveOverlap(
        int $userId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $startSlot,
        string $endSlot,
        ?int $excludingLeaveId,
    ): void {
        $requestedStart = $this->startBoundary($start, $startSlot);
        $requestedEnd = $this->endBoundary($end, $endSlot);

        $candidates = Leave::query()
            ->where('user_id', $userId)
            ->whereNotIn('status', ['Cancelled', 'Rejected'])
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->when($excludingLeaveId, fn ($query) => $query->whereKeyNot($excludingLeaveId))
            ->get(['id', 'display_id', 'start_date', 'end_date', 'start_time_slot', 'end_time_slot']);

        foreach ($candidates as $candidate) {
            if (! $candidate->start_date || ! $candidate->end_date) {
                continue;
            }
            $candidateStart = $this->startBoundary(
                CarbonImmutable::parse($candidate->start_date->toDateString()),
                (string) ($candidate->start_time_slot ?: 'shift-start'),
            );
            $candidateEnd = $this->endBoundary(
                CarbonImmutable::parse($candidate->end_date->toDateString()),
                (string) ($candidate->end_time_slot ?: 'shift-end'),
            );
            if ($requestedStart->lessThan($candidateEnd) && $requestedEnd->greaterThan($candidateStart)) {
                throw new HttpResponseException(response()->json([
                    'code' => 'LEAVE_OVERLAP',
                    'message' => 'This request overlaps an existing active leave request.',
                    'conflictingRecordId' => $candidate->id,
                    'conflictingDisplayId' => $candidate->display_id,
                ], 409));
            }
        }
    }

    private function startBoundary(CarbonImmutable $date, string $slot): CarbonImmutable
    {
        return $slot === 'midpoint' ? $date->startOfDay()->addHours(12) : $date->startOfDay();
    }

    private function endBoundary(CarbonImmutable $date, string $slot): CarbonImmutable
    {
        return $slot === 'midpoint' ? $date->startOfDay()->addHours(12) : $date->startOfDay()->addDay();
    }
}
