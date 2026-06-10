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
        $recipientIds = Message::whereNull('read_at')
            ->distinct()
            ->pluck('recipient_user_id');

        if ($recipientIds->isEmpty()) {
            $this->info('No unread messages.');
            return self::SUCCESS;
        }

        User::whereIn('id', $recipientIds)
            ->whereNull('deleted_at')
            ->where('status', 'Active')
            ->chunkById(100, function ($users) {
                foreach ($users as $user) {
                    if (! $user->email) {
                        continue;
                    }

                    $messages = Message::with('sender')
                        ->where('recipient_user_id', $user->id)
                        ->whereNull('read_at')
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
                        $user->forceFill(['last_message_digest_at' => now()])->save();
                    } catch (\Throwable $e) {
                        $this->error("Failed to send digest to {$user->email}: {$e->getMessage()}");
                    }
                }
            });

        return self::SUCCESS;
    }
}
