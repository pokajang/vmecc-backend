<?php

namespace App\Services;

use Illuminate\Support\Str;

final class AiHelperAnswerQualityAuditService
{
    public function __construct(
        private readonly AiHelperKnowledgeQueryAnalyzer $analyzer,
        private readonly AiHelperWorkflowRegistry $workflows,
        private readonly AiHelperWorkflowRenderer $renderer,
        private readonly AiHelperUiStateNormalizer $uiState,
        private readonly AiHelperSystemGuideCatalog $guides,
    ) {}

    /** @return array<string, mixed> */
    /** @param array<int, string> $selectedIds */
    public function audit(array $selectedIds = []): array
    {
        $manifest = (array) config('ai_helper_answer_quality');
        $errors = [];
        $selectedIds = collect($selectedIds)
            ->map(fn (string $id): string => trim($id))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $partial = $selectedIds !== [];
        if (($manifest['version'] ?? null) !== 1) {
            $errors[] = 'Answer-quality manifest version must be 1.';
        }

        $configuredCases = collect((array) ($manifest['cases'] ?? []));
        $configuredIds = $configuredCases->pluck('id')->filter()->all();
        foreach (array_diff($selectedIds, $configuredIds) as $unknownId) {
            $errors[] = "Unknown answer-quality case id: {$unknownId}";
        }

        $results = [];
        $ids = [];
        $coveredWorkflows = [];
        foreach ($configuredCases
            ->when($partial, fn ($cases) => $cases->whereIn('id', $selectedIds))
            ->all() as $case) {
            $id = trim((string) ($case['id'] ?? ''));
            $message = trim((string) ($case['message'] ?? ''));
            if ($id === '' || $message === '') {
                $errors[] = 'Answer-quality case is missing an id or message.';

                continue;
            }
            if (isset($ids[$id])) {
                $errors[] = "Duplicate answer-quality case id: {$id}";
            }
            $ids[$id] = true;

            $analysis = $this->analyzer->analyze($message);
            $failures = [];
            $expectedClarification = $case['expected_clarification'] ?? null;
            if ($expectedClarification !== null) {
                $this->expect(
                    ($analysis['clarification_required'] ?? false) === true,
                    'clarification_required',
                    $failures,
                );
                $this->expect(
                    ($analysis['clarification_reason'] ?? null) === $expectedClarification,
                    'clarification:'.$expectedClarification,
                    $failures,
                );
                $this->expect(
                    ($analysis['answer_mode'] ?? null) === 'product_clarification',
                    'answer_mode:product_clarification',
                    $failures,
                );
                $expectedOptions = array_values((array) ($case['expected_options'] ?? []));
                $this->expect(
                    ($analysis['clarification_option_keys'] ?? []) === $expectedOptions,
                    'clarification_options:'.implode(',', $expectedOptions),
                    $failures,
                );
                $this->expect(($analysis['task_keys'] ?? []) === [], 'clarification_has_task', $failures);
                $results[] = $this->result($case, $analysis, null, null, $failures);

                continue;
            }

            $expectedTask = (string) ($case['expected_task'] ?? '');
            $expectedWorkflow = (string) ($case['expected_workflow'] ?? '');
            $expectedGuide = (string) ($case['expected_guide'] ?? '');
            $this->expect(
                in_array($expectedTask, $analysis['task_keys'] ?? [], true),
                'task:'.$expectedTask,
                $failures,
            );
            $workflow = collect($this->workflows->candidatesFor($analysis))->firstWhere('key', $expectedWorkflow);
            $this->expect(is_array($workflow), 'workflow:'.$expectedWorkflow, $failures);
            if (! is_array($workflow)) {
                $results[] = $this->result($case, $analysis, null, null, $failures);

                continue;
            }

            $coveredWorkflows[] = $expectedWorkflow;
            $this->expect(($workflow['guide_key'] ?? null) === $expectedGuide, 'guide:'.$expectedGuide, $failures);
            $definition = $this->guides->definition($expectedGuide);
            $this->expect(is_array($definition), 'unknown_guide:'.$expectedGuide, $failures);
            if (is_array($definition)) {
                $this->expect(
                    in_array($expectedTask, $definition['tasks'] ?? [], true),
                    'guide_task:'.$expectedTask,
                    $failures,
                );
                $this->expect(
                    ($definition['permissions'] ?? []) !== [],
                    'guide_has_no_permission_boundary',
                    $failures,
                );
            }

            $workflow['requested_operations'] = array_values((array) ($analysis['operation_keys'] ?? []));
            $runtimeState = $this->uiState->forWorkflow(
                $this->uiState->normalize((array) ($case['ui_state'] ?? [])),
                $workflow,
            );
            $answer = $this->renderer->render(
                $workflow,
                ($case['language'] ?? 'en') === 'bm',
                $runtimeState,
            );
            $comparable = Str::lower($answer);
            foreach ((array) ($case['required'] ?? []) as $required) {
                $this->expect(
                    Str::contains($comparable, Str::lower((string) $required)),
                    'answer_required:'.$required,
                    $failures,
                );
            }
            foreach ((array) ($case['forbidden'] ?? []) as $forbidden) {
                $this->expect(
                    ! Str::contains($comparable, Str::lower((string) $forbidden)),
                    'answer_forbidden:'.$forbidden,
                    $failures,
                );
            }

            $results[] = $this->result($case, $analysis, $workflow, $answer, $failures);
        }

        if ($results === []) {
            $errors[] = 'Answer-quality case corpus is empty.';
        }

        $registryWorkflowKeys = collect($this->workflows->all())->pluck('key')->values()->all();
        $missingWorkflows = array_values(array_diff($registryWorkflowKeys, array_unique($coveredWorkflows)));
        if (! $partial) {
            foreach ($missingWorkflows as $workflowKey) {
                $errors[] = "Workflow has no answer-quality case: {$workflowKey}";
            }
        }
        $failed = array_values(array_filter($results, fn (array $result): bool => ! $result['passed']));
        $errors = array_values(array_unique([...$errors, ...$this->workflows->validationErrors()]));
        $ready = $errors === [] && $failed === [];

        return [
            'manifest_version' => $manifest['version'] ?? null,
            'scope' => $partial ? 'selected' : 'full',
            'ready' => $ready,
            'cases' => [
                'total' => count($results),
                'passed' => count($results) - count($failed),
                'failed' => count($failed),
            ],
            'workflows' => [
                'registry' => count($registryWorkflowKeys),
                'covered' => count(array_unique($coveredWorkflows)),
                'missing' => $partial ? [] : $missingWorkflows,
            ],
            'errors' => $errors,
            'failures' => $failed,
            'results' => $results,
        ];
    }

    private function expect(bool $condition, string $failure, array &$failures): void
    {
        if (! $condition) {
            $failures[] = $failure;
        }
    }

    /** @return array<string, mixed> */
    private function result(
        array $case,
        array $analysis,
        ?array $workflow,
        ?string $answer,
        array $failures,
    ): array {
        return [
            'id' => $case['id'],
            'passed' => $failures === [],
            'failures' => $failures,
            'task_keys' => $analysis['task_keys'] ?? [],
            'workflow_key' => $workflow['key'] ?? null,
            'guide_key' => $workflow['guide_key'] ?? null,
            'clarification_reason' => $analysis['clarification_reason'] ?? null,
            'answer_preview' => $answer === null ? null : Str::limit($answer, 400, ''),
        ];
    }
}
