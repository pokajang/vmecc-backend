<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        foreach ((array) config('mail.workflow_notifications.digest_times', ['06:00', '18:00']) as $digestTime) {
            $digestTime = trim((string) $digestTime);
            if ($digestTime !== '') {
                $schedule->command('workflow:send-digests')->dailyAt($digestTime)->withoutOverlapping();
            }
        }
        $schedule->command('messages:digest')->dailyAt('09:00')->withoutOverlapping();
        $schedule->command('ai-helper:reconcile-stale-streams')
            ->everyFiveMinutes()
            ->withoutOverlapping(10);
        $schedule->command('ai-helper:reconcile-stuck-embeddings --retry')
            ->everyTenMinutes()
            ->withoutOverlapping(20);
        $schedule->command('ai-helper:prune-knowledge-files')->dailyAt('02:30')->withoutOverlapping();
        $schedule->command('ai-helper:prune-runtime-data')->dailyAt('02:40')->withoutOverlapping();
        $schedule->command('report-media:prune')->dailyAt('03:00')->withoutOverlapping();
        $schedule->command('inspection:prune-duty-confirmations')->dailyAt('03:20')->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
