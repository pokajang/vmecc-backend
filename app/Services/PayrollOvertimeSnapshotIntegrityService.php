<?php

namespace App\Services;

use App\Models\OvertimeRecord;
use App\Models\PayrollClaim;
use Illuminate\Validation\ValidationException;

class PayrollOvertimeSnapshotIntegrityService
{
    public function driftReason(PayrollClaim $claim): string
    {
        if ((string) $claim->claim_type !== 'salary') {
            return '';
        }

        $snapshotRows = collect(is_array($claim->overtime_rows) ? $claim->overtime_rows : [])
            ->filter(fn ($row) => is_array($row)
                && (int) ($row['overtimeRecordId'] ?? 0) > 0)
            ->values();
        if ($snapshotRows->isEmpty()) {
            return '';
        }

        $records = OvertimeRecord::withTrashed()
            ->whereIn('id', $snapshotRows->pluck('overtimeRecordId')->map(fn ($id) => (int) $id))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($snapshotRows as $snapshot) {
            $record = $records->get((int) $snapshot['overtimeRecordId']);
            if (! $record
                || $record->deleted_at !== null
                || (string) $record->status !== 'Approved'
                || (int) $record->user_id !== (int) $claim->user_id
                || (
                    trim((string) ($snapshot['overtimePublicId'] ?? '')) !== ''
                    && (string) $record->public_id !== (string) $snapshot['overtimePublicId']
                )
                || (
                    (int) ($snapshot['overtimeRecordVersion'] ?? 0) > 0
                    && (int) $record->version !== (int) $snapshot['overtimeRecordVersion']
                )) {
                return 'Approved overtime changed after this salary claim was calculated. The employee must update and resubmit the salary claim.';
            }
        }

        return '';
    }

    public function assertCurrent(PayrollClaim $claim): void
    {
        $reason = $this->driftReason($claim);
        if ($reason === '') {
            return;
        }

        throw ValidationException::withMessages([
            'overtime_snapshot' => [$reason],
        ]);
    }

    public function assertNotLockedByPaidSalary(OvertimeRecord $record): void
    {
        $paidClaims = PayrollClaim::query()
            ->where('user_id', $record->user_id)
            ->where('claim_type', 'salary')
            ->where('status', 'Paid')
            ->get(['id', 'display_id', 'overtime_rows']);

        $paidClaim = $paidClaims->first(fn (PayrollClaim $claim) => collect(
            is_array($claim->overtime_rows) ? $claim->overtime_rows : [],
        )->contains(fn ($snapshot) => is_array($snapshot)
            && (
                (int) ($snapshot['overtimeRecordId'] ?? 0) === (int) $record->id
                || (
                    trim((string) ($snapshot['overtimePublicId'] ?? '')) !== ''
                    && (string) $snapshot['overtimePublicId'] === (string) $record->public_id
                )
            )));

        if (! $paidClaim) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => [
                "This overtime record is locked because it was paid in salary claim {$paidClaim->display_id}.",
            ],
        ]);
    }
}
