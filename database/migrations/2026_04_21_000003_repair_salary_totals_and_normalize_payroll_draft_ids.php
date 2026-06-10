<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('payroll_claims')
            ->where('claim_type', 'salary')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->each(function (object $claim): void {
                $items = DB::table('payroll_claim_items')
                    ->where('payroll_claim_id', $claim->id)
                    ->orderBy('line_no')
                    ->get();

                $additions = 0.0;
                $deductions = 0.0;

                foreach ($items as $item) {
                    $itemType = strtolower(trim((string) ($item->item_type ?? '')));
                    $notes = strtolower(trim((string) ($item->notes ?? '')));
                    $rawAmount = round((float) ($item->amount ?? 0), 2);

                    $isOvertimeFallback = $itemType === 'addition'
                        && str_contains($notes, 'approved overtime payout');
                    if ($isOvertimeFallback) {
                        continue;
                    }

                    $isDeduction = in_array($itemType, ['deduction', 'deduct', 'minus'], true) || $rawAmount < 0;
                    $amount = abs($rawAmount);
                    if ($isDeduction) {
                        $deductions += $amount;
                    } else {
                        $additions += $amount;
                    }
                }

                $adjustmentsTotal = round($additions - $deductions, 2);
                $snapshot = json_decode((string) ($claim->payroll_snapshot ?? '{}'), true);
                $snapshotNet = round((float) ($snapshot['net'] ?? $snapshot['netSalary'] ?? 0), 2);
                $approvedOtPayout = round((float) ($claim->approved_overtime_payout ?? 0), 2);
                $projectedNetPayout = round($snapshotNet + $adjustmentsTotal + $approvedOtPayout, 2);

                DB::table('payroll_claims')
                    ->where('id', $claim->id)
                    ->update([
                        'adjustments_total' => $adjustmentsTotal,
                        'projected_net_payout' => $projectedNetPayout,
                    ]);
            });

        DB::table('payroll_claim_drafts')
            ->orderBy('id')
            ->each(function (object $draft): void {
                $currentDraftId = trim((string) ($draft->draft_id ?? ''));
                if ($currentDraftId !== '') {
                    return;
                }

                $baseDraftId = sprintf('legacy-%s-%d', (string) $draft->claim_type, (int) $draft->id);
                $nextDraftId = $baseDraftId;
                $counter = 2;
                while (
                    DB::table('payroll_claim_drafts')
                        ->where('user_id', (int) $draft->user_id)
                        ->where('claim_type', (string) $draft->claim_type)
                        ->where('draft_id', $nextDraftId)
                        ->exists()
                ) {
                    $nextDraftId = "{$baseDraftId}-{$counter}";
                    $counter++;
                }

                DB::table('payroll_claim_drafts')
                    ->where('id', (int) $draft->id)
                    ->update(['draft_id' => $nextDraftId]);
            });
    }

    public function down(): void
    {
        // Irreversible data repair migration.
    }
};

