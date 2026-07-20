<?php

namespace App\Console\Commands;

use App\Models\AiHelperMessage;
use App\Models\AiHelperResponseReport;
use App\Models\AiHelperRun;
use App\Models\AiHelperThread;
use Illuminate\Console\Command;

class PruneAiHelperRuntimeData extends Command
{
    protected $signature = 'ai-helper:prune-runtime-data
        {--conversation-days= : Retention period for inactive, unreported conversations}
        {--failed-message-days= : Retention period for empty failed or aborted responses}
        {--run-days= : Retention period for compact operational run telemetry}
        {--resolved-report-days= : Retention period for resolved or dismissed response reports}
        {--report-network-data-days= : Retention period for report IP and user-agent fields}
        {--dry-run : Report matching records without changing them}';

    protected $description = 'Prune retained Ask AI conversations, telemetry and closed reports, and anonymize old network data.';

    public function handle(): int
    {
        $conversationDays = $this->positiveIntegerOption(
            'conversation-days',
            (int) config('ai_helper.conversation_retention_days', 90),
        );
        $failedMessageDays = $this->positiveIntegerOption(
            'failed-message-days',
            (int) config('ai_helper.failed_message_retention_days', 7),
        );
        $runDays = $this->positiveIntegerOption(
            'run-days',
            (int) config('ai_helper.run_retention_days', 90),
        );
        $resolvedReportDays = $this->positiveIntegerOption(
            'resolved-report-days',
            (int) config('ai_helper.resolved_report_retention_days', 90),
        );
        $networkDataDays = $this->positiveIntegerOption(
            'report-network-data-days',
            (int) config('ai_helper.report_network_data_retention_days', 30),
        );
        if (in_array(null, [
            $conversationDays,
            $failedMessageDays,
            $runDays,
            $resolvedReportDays,
            $networkDataDays,
        ], true)) {
            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $closedStatuses = [
            AiHelperResponseReport::STATUS_RESOLVED,
            AiHelperResponseReport::STATUS_DISMISSED,
        ];
        $closedReportCutoff = now()->subDays($resolvedReportDays);

        $networkData = AiHelperResponseReport::query()
            ->where('created_at', '<=', now()->subDays($networkDataDays))
            ->where(fn ($query) => $query->whereNotNull('reporter_ip')->orWhereNotNull('reporter_user_agent'));
        $networkDataCount = (clone $networkData)->count();
        if (! $dryRun && $networkDataCount > 0) {
            $networkData->update([
                'reporter_ip' => null,
                'reporter_user_agent' => null,
            ]);
        }

        $closedReports = AiHelperResponseReport::query()
            ->whereIn('status', $closedStatuses)
            ->where('updated_at', '<=', $closedReportCutoff);
        $closedReportCount = (clone $closedReports)->count();
        if (! $dryRun && $closedReportCount > 0) {
            $closedReports->delete();
        }

        $abandonedMessages = AiHelperMessage::query()
            ->where('role', AiHelperMessage::ROLE_ASSISTANT)
            ->whereIn('status', [AiHelperMessage::STATUS_ABORTED, AiHelperMessage::STATUS_FAILED])
            ->where(fn ($query) => $query->whereNull('content')->orWhere('content', ''))
            ->where('updated_at', '<=', now()->subDays($failedMessageDays))
            ->whereDoesntHave('responseReports');
        $abandonedMessageCount = (clone $abandonedMessages)->count();
        if (! $dryRun && $abandonedMessageCount > 0) {
            $abandonedMessages->delete();
        }

        $threads = AiHelperThread::query()
            ->where('updated_at', '<=', now()->subDays($conversationDays))
            ->whereDoesntHave('responseReports', function ($query) use ($closedStatuses, $closedReportCutoff): void {
                $query->where(function ($preserved) use ($closedStatuses, $closedReportCutoff): void {
                    $preserved
                        ->whereNotIn('status', $closedStatuses)
                        ->orWhere('updated_at', '>', $closedReportCutoff);
                });
            });
        $threadCount = (clone $threads)->count();
        if (! $dryRun && $threadCount > 0) {
            $threads->delete();
        }

        $runs = AiHelperRun::query()->where('created_at', '<=', now()->subDays($runDays));
        $runCount = (clone $runs)->count();
        if (! $dryRun && $runCount > 0) {
            $runs->delete();
        }

        $verb = $dryRun ? 'Matched' : 'Pruned';
        $this->info(sprintf(
            '%s: %d abandoned message(s), %d conversation(s), %d run(s), %d closed report(s); %d report(s) had network data anonymized.',
            $verb,
            $abandonedMessageCount,
            $threadCount,
            $runCount,
            $closedReportCount,
            $networkDataCount,
        ));

        return self::SUCCESS;
    }

    private function positiveIntegerOption(string $name, int $default): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return max(1, $default);
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            $this->error("The --{$name} option must be a positive integer.");

            return null;
        }

        return (int) $value;
    }
}
