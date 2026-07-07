<?php

namespace App\Jobs;

use App\Mail\WorkflowDigestNotificationMail;
use App\Models\User;
use App\Models\WorkflowEmailDelivery;
use App\Models\WorkflowNotificationRecipientState;
use App\Services\WorkflowNotifications\WorkflowNotificationChannelPolicy;
use App\Services\WorkflowNotifications\WorkflowNotificationLinkResolver;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendWorkflowDigestEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 180, 600];

    public function __construct(
        private readonly int $userId,
        private readonly string $windowStartIso,
        private readonly string $windowEndIso,
    ) {
    }

    public function handle(WorkflowNotificationLinkResolver $linkResolver): void
    {
        if (! config('mail.workflow_notifications.enabled', false)) {
            return;
        }

        $recipient = User::query()->whereKey($this->userId)->whereNotNull('email')->first();
        if (! $recipient) {
            return;
        }

        $windowStart = CarbonImmutable::parse($this->windowStartIso);
        $windowEnd = CarbonImmutable::parse($this->windowEndIso);

        $reservationTime = now();
        $candidateDeferredIds = $this->deferredStates($windowStart, $windowEnd)->pluck('id');
        $candidateReminderIds = $this->reminderStates($windowStart, $windowEnd)->pluck('id');

        if ($candidateDeferredIds->isEmpty() && $candidateReminderIds->isEmpty()) {
            return;
        }

        if ($candidateDeferredIds->isNotEmpty()) {
            WorkflowNotificationRecipientState::query()
                ->whereIn('id', $candidateDeferredIds->all())
                ->where(function ($query) use ($windowStart) {
                    $query->whereNull('emailed_digest_at')
                        ->orWhere('emailed_digest_at', '<', $windowStart);
                })
                ->update([
                    'emailed_digest_at' => $reservationTime,
                    'updated_at' => $reservationTime,
                ]);
        }

        if ($candidateReminderIds->isNotEmpty()) {
            WorkflowNotificationRecipientState::query()
                ->whereIn('id', $candidateReminderIds->all())
                ->where(function ($query) use ($windowStart) {
                    $query->whereNull('last_reminder_at')
                        ->orWhere('last_reminder_at', '<', $windowStart);
                })
                ->update([
                    'last_reminder_at' => $reservationTime,
                    'updated_at' => $reservationTime,
                ]);
        }

        $deferredStates = WorkflowNotificationRecipientState::query()
            ->with('notification')
            ->whereIn('id', $candidateDeferredIds->all())
            ->where('emailed_digest_at', $reservationTime)
            ->get();
        $reminderStates = WorkflowNotificationRecipientState::query()
            ->with('notification')
            ->whereIn('id', $candidateReminderIds->all())
            ->where('last_reminder_at', $reservationTime)
            ->get();

        $deferredItems = $this->groupDigestItems($deferredStates, $linkResolver, $recipient);
        $reminderItems = $this->groupDigestItems($reminderStates, $linkResolver, $recipient);

        if ($deferredItems->isEmpty() && $reminderItems->isEmpty()) {
            return;
        }

        try {
            Mail::to($recipient->email)->send(new WorkflowDigestNotificationMail(
                $recipient,
                $deferredItems,
                $reminderItems,
                $windowStart->toMutable(),
                $windowEnd->toMutable(),
            ));

            DB::transaction(function () use ($deferredStates, $reminderStates, $recipient, $windowStart, $windowEnd, $reservationTime) {
                foreach ($deferredStates as $state) {
                    WorkflowEmailDelivery::create([
                        'notification_id' => $state->notification_id,
                        'user_id' => $recipient->id,
                        'recipient_email' => (string) $recipient->email,
                        'delivery_kind' => 'digest',
                        'digest_window_start' => $windowStart,
                        'digest_window_end' => $windowEnd,
                        'status' => 'sent',
                        'attempts' => 1,
                        'sent_at' => now(),
                    ]);

                    $state->forceFill([
                        'emailed_digest_at' => $reservationTime,
                        'updated_at' => now(),
                    ])->save();
                }

                foreach ($reminderStates as $state) {
                    WorkflowEmailDelivery::create([
                        'notification_id' => $state->notification_id,
                        'user_id' => $recipient->id,
                        'recipient_email' => (string) $recipient->email,
                        'delivery_kind' => 'reminder',
                        'digest_window_start' => $windowStart,
                        'digest_window_end' => $windowEnd,
                        'status' => 'sent',
                        'attempts' => 1,
                        'sent_at' => now(),
                    ]);

                    $state->forceFill([
                        'last_reminder_at' => $reservationTime,
                        'updated_at' => now(),
                    ])->save();
                }
            });
        } catch (\Throwable $exception) {
            foreach ($deferredStates as $state) {
                WorkflowEmailDelivery::create([
                    'notification_id' => $state->notification_id,
                    'user_id' => $recipient->id,
                    'recipient_email' => (string) $recipient->email,
                    'delivery_kind' => 'digest',
                    'digest_window_start' => $windowStart,
                    'digest_window_end' => $windowEnd,
                    'status' => 'failed',
                    'attempts' => 1,
                    'last_error' => $exception->getMessage(),
                ]);
            }

            foreach ($reminderStates as $state) {
                WorkflowEmailDelivery::create([
                    'notification_id' => $state->notification_id,
                    'user_id' => $recipient->id,
                    'recipient_email' => (string) $recipient->email,
                    'delivery_kind' => 'reminder',
                    'digest_window_start' => $windowStart,
                    'digest_window_end' => $windowEnd,
                    'status' => 'failed',
                    'attempts' => 1,
                    'last_error' => $exception->getMessage(),
                ]);
            }

            if ($deferredStates->isNotEmpty()) {
                WorkflowNotificationRecipientState::query()
                    ->whereIn('id', $deferredStates->pluck('id')->all())
                    ->where('emailed_digest_at', $reservationTime)
                    ->update([
                        'emailed_digest_at' => null,
                        'updated_at' => now(),
                    ]);
            }

            if ($reminderStates->isNotEmpty()) {
                WorkflowNotificationRecipientState::query()
                    ->whereIn('id', $reminderStates->pluck('id')->all())
                    ->where('last_reminder_at', $reservationTime)
                    ->update([
                        'last_reminder_at' => null,
                        'updated_at' => now(),
                    ]);
            }

            Log::warning('Workflow digest email dispatch failed.', [
                'user_id' => $recipient->id,
                'window_start' => $windowStart->toIso8601String(),
                'window_end' => $windowEnd->toIso8601String(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function deferredStates(CarbonImmutable $windowStart, CarbonImmutable $windowEnd): Builder
    {
        return WorkflowNotificationRecipientState::query()
            ->with('notification')
            ->where('user_id', $this->userId)
            ->whereNull('dismissed_at')
            ->whereNull('read_at')
            ->where(function ($query) {
                $query
                    ->where('channel_policy', WorkflowNotificationChannelPolicy::IN_APP_PLUS_DIGEST)
                    ->orWhere('channel_policy', WorkflowNotificationChannelPolicy::IN_APP_PLUS_IMMEDIATE_PLUS_DIGEST_REMINDER);
            })
            ->where(function ($query) use ($windowStart) {
                $query->whereNull('emailed_digest_at')
                    ->orWhere('emailed_digest_at', '<', $windowStart);
            })
            ->whereHas('notification', function (Builder $query) use ($windowStart, $windowEnd) {
                $query
                    ->whereBetween('updated_at', [$windowStart, $windowEnd])
                    ->whereNotIn('category', [
                        'final_outcome',
                        'action_required_review',
                        'action_required_approve',
                    ])
                    ->where(function ($inner) use ($windowEnd) {
                        $inner->whereNull('resolved_at')->orWhere('resolved_at', '>', $windowEnd);
                    });
            });
    }

    private function reminderStates(CarbonImmutable $windowStart, CarbonImmutable $windowEnd): Builder
    {
        return WorkflowNotificationRecipientState::query()
            ->with('notification')
            ->where('user_id', $this->userId)
            ->whereNull('dismissed_at')
            ->where('channel_policy', WorkflowNotificationChannelPolicy::IN_APP_PLUS_IMMEDIATE_PLUS_DIGEST_REMINDER)
            ->where(function ($query) use ($windowStart) {
                $query->whereNull('last_reminder_at')
                    ->orWhere('last_reminder_at', '<', $windowStart);
            })
            ->where(function ($query) use ($windowEnd) {
                $query->whereNull('resolved_at')
                    ->orWhere('resolved_at', '>', $windowEnd);
            })
            ->whereHas('notification', function (Builder $query) use ($windowEnd) {
                $query
                    ->where('action_required', true)
                    ->where(function ($inner) use ($windowEnd) {
                        $inner->whereNull('resolved_at')->orWhere('resolved_at', '>', $windowEnd);
                    });
            });
    }

    private function groupDigestItems(
        Collection $states,
        WorkflowNotificationLinkResolver $linkResolver,
        User $recipient,
    ): Collection {
        return $states
            ->filter(fn (WorkflowNotificationRecipientState $state) => $state->notification !== null)
            ->sortByDesc(fn (WorkflowNotificationRecipientState $state) => optional($state->notification->updated_at ?? $state->notification->created_at)?->getTimestamp() ?? 0)
            ->groupBy(function (WorkflowNotificationRecipientState $state) {
                $notification = $state->notification;
                return implode('|', [
                    strtolower(trim((string) $notification->module)),
                    strtolower(trim((string) $notification->record_type)),
                    (string) ($notification->record_id ?? $notification->record_display_id ?? ''),
                    strtolower(trim((string) ($notification->category ?? ''))),
                ]);
            })
            ->map(function (Collection $group) use ($linkResolver, $recipient) {
                $state = $group->first();
                $notification = $state->notification;
                $metadata = is_array($notification->metadata) ? $notification->metadata : [];

                return [
                    'notificationId' => $notification->id,
                    'module' => (string) $notification->module,
                    'category' => (string) $notification->category,
                    'title' => (string) $notification->title,
                    'message' => (string) $notification->message,
                    'recordDisplayId' => (string) ($notification->record_display_id ?? ''),
                    'eventType' => (string) $notification->event_type,
                    'workflowStage' => (string) ($metadata['workflowStage'] ?? $metadata['workflow_stage'] ?? ''),
                    'deepLink' => $linkResolver->resolveAbsolute($notification, $recipient),
                    'createdAt' => optional($notification->created_at)?->toIso8601String(),
                ];
            })
            ->groupBy(fn (array $item) => strtolower(trim((string) $item['module'])))
            ->map(fn (Collection $items, string $module) => [
                'module' => $module,
                'count' => $items->count(),
                'items' => $items->values(),
            ])
            ->values();
    }
}
