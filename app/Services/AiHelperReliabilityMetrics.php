<?php

namespace App\Services;

use App\Models\AiHelperMessage;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class AiHelperReliabilityMetrics
{
    /** @return array<string, int|float|null> */
    public function recent(int $limit = 500): array
    {
        if (! Schema::hasColumn('ai_helper_messages', 'retrieval_metadata')) {
            return $this->emptyMetrics();
        }

        $messages = AiHelperMessage::query()
            ->where('role', AiHelperMessage::ROLE_ASSISTANT)
            ->where('status', AiHelperMessage::STATUS_COMPLETED)
            ->whereNotNull('retrieval_metadata')
            ->latest('id')
            ->limit(max(1, min(2000, $limit)))
            ->get(['retrieval_metadata']);
        $total = $messages->count();
        $count = fn (callable $predicate) => $messages->filter(
            fn (AiHelperMessage $message) => $predicate($message->retrieval_metadata ?? [])
        )->count();
        $durations = $messages
            ->map(fn (AiHelperMessage $message) => (int) Arr::get(
                $message->retrieval_metadata ?? [],
                'response_timings_ms.total',
                0,
            ))
            ->filter(fn (int $duration) => $duration > 0)
            ->sort()
            ->values();

        return [
            'sample_size' => $total,
            'verified' => $count(fn (array $metadata) => Arr::get($metadata, 'verification.status') === 'verified'),
            'repaired' => $count(fn (array $metadata) => (int) Arr::get($metadata, 'verification.attempts', 0) > 1
                && Arr::get($metadata, 'verification.status') === 'verified'),
            'rejected' => $count(fn (array $metadata) => Arr::get($metadata, 'verification.status') === 'rejected'),
            'semantic_fallbacks' => $count(fn (array $metadata) => (bool) ($metadata['semantic_fallback'] ?? false)),
            'rerank_fallbacks' => $count(fn (array $metadata) => (bool) Arr::get($metadata, 'rerank.fallback', false)),
            'grounding_shadow_failures' => $count(fn (array $metadata) => Arr::get(
                $metadata,
                'verification.grounding_verification.would_pass',
            ) === false),
            'verification_pass_rate' => $total > 0
                ? round($count(fn (array $metadata) => Arr::get($metadata, 'verification.status') === 'verified') / $total, 4)
                : null,
            'median_response_ms' => $this->percentile($durations->all(), 0.5),
            'p95_response_ms' => $this->percentile($durations->all(), 0.95),
        ];
    }

    /** @param array<int, int> $sorted */
    private function percentile(array $sorted, float $percentile): ?int
    {
        if ($sorted === []) {
            return null;
        }

        $index = max(0, min(count($sorted) - 1, (int) ceil(count($sorted) * $percentile) - 1));

        return $sorted[$index];
    }

    /** @return array<string, int|float|null> */
    private function emptyMetrics(): array
    {
        return [
            'sample_size' => 0,
            'verified' => 0,
            'repaired' => 0,
            'rejected' => 0,
            'semantic_fallbacks' => 0,
            'rerank_fallbacks' => 0,
            'grounding_shadow_failures' => 0,
            'verification_pass_rate' => null,
            'median_response_ms' => null,
            'p95_response_ms' => null,
        ];
    }
}
