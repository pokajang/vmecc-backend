<?php

namespace App\Console\Commands;

use App\Models\AiHelperMessage;
use App\Models\AiHelperRun;
use Illuminate\Console\Command;

class ReconcileAiHelperStaleStreams extends Command
{
    protected $signature = 'ai-helper:reconcile-stale-streams
        {--minutes= : Age after which a streaming response is considered abandoned}
        {--dry-run : Report stale responses without changing them}';

    protected $description = 'Mark abandoned Ask AI streaming responses as aborted.';

    public function handle(): int
    {
        $minutes = $this->positiveIntegerOption(
            'minutes',
            (int) config('ai_helper.stale_stream_minutes', 10),
        );
        if ($minutes === null) {
            return self::INVALID;
        }

        $query = AiHelperMessage::query()
            ->where('role', AiHelperMessage::ROLE_ASSISTANT)
            ->where('status', AiHelperMessage::STATUS_STREAMING)
            ->where('updated_at', '<=', now()->subMinutes($minutes));
        $matched = (clone $query)->count();

        if (! $this->option('dry-run') && $matched > 0) {
            $messageIds = (clone $query)->pluck('id');
            $query->update([
                'status' => AiHelperMessage::STATUS_ABORTED,
                'error' => 'AI_HELPER_STREAM_ABANDONED',
                'updated_at' => now(),
            ]);
            AiHelperRun::query()
                ->whereIn('assistant_message_id', $messageIds)
                ->where('status', AiHelperRun::STATUS_STARTED)
                ->update([
                    'status' => AiHelperRun::STATUS_ABORTED,
                    'result_code' => 'AI_HELPER_STREAM_ABANDONED',
                    'error_stage' => 'reconciliation',
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        $this->info(sprintf(
            '%s %d stale Ask AI streaming response(s).',
            $this->option('dry-run') ? 'Matched' : 'Reconciled',
            $matched,
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
