<?php

namespace App\Services;

final readonly class AiHelperInputAssessment
{
    public const ALLOW = 'allow';

    public const CLARIFY = 'clarify';

    public const REPHRASE = 'rephrase';

    public const REFUSE_SENSITIVE = 'refuse_sensitive';

    public const REFUSE_EXFILTRATION = 'refuse_exfiltration';

    public const SEMANTIC_REVIEW = 'semantic_review';

    /** @param array<int, string> $reasonCodes @param array<int, string> $recognizedTopics */
    public function __construct(
        public string $decision,
        public array $reasonCodes,
        public float $confidence,
        public array $recognizedTopics = [],
        public bool $recoverable = false,
        public bool $semanticFallbackUsed = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'decision' => $this->decision,
            'reason_codes' => $this->reasonCodes,
            'confidence' => $this->confidence,
            'recognized_topics' => $this->recognizedTopics,
            'recoverable' => $this->recoverable,
            'semantic_fallback_used' => $this->semanticFallbackUsed,
        ];
    }
}
