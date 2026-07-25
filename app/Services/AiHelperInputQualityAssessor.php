<?php

namespace App\Services;

use Illuminate\Support\Str;

final class AiHelperInputQualityAssessor
{
    public function __construct(
        private readonly AiHelperSensitiveDataGuard $sensitiveData,
        private readonly AiHelperKnowledgeQueryAnalyzer $analyzer,
    ) {}

    public function assess(string $message, array $previousUserMessages = []): AiHelperInputAssessment
    {
        $message = trim($message);
        $sensitiveCategories = $this->sensitiveData->categories($message);
        if ($sensitiveCategories !== []) {
            return new AiHelperInputAssessment(
                AiHelperInputAssessment::REFUSE_SENSITIVE,
                $sensitiveCategories,
                1.0,
            );
        }

        $normalized = Str::lower($message);
        if ($this->isExfiltrationRequest($normalized)) {
            return new AiHelperInputAssessment(
                AiHelperInputAssessment::REFUSE_EXFILTRATION,
                ['restricted_information_request'],
                0.99,
            );
        }

        if ($this->isObjectivelyInvalid($normalized)) {
            return new AiHelperInputAssessment(
                AiHelperInputAssessment::REPHRASE,
                ['objectively_invalid_input'],
                0.99,
            );
        }

        $analysis = $this->analyzer->analyze($message, $previousUserMessages);
        $topics = array_values((array) ($analysis['topic_keys'] ?? []));
        $tasks = array_values((array) ($analysis['task_keys'] ?? []));
        $operations = array_values((array) ($analysis['operation_keys'] ?? []));
        if (($analysis['clarification_required'] ?? false) === true) {
            return new AiHelperInputAssessment(
                AiHelperInputAssessment::CLARIFY,
                ['structured_product_clarification'],
                0.98,
                $topics,
                true,
            );
        }

        if ($topics !== [] && $tasks === [] && $operations === []
            && preg_match('/\b(?:how|cara|macam mana|bagaimana)\b/u', $normalized) === 1) {
            return new AiHelperInputAssessment(
                AiHelperInputAssessment::CLARIFY,
                ['recognized_topic_missing_action'],
                0.9,
                $topics,
                true,
            );
        }

        if ($topics !== [] || $tasks !== [] || $operations !== [] || $this->looksNaturallyMeaningful($normalized)) {
            return new AiHelperInputAssessment(
                AiHelperInputAssessment::ALLOW,
                $topics === [] ? ['natural_language_input'] : ['recognized_product_language'],
                $topics === [] ? 0.75 : 0.95,
                $topics,
                $topics !== [],
            );
        }

        return new AiHelperInputAssessment(
            AiHelperInputAssessment::SEMANTIC_REVIEW,
            ['low_local_confidence'],
            0.5,
        );
    }

    private function isExfiltrationRequest(string $message): bool
    {
        return preg_match(
            '/\b(?:show|reveal|display|print|give|export|list|tunjuk|dedahkan|papar|beri|senaraikan)\b.{0,60}'.
            '\b(?:system prompt|hidden instructions?|private documents?|other users?[’\']?\s+(?:records?|data)|'.
            'database credentials?|arahan tersembunyi|prompt sistem|dokumen peribadi|rekod pengguna lain)\b/u',
            $message,
        ) === 1
            || preg_match('/\bignore (?:all |the )?(?:previous|system) instructions?\b.{0,80}\b(?:reveal|show|export|print)\b/u', $message) === 1;
    }

    private function isObjectivelyInvalid(string $message): bool
    {
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $message) === 1
            || preg_match('/([a-z0-9])\1{11,}/u', $message) === 1) {
            return true;
        }

        $compact = preg_replace('/[^a-z0-9]+/u', '', $message) ?? '';

        return preg_match('/(?:asdfgh|qwerty|zxcvbn|hjkl){1,}/u', $compact) === 1
            && preg_match('/\b(?:erco|hse|scba|report|laporan|inspection|pemeriksaan)\b/u', $message) !== 1;
    }

    private function looksNaturallyMeaningful(string $message): bool
    {
        if (preg_match('/\b(?:hi|hello|hey|thanks|thank you|terima kasih|hai|tolong|help)\b/u', $message) === 1) {
            return true;
        }

        preg_match_all('/[\pL\pN]+/u', $message, $matches);
        $tokens = $matches[0] ?? [];

        return count($tokens) >= 4
            || (count($tokens) >= 2
                && preg_match('/\b(?:what|why|where|when|who|which|can|could|please|apa|kenapa|mana|bila|siapa|boleh)\b/u', $message) === 1);
    }
}
