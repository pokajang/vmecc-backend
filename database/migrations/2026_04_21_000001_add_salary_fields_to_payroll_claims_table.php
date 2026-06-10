<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_claims', function (Blueprint $table) {
            $table->decimal('adjustments_total', 14, 2)->nullable()->after('approved_overtime_payout');
            $table->decimal('projected_net_payout', 14, 2)->nullable()->after('adjustments_total');
        });

        // Backfill existing salary claims from their line items + overtime payout
        DB::table('payroll_claims')
            ->where('claim_type', 'salary')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->each(function (object $claim) {
                $items = DB::table('payroll_claim_items')
                    ->where('payroll_claim_id', $claim->id)
                    ->get();

                $additionsSum = $items->where('item_type', 'Addition')->sum('amount');
                $deductionsSum = $items->where('item_type', 'Deduction')->sum('amount');
                $approvedOtPayout = (float) ($claim->approved_overtime_payout ?? 0);

                $adjustmentsTotal = round($additionsSum - $deductionsSum + $approvedOtPayout, 2);

                $payrollSnapshot = json_decode((string) ($claim->payroll_snapshot ?? '{}'), true);
                $snapshotNet = (float) ($payrollSnapshot['net'] ?? 0);
                $projectedNetPayout = round($snapshotNet + $adjustmentsTotal, 2);

                DB::table('payroll_claims')->where('id', $claim->id)->update([
                    'adjustments_total' => $adjustmentsTotal,
                    'projected_net_payout' => $projectedNetPayout,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('payroll_claims', function (Blueprint $table) {
            $table->dropColumn(['adjustments_total', 'projected_net_payout']);
        });
    }
};
