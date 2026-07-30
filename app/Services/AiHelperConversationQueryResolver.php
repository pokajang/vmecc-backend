<?php

namespace App\Services;

use Illuminate\Support\Str;

final class AiHelperConversationQueryResolver
{
    public function __construct(
        private readonly AiHelperTopicAliasRegistry $topics,
        private readonly AiHelperQuestionFacetAnalyzer $facets,
    ) {}

    /**
     * @param  array<int, string>  $previousUserMessages
     * @return array{query: string, follow_up: bool, confidence: string, anchor: ?string}
     */
    public function resolve(string $message, array $previousUserMessages): array
    {
        $message = trim($message);
        $normalized = $this->normalize($message);
        $history = collect($previousUserMessages)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value) => trim($value))
            ->take(-3)
            ->values();
        if ($history->isEmpty() || ! $this->looksReferential($normalized)) {
            return [
                'query' => $message,
                'follow_up' => false,
                'confidence' => 'none',
                'anchor' => null,
            ];
        }

        $currentTopics = $this->topics->topicKeys($normalized);
        $currentEntities = $this->facets->entities($normalized);
        if ($currentEntities !== []) {
            return [
                'query' => $message,
                'follow_up' => false,
                'confidence' => 'none',
                'anchor' => null,
            ];
        }

        $anchor = $history
            ->map(function (string $candidate, int $index) use ($currentTopics, $history): array {
                $normalizedCandidate = $this->normalize($candidate);
                $candidateTopics = $this->topics->topicKeys($normalizedCandidate);
                $candidateEntities = $this->facets->entities($normalizedCandidate);
                $candidateFacets = $this->facets->facets($normalizedCandidate);
                $score = ($candidateEntities !== [] ? 8 : 0)
                    + ($candidateTopics !== [] ? 4 : 0)
                    + ($candidateFacets !== [] ? 3 : 0)
                    + (preg_match('/\b(?:member|role|responsibilit(?:y|ies)|qualification|procedure|annex|document|peranan|tanggungjawab|kelayakan|prosedur)\b/u', $normalizedCandidate) === 1 ? 3 : 0)
                    + $index / max(1, $history->count());
                if ($currentTopics !== [] && array_intersect($currentTopics, $candidateTopics) === []) {
                    $score -= 5;
                }

                return compact('candidate', 'score', 'candidateTopics', 'candidateEntities');
            })
            ->sortByDesc('score')
            ->first(fn (array $candidate) => $candidate['score'] >= 4);

        if (! is_array($anchor)) {
            return [
                'query' => $message,
                'follow_up' => false,
                'confidence' => 'none',
                'anchor' => null,
            ];
        }

        $confidence = $anchor['candidateEntities'] !== [] ? 'high' : 'medium';

        return [
            'query' => $anchor['candidate']."\n".$message,
            'follow_up' => true,
            'confidence' => $confidence,
            'anchor' => $anchor['candidate'],
        ];
    }

    private function looksReferential(string $message): bool
    {
        if (Str::length($message) <= 120
            && preg_match('/\b(?:it|its|that|those|them|this|that role|this role|the role|previous|above|there|itu|tersebut|peranan itu|yang tadi)\b/u', $message) === 1) {
            return true;
        }

        return preg_match('/^(?:and|also|then|but|what about|how about|dan|juga|kemudian|tetapi|tapi|bagaimana pula)\b/u', $message) === 1;
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', Str::lower($value)));
    }
}
