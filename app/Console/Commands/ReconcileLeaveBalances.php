<?php

namespace App\Console\Commands;

use App\Models\Leave;
use App\Models\LeaveAssignment;
use Illuminate\Console\Command;

class ReconcileLeaveBalances extends Command
{
    protected $signature = 'leave:reconcile-balances {--json : Emit discrepancies as JSON}';

    protected $description = 'Report leave balance discrepancies without changing data.';

    public function handle(): int
    {
        $expected = [];
        Leave::query()
            ->whereIn('status', ['Pending', 'Needs Correction', 'Approved'])
            ->orderBy('id')
            ->each(function (Leave $leave) use (&$expected): void {
                if (! $leave->start_date) {
                    return;
                }
                $key = implode(':', [$leave->user_id, $leave->start_date->year, $leave->leave_type]);
                $expected[$key] ??= [
                    'user_id' => (int) $leave->user_id,
                    'year' => (int) $leave->start_date->year,
                    'leave_type' => (string) $leave->leave_type,
                    'used' => 0.0,
                    'pending' => 0.0,
                ];
                if ($leave->status === 'Approved') {
                    $expected[$key]['used'] += (float) $leave->days;
                } else {
                    $expected[$key]['pending'] += (float) $leave->days;
                }
            });

        $assignments = LeaveAssignment::query()->get()->keyBy(
            fn (LeaveAssignment $row) => implode(':', [$row->user_id, $row->year, $row->leave_type]),
        );
        $keys = array_unique([...array_keys($expected), ...$assignments->keys()->all()]);
        $discrepancies = [];
        foreach ($keys as $key) {
            $actual = $assignments->get($key);
            $target = $expected[$key] ?? null;
            $used = (float) ($target['used'] ?? 0);
            $pending = (float) ($target['pending'] ?? 0);
            if (! $actual || abs((float) $actual->used - $used) > 0.0001 || abs((float) $actual->pending - $pending) > 0.0001) {
                $discrepancies[] = [
                    'user_id' => $target['user_id'] ?? (int) $actual->user_id,
                    'year' => $target['year'] ?? (int) $actual->year,
                    'leave_type' => $target['leave_type'] ?? (string) $actual->leave_type,
                    'actual_used' => (float) ($actual->used ?? 0),
                    'expected_used' => $used,
                    'actual_pending' => (float) ($actual->pending ?? 0),
                    'expected_pending' => $pending,
                    'assignment_exists' => $actual !== null,
                ];
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($discrepancies, JSON_PRETTY_PRINT));
        } elseif ($discrepancies === []) {
            $this->info('Leave balances match active leave workflow records. No data was changed.');
        } else {
            $this->table(
                ['User', 'Year', 'Leave Type', 'Actual Used', 'Expected Used', 'Actual Pending', 'Expected Pending', 'Assignment'],
                array_map(fn (array $row) => [
                    $row['user_id'], $row['year'], $row['leave_type'], $row['actual_used'], $row['expected_used'],
                    $row['actual_pending'], $row['expected_pending'], $row['assignment_exists'] ? 'yes' : 'missing',
                ], $discrepancies),
            );
            $this->warn('Review discrepancies with HR before any manual correction. No data was changed.');
        }

        return self::SUCCESS;
    }
}
