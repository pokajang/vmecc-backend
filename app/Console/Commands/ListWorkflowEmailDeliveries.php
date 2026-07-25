<?php

namespace App\Console\Commands;

use App\Models\WorkflowEmailDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ListWorkflowEmailDeliveries extends Command
{
    protected $signature = 'workflow:email-deliveries
        {--status= : Filter by delivery status, for example failed or sent}
        {--user= : Filter by user id or recipient email}
        {--module= : Filter by workflow module}
        {--kind= : Filter by delivery kind: immediate, digest, or reminder}
        {--since= : Filter rows created at or after this timestamp}
        {--limit=50 : Maximum rows to show}';

    protected $description = 'List workflow email delivery attempts for support and operations.';

    public function handle(): int
    {
        $limit = min(max((int) $this->option('limit'), 1), 500);
        $status = $this->text($this->option('status'));
        $user = $this->text($this->option('user'));
        $module = $this->text($this->option('module'));
        $kind = $this->text($this->option('kind'));
        $since = $this->text($this->option('since'));

        $query = WorkflowEmailDelivery::query()
            ->with(['notification:id,module,record_type,record_id,record_display_id,event_type', 'user:id,name,email'])
            ->when($status !== '', fn (Builder $builder) => $builder->where('status', $status))
            ->when($kind !== '', fn (Builder $builder) => $builder->where('delivery_kind', $kind))
            ->when($module !== '', fn (Builder $builder) => $builder->whereHas(
                'notification',
                fn (Builder $notification) => $notification->where('module', $module),
            ))
            ->when($user !== '', function (Builder $builder) use ($user) {
                if (ctype_digit($user)) {
                    $builder->where('user_id', (int) $user);

                    return;
                }

                $builder->where('recipient_email', $user);
            })
            ->when($since !== '', fn (Builder $builder) => $builder->where(
                'created_at',
                '>=',
                CarbonImmutable::parse($since),
            ))
            ->latest('created_at')
            ->limit($limit);

        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->info('No workflow email deliveries matched the filters.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Notification', 'User', 'Email', 'Module', 'Kind', 'Status', 'Attempts', 'Sent At', 'Last Error'],
            $rows->map(function (WorkflowEmailDelivery $delivery) {
                $notification = $delivery->notification;
                $user = $delivery->user;

                return [
                    $delivery->id,
                    $delivery->notification_id,
                    $user ? "{$user->id}: {$user->name}" : (string) ($delivery->user_id ?? ''),
                    (string) $delivery->recipient_email,
                    (string) ($notification?->module ?? ''),
                    (string) $delivery->delivery_kind,
                    (string) $delivery->status,
                    (string) $delivery->attempts,
                    optional($delivery->sent_at)->toDateTimeString() ?: '',
                    mb_strimwidth((string) ($delivery->last_error ?? ''), 0, 80, '...'),
                ];
            })->all(),
        );

        $failedRows = $rows->filter(fn (WorkflowEmailDelivery $delivery) => (string) $delivery->last_error !== '');
        foreach ($failedRows as $delivery) {
            $this->line(sprintf(
                'Delivery %d error: %s',
                $delivery->id,
                (string) $delivery->last_error,
            ));
        }

        return self::SUCCESS;
    }

    private function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }
}
