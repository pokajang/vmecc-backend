<?php

namespace App\Services;

use Throwable;

final class AiHelperInputSemanticClassifier
{
    public function __construct(private readonly AiHelperOpenAiService $openAi) {}

    public function classify(
        string $message,
        string $safetyIdentifier,
        AiHelperRequestDeadline $deadline,
    ): AiHelperInputAssessment {
        if (! $this->openAi->isAvailable()) {
            return $this->fallback();
        }

        try {
            $result = $this->openAi->structuredResponse(
                (string) config('ai_helper.model'),
                'Classify whether a short user message is meaningful enough for a workplace assistant. '
                .'Choose allow for understandable conversation, clarify for incomplete but potentially meaningful text, '
                .'or rephrase only for meaningless text. Never infer or answer the user request.',
                [['role' => 'user', 'content' => $message]],
                'vmecc_input_quality',
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['decision'],
                    'properties' => [
                        'decision' => [
                            'type' => 'string',
                            'enum' => ['allow', 'clarify', 'rephrase'],
                        ],
                    ],
                ],
                5,
                $deadline,
                $safetyIdentifier,
            );
            $decision = (string) data_get($result, 'data.decision', 'clarify');

            return new AiHelperInputAssessment(
                $decision,
                ['semantic_query_quality'],
                0.75,
                semanticFallbackUsed: true,
                recoverable: $decision !== AiHelperInputAssessment::REPHRASE,
            );
        } catch (Throwable) {
            return $this->fallback();
        }
    }

    private function fallback(): AiHelperInputAssessment
    {
        return new AiHelperInputAssessment(
            AiHelperInputAssessment::CLARIFY,
            ['semantic_classifier_unavailable'],
            0.4,
            recoverable: true,
            semanticFallbackUsed: true,
        );
    }
}
