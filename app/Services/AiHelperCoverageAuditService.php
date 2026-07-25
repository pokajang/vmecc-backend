<?php

namespace App\Services;

final class AiHelperCoverageAuditService
{
    public function __construct(
        private readonly AiHelperKnowledgeQueryAnalyzer $analyzer,
        private readonly AiHelperSystemGuideCatalog $guides,
        private readonly AiHelperWorkflowRegistry $workflows,
        private readonly AiHelperTopicAliasRegistry $topics,
    ) {}

    /** @return array<string, mixed> */
    public function audit(): array
    {
        $manifest = (array) config('ai_helper_coverage');
        $errors = [];
        $classifications = array_keys((array) ($manifest['modules'] ?? []));
        $expectedClassifications = [
            'deterministic_workflow',
            'grounded_guidance',
            'product_navigation',
            'clarification_required',
            'intentionally_unsupported',
        ];

        if (($manifest['version'] ?? null) !== 1) {
            $errors[] = 'Coverage manifest version must be 1.';
        }
        if ($classifications !== $expectedClassifications) {
            $errors[] = 'Coverage classifications are missing, unknown, or out of order.';
        }

        $moduleAssignments = [];
        foreach ((array) ($manifest['modules'] ?? []) as $classification => $moduleKeys) {
            foreach ((array) $moduleKeys as $moduleKey) {
                $moduleAssignments[$moduleKey][] = $classification;
            }
        }

        $catalogKeys = ModuleCatalog::keys();
        $manifestKeys = array_keys($moduleAssignments);
        $missingModules = array_values(array_diff($catalogKeys, $manifestKeys));
        $unknownModules = array_values(array_diff($manifestKeys, $catalogKeys));
        $duplicateModules = array_keys(array_filter(
            $moduleAssignments,
            static fn (array $assignments): bool => count($assignments) !== 1,
        ));
        foreach ($missingModules as $moduleKey) {
            $errors[] = "Unclassified module: {$moduleKey}";
        }
        foreach ($unknownModules as $moduleKey) {
            $errors[] = "Unknown module in coverage manifest: {$moduleKey}";
        }
        foreach ($duplicateModules as $moduleKey) {
            $errors[] = "Module has multiple coverage classifications: {$moduleKey}";
        }
        $errors = [...$errors, ...ModuleCatalog::validateRegistry()];

        $guideKeys = $this->guides->keys();
        foreach ($guideKeys as $guideKey) {
            $definition = $this->guides->definition($guideKey) ?? [];
            $moduleKey = (string) ($definition['module_key'] ?? '');
            if (! isset($moduleAssignments[$moduleKey])) {
                $errors[] = "System guide {$guideKey} has no classified module: {$moduleKey}";
            }
        }
        $errors = [...$errors, ...$this->guides->validateRegistry(), ...$this->workflows->validationErrors()];

        $workflowDefinitions = $this->workflows->all();
        $workflowKeys = collect($workflowDefinitions)->pluck('key')->filter()->values()->all();
        $queryResults = [];
        $queryIds = [];
        $expectedQueryTopics = [];
        foreach ((array) ($manifest['queries'] ?? []) as $query) {
            $id = trim((string) ($query['id'] ?? ''));
            $moduleKey = trim((string) ($query['module'] ?? ''));
            $message = trim((string) ($query['message'] ?? ''));
            if ($id === '') {
                $errors[] = 'Coverage query is missing an id.';

                continue;
            }
            if (isset($queryIds[$id])) {
                $errors[] = "Duplicate coverage query id: {$id}";
            }
            $queryIds[$id] = true;
            if (! isset($moduleAssignments[$moduleKey])) {
                $errors[] = "Coverage query {$id} references an unclassified module: {$moduleKey}";
            }
            if ($message === '') {
                $errors[] = "Coverage query {$id} has an empty message.";

                continue;
            }

            $analysis = $this->analyzer->analyze($message);
            $expected = [
                'topics' => array_values((array) ($query['topics'] ?? [])),
                'operations' => array_values((array) ($query['operations'] ?? [])),
                'tasks' => array_values((array) ($query['tasks'] ?? [])),
            ];
            $expectedQueryTopics = [...$expectedQueryTopics, ...$expected['topics']];
            $detected = [
                'topics' => array_values((array) ($analysis['topic_keys'] ?? [])),
                'operations' => array_values((array) ($analysis['operation_keys'] ?? [])),
                'tasks' => array_values((array) ($analysis['task_keys'] ?? [])),
            ];
            $missing = [];
            $unexpected = [];
            foreach ($expected as $dimension => $values) {
                $difference = array_values(array_diff($values, $detected[$dimension]));
                if ($difference !== []) {
                    $missing[$dimension] = $difference;
                }
            }
            $unexpectedTasks = array_values(array_diff($detected['tasks'], $expected['tasks']));
            if ($unexpectedTasks !== []) {
                $unexpected['tasks'] = $unexpectedTasks;
            }
            $queryResults[] = [
                'id' => $id,
                'module' => $moduleKey,
                'classification' => $moduleAssignments[$moduleKey][0] ?? null,
                'message' => $message,
                'matched' => $missing === [] && $unexpected === [],
                'missing' => $missing,
                'unexpected' => $unexpected,
                'expected' => $expected,
                'detected' => $detected,
                'answer_mode' => $analysis['answer_mode'] ?? null,
            ];
        }

        if ($queryResults === []) {
            $errors[] = 'Coverage query corpus is empty.';
        }

        $registryTopics = $this->topics->keys();
        $coveredTopics = array_values(array_unique($expectedQueryTopics));
        $missingTopics = array_values(array_diff($registryTopics, $coveredTopics));
        $unknownTopics = array_values(array_diff($coveredTopics, $registryTopics));
        foreach ($missingTopics as $topicKey) {
            $errors[] = "Topic has no representative coverage query: {$topicKey}";
        }
        foreach ($unknownTopics as $topicKey) {
            $errors[] = "Coverage query references an unknown topic: {$topicKey}";
        }

        $errors = array_values(array_unique($errors));
        $gaps = array_values(array_filter(
            $queryResults,
            static fn (array $result): bool => ! $result['matched'],
        ));
        $phaseOneReady = $errors === [];

        return [
            'manifest_version' => $manifest['version'] ?? null,
            'phase_1_ready' => $phaseOneReady,
            'phase_2_ready' => $phaseOneReady && $gaps === [],
            'phase_2_required' => $phaseOneReady && $gaps !== [],
            'modules' => [
                'catalog' => count($catalogKeys),
                'classified' => count($manifestKeys),
                'missing' => $missingModules,
                'unknown' => $unknownModules,
                'duplicates' => $duplicateModules,
                'by_classification' => array_map('count', (array) ($manifest['modules'] ?? [])),
            ],
            'guides' => count($guideKeys),
            'workflows' => count($workflowKeys),
            'topics' => [
                'registry' => count($registryTopics),
                'covered' => count($coveredTopics),
                'missing' => $missingTopics,
                'unknown' => $unknownTopics,
            ],
            'queries' => [
                'total' => count($queryResults),
                'matched' => count($queryResults) - count($gaps),
                'gaps' => count($gaps),
            ],
            'errors' => $errors,
            'gap_details' => $gaps,
            'query_results' => $queryResults,
        ];
    }
}
