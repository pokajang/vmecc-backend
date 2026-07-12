<?php

namespace App\Console\Commands;

use App\Models\InspectionDutyConfirmation;
use Illuminate\Console\Command;

class PruneInspectionDutyConfirmations extends Command
{
    protected $signature = 'inspection:prune-duty-confirmations {--days=7 : Retention period after expiry or closure}';

    protected $description = 'Remove expired, consumed, and revoked inspection duty confirmation secrets';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) $this->option('days')));
        $deleted = InspectionDutyConfirmation::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where('expires_at', '<', $cutoff)
                    ->orWhere('consumed_at', '<', $cutoff)
                    ->orWhere('revoked_at', '<', $cutoff);
            })
            ->delete();

        $this->info("Pruned {$deleted} inspection duty confirmations.");

        return self::SUCCESS;
    }
}
