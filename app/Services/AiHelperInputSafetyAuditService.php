<?php

namespace App\Services;

final class AiHelperInputSafetyAuditService
{
    public function __construct(private readonly AiHelperInputQualityAssessor $assessor) {}

    /** @return array<string, mixed> */
    public function audit(): array
    {
        $manifest = (array) config('ai_helper_input_safety');
        $errors = [];
        if (($manifest['version'] ?? null) !== 1) {
            $errors[] = 'Input-safety manifest version must be 1.';
        }

        $results = [];
        $ids = [];
        foreach ((array) ($manifest['cases'] ?? []) as $case) {
            $id = trim((string) ($case['id'] ?? ''));
            $message = trim((string) ($case['message'] ?? ''));
            if ($id === '' || $message === '') {
                $errors[] = 'Input-safety case is missing an id or message.';

                continue;
            }
            if (isset($ids[$id])) {
                $errors[] = "Duplicate input-safety case id: {$id}";
            }
            $ids[$id] = true;

            $assessment = $this->assessor->assess($message, (array) ($case['previous'] ?? []));
            $failures = [];
            if ($assessment->decision !== ($case['decision'] ?? null)) {
                $failures[] = 'decision:'.($case['decision'] ?? '');
            }
            if (array_key_exists('recoverable', $case)
                && $assessment->recoverable !== (bool) $case['recoverable']) {
                $failures[] = 'recoverable:'.((bool) $case['recoverable'] ? 'true' : 'false');
            }
            $results[] = [
                'id' => $id,
                'passed' => $failures === [],
                'failures' => $failures,
                'decision' => $assessment->decision,
                'reason_codes' => $assessment->reasonCodes,
                'recoverable' => $assessment->recoverable,
            ];
        }

        if ($results === []) {
            $errors[] = 'Input-safety case corpus is empty.';
        }
        $requiredDecisions = [
            AiHelperInputAssessment::ALLOW,
            AiHelperInputAssessment::CLARIFY,
            AiHelperInputAssessment::REPHRASE,
            AiHelperInputAssessment::REFUSE_SENSITIVE,
            AiHelperInputAssessment::REFUSE_EXFILTRATION,
            AiHelperInputAssessment::SEMANTIC_REVIEW,
        ];
        $coveredDecisions = array_values(array_unique(array_column($results, 'decision')));
        foreach (array_diff($requiredDecisions, $coveredDecisions) as $decision) {
            $errors[] = "Input decision has no audit case: {$decision}";
        }
        $failures = array_values(array_filter($results, fn (array $result): bool => ! $result['passed']));

        return [
            'manifest_version' => $manifest['version'] ?? null,
            'ready' => $errors === [] && $failures === [],
            'cases' => [
                'total' => count($results),
                'passed' => count($results) - count($failures),
                'failed' => count($failures),
            ],
            'decisions' => [
                'required' => $requiredDecisions,
                'covered' => $coveredDecisions,
                'missing' => array_values(array_diff($requiredDecisions, $coveredDecisions)),
            ],
            'errors' => array_values(array_unique($errors)),
            'failures' => $failures,
            'results' => $results,
        ];
    }
}
