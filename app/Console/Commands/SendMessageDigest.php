<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\User;
use App\Notifications\MessageDigestNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SendMessageDigest extends Command
{
    protected $signature = 'messages:digest';

    protected $description = 'Send unread message digest emails';

    public function handle(): int
    {
        if (! config('mail.message_digest.enabled', false)) {
            $this->info('Message digest email is disabled.');

            return self::SUCCESS;
        }

        $cutoff = now();

        $recipientIds = Message::whereNull('read_at')
            ->where('created_at', '<=', $cutoff)
            ->distinct()
            ->pluck('recipient_user_id');

        if ($recipientIds->isEmpty()) {
            $this->info('No unread messages.');

            return self::SUCCESS;
        }

        $failures = 0;

        User::whereIn('id', $recipientIds)
            ->whereNull('deleted_at')
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'active'")
            ->whereNotNull('email')
            ->whereRaw("TRIM(email) <> ''")
            ->chunkById(100, function ($users) use (&$failures, $cutoff) {
                foreach ($users as $user) {
                    $messages = Message::with('sender')
                        ->where('recipient_user_id', $user->id)
                        ->whereNull('read_at')
                        ->where('created_at', '<=', $cutoff)
                        ->when(
                            $user->last_message_digest_at,
                            fn ($query, $lastDigestAt) => $query->where('created_at', '>', $lastDigestAt),
                        )
                        ->orderBy('created_at')
                        ->get();

                    if ($messages->isEmpty()) {
                        continue;
                    }

                    $count = $messages->count();
                    $digestItems = $messages->map(function (Message $message) {
                        return [
                            'name' => $message->sender?->name ?? $message->sender?->email ?? 'Someone',
                            'time' => optional($message->created_at)->toDateTimeString(),
                            'snippet' => Str::limit(trim(preg_replace('/\s+/', ' ', $message->body)), 120),
                        ];
                    })->all();

                    $topSenders = $messages
                        ->groupBy('sender_user_id')
                        ->map(fn ($items) => $items->count())
                        ->sortDesc()
                        ->take(3)
                        ->map(function ($count, $senderId) use ($messages) {
                            $sender = $messages->firstWhere('sender_user_id', $senderId)?->sender;

                            return [
                                'name' => $sender?->name ?? $sender?->email ?? 'Someone',
                                'count' => $count,
                            ];
                        })
                        ->values()
                        ->all();

                    try {
                        $user->notify(new MessageDigestNotification($count, $topSenders, $digestItems));
                        $user->forceFill(['last_message_digest_at' => $cutoff])->save();
                    } catch (\Throwable $e) {
                        $failures++;
                        $this->error("Failed to send digest to {$user->email}: {$e->getMessage()}");
                    }
                }
            });

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
