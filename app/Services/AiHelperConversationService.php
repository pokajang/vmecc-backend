<?php

namespace App\Services;

use App\Models\AiHelperMessage;
use App\Models\AiHelperThread;

class AiHelperConversationService
{
    /** @return array<int, string> */
    public function recentUserMessages(?AiHelperThread $thread, int $limit = 3): array
    {
        if (! $thread) {
            return [];
        }

        return $thread->messages()
            ->where('role', AiHelperMessage::ROLE_USER)
            ->where('status', AiHelperMessage::STATUS_COMPLETED)
            ->latest('created_at')
            ->limit(max(1, $limit))
            ->pluck('content')
            ->reverse()
            ->map(fn ($content) => trim((string) $content))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function inputForThread(AiHelperThread $thread, int $currentUserMessageId): array
    {
        $turnLimit = max(2, (int) config('ai_helper.history_turns', 12));
        $characterLimit = max(1000, (int) config('ai_helper.history_max_characters', 12000));
        $usedCharacters = 0;

        $messages = $thread->messages()
            ->where('id', '<=', $currentUserMessageId)
            ->whereIn('role', [AiHelperMessage::ROLE_USER, AiHelperMessage::ROLE_ASSISTANT])
            ->where('status', AiHelperMessage::STATUS_COMPLETED)
            ->latest('created_at')
            ->limit($turnLimit * 2)
            ->get()
            ->filter(fn (AiHelperMessage $message) => trim((string) $message->content) !== '')
            ->values();

        $selected = [];
        foreach ($messages as $message) {
            $content = (string) $message->content;
            $length = strlen($content);
            if ($selected !== [] && ($usedCharacters + $length) > $characterLimit) {
                continue;
            }

            $selected[] = $message;
            $usedCharacters += $length;

            if (count($selected) >= $turnLimit) {
                break;
            }
        }

        return collect($selected)
            ->sortBy('created_at')
            ->map(fn (AiHelperMessage $message) => [
                'role' => $message->role,
                'content' => (string) $message->content,
            ])
            ->values()
            ->all();
    }
}
