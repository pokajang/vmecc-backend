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
    public function inputForThread(
        AiHelperThread $thread,
        int $currentUserMessageId,
        ?array $visibleEvidenceIds = null,
    ): array {
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
            ->map(function (AiHelperMessage $message) use ($visibleEvidenceIds) {
                $content = (string) $message->content;
                if ($message->role === AiHelperMessage::ROLE_ASSISTANT) {
                    $documentIds = collect((array) data_get($message->retrieval_metadata, 'document_ids', []))
                        ->map(fn ($id) => (int) $id)
                        ->filter()
                        ->unique();
                    if ($visibleEvidenceIds !== null
                        && $documentIds->diff($visibleEvidenceIds)->isNotEmpty()) {
                        // Never resend revoked guidance to the provider. The user
                        // may keep the question in context, but its old answer is
                        // replaced with the same access-change tombstone as the UI.
                        $content = 'Previous Ask AI response unavailable because access changed.';
                    }
                    // Source IDs are response-local. Keeping old [S#] markers in
                    // model history can make a later answer cite the wrong pack.
                    $content = trim((string) preg_replace('/\s*\[S[1-9]\d*\]/u', '', $content));
                }

                return [
                    'role' => $message->role,
                    'content' => $content,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int, int> */
    public function evidenceIdsForThread(AiHelperThread $thread, int $currentUserMessageId): array
    {
        return $thread->messages()
            ->where('id', '<', $currentUserMessageId)
            ->where('role', AiHelperMessage::ROLE_ASSISTANT)
            ->where('status', AiHelperMessage::STATUS_COMPLETED)
            ->latest('created_at')
            ->limit(max(2, (int) config('ai_helper.history_turns', 12)) * 2)
            ->get(['retrieval_metadata'])
            ->flatMap(fn (AiHelperMessage $message) => (array) data_get(
                $message->retrieval_metadata,
                'document_ids',
                [],
            ))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
