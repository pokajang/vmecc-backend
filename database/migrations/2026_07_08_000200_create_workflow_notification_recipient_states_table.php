<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_notification_recipient_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('workflow_notifications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('emailed_immediate_at')->nullable();
            $table->timestamp('emailed_digest_at')->nullable();
            $table->timestamp('last_reminder_at')->nullable();
            $table->string('channel_policy', 80)->default('in_app_only');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['notification_id', 'user_id'], 'workflow_notification_recipient_state_unique');
            $table->index(['user_id', 'read_at'], 'workflow_notification_recipient_state_user_read_idx');
            $table->index(['user_id', 'dismissed_at'], 'workflow_notification_recipient_state_user_dismissed_idx');
            $table->index(['user_id', 'resolved_at'], 'workflow_notification_recipient_state_user_resolved_idx');
            $table->index(['channel_policy', 'resolved_at'], 'workflow_notification_recipient_state_channel_resolved_idx');
        });

        $readsByNotification = DB::table('workflow_notification_reads')
            ->select('notification_id', 'user_id', 'read_at')
            ->orderBy('notification_id')
            ->get()
            ->groupBy('notification_id');

        $dismissalsByNotification = DB::table('workflow_notification_dismissals')
            ->select('notification_id', 'user_id', 'dismissed_at')
            ->orderBy('notification_id')
            ->get()
            ->groupBy('notification_id');

        DB::table('workflow_notifications')
            ->select([
                'id',
                'owner_user_id',
                'recipient_user_ids',
                'action_required',
                'channel_policy',
                'resolved_at',
                'created_at',
                'updated_at',
            ])
            ->orderBy('id')
            ->chunkById(100, function (Collection $notifications) use ($readsByNotification, $dismissalsByNotification) {
                $rows = [];
                foreach ($notifications as $notification) {
                    $recipientIds = collect(json_decode((string) ($notification->recipient_user_ids ?? '[]'), true) ?: [])
                        ->map(fn ($value) => (int) $value)
                        ->filter(fn (int $id) => $id > 0)
                        ->values();

                    $viewerIds = $recipientIds
                        ->push((int) $notification->owner_user_id)
                        ->filter(fn (int $id) => $id > 0)
                        ->unique()
                        ->values();

                    if ($viewerIds->isEmpty()) {
                        continue;
                    }

                    $notificationReads = collect($readsByNotification->get($notification->id, collect()))
                        ->keyBy(fn ($item) => (int) $item->user_id);
                    $notificationDismissals = collect($dismissalsByNotification->get($notification->id, collect()))
                        ->keyBy(fn ($item) => (int) $item->user_id);

                    foreach ($viewerIds as $userId) {
                        $isExplicitRecipient = $recipientIds->contains($userId);
                        $policy = $isExplicitRecipient
                            ? (string) ($notification->channel_policy ?: ($notification->action_required ? 'in_app_plus_immediate_plus_digest_reminder' : 'in_app_plus_digest'))
                            : 'in_app_only';
                        $read = $notificationReads->get($userId);
                        $dismissal = $notificationDismissals->get($userId);

                        $rows[] = [
                            'notification_id' => (int) $notification->id,
                            'user_id' => (int) $userId,
                            'read_at' => $read?->read_at,
                            'dismissed_at' => $dismissal?->dismissed_at,
                            'emailed_immediate_at' => null,
                            'emailed_digest_at' => null,
                            'last_reminder_at' => null,
                            'channel_policy' => $policy,
                            'resolved_at' => $notification->resolved_at,
                            'created_at' => $notification->created_at ?: now(),
                            'updated_at' => $notification->updated_at ?: $notification->created_at ?: now(),
                        ];
                    }
                }

                if (! empty($rows)) {
                    DB::table('workflow_notification_recipient_states')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_notification_recipient_states');
    }
};
