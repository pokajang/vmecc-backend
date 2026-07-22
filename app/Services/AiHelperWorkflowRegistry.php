<?php

namespace App\Services;

use App\Services\AiHelperWorkflows\AdministrationWorkflows;
use App\Services\AiHelperWorkflows\InspectionWorkflows;
use App\Services\AiHelperWorkflows\OperationsWorkflows;
use App\Services\AiHelperWorkflows\SelfServiceWorkflows;

final class AiHelperWorkflowRegistry
{
    public function __construct(private readonly AiHelperSystemGuideCatalog $guides) {}

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return collect(array_merge(
            InspectionWorkflows::definitions(),
            SelfServiceWorkflows::definitions(),
            OperationsWorkflows::definitions(),
            AdministrationWorkflows::definitions(),
        ))->map(fn (array $workflow) => ['fact_scope' => 'navigation', ...$workflow])->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function candidatesFor(array $analysis): array
    {
        $tasks = collect($analysis['task_keys'] ?? [])->filter();
        if ($tasks->isEmpty()) {
            return [];
        }

        $entities = collect($analysis['entity_keys'] ?? [])->filter();

        return collect($this->all())
            ->filter(fn (array $workflow) => ($workflow['fact_scope'] ?? null) === 'navigation')
            ->filter(function (array $workflow) use ($tasks): bool {
                $workflowTasks = collect($workflow['task_keys'] ?? []);

                return $tasks->diff($workflowTasks)->isEmpty();
            })
            ->filter(function (array $workflow) use ($entities): bool {
                $workflowEntities = collect($workflow['entity_keys'] ?? [])->filter();
                if ($entities->isEmpty()) {
                    return $workflowEntities->isEmpty();
                }

                return $workflowEntities->isEmpty() || $entities->intersect($workflowEntities)->isNotEmpty();
            })
            ->sortByDesc(fn (array $workflow) => count($workflow['entity_keys'] ?? []))
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public function validationErrors(): array
    {
        $errors = [];
        $keys = [];
        foreach ($this->all() as $workflow) {
            $key = trim((string) ($workflow['key'] ?? ''));
            if ($key === '' || isset($keys[$key])) {
                $errors[] = $key === '' ? 'Workflow key is missing.' : "Duplicate workflow key: {$key}";
            }
            $keys[$key] = true;
            if (empty($workflow['guide_key']) || empty($workflow['task_keys']) || empty($workflow['steps'])) {
                $errors[] = "Workflow {$key} is incomplete.";
            }
            if (($workflow['fact_scope'] ?? null) !== 'navigation') {
                $errors[] = "Workflow {$key} must remain navigation-only.";
            }
            $guideKey = (string) ($workflow['guide_key'] ?? '');
            if ($this->guides->definition($guideKey) === null) {
                $errors[] = "Workflow {$key} references an unknown system guide.";
            } elseif (collect($workflow['task_keys'] ?? [])->diff($this->guides->tasksForGuideKey($guideKey))->isNotEmpty()) {
                $errors[] = "Workflow {$key} does not match its system-guide task metadata.";
            }
            $sourceLabels = collect($workflow['source_labels'] ?? [])->filter();
            if ($sourceLabels->isEmpty()) {
                $errors[] = "Workflow {$key} does not declare source labels.";
            } else {
                $sourcePath = database_path("ai-helper-system-guides/{$guideKey}.md");
                if (! is_file($sourcePath)) {
                    $errors[] = "Workflow {$key} source guide is missing.";
                } else {
                    $source = mb_strtolower((string) file_get_contents($sourcePath));
                    foreach ($sourceLabels as $label) {
                        if (! str_contains($source, mb_strtolower((string) $label))) {
                            $errors[] = "Workflow {$key} label is absent from its source guide: {$label}";
                        }
                    }
                }
            }
            foreach ($workflow['steps'] ?? [] as $step) {
                if (empty($step['key']) || empty($step['kind']) || (empty($step['target']) && empty($step['targets']))) {
                    $errors[] = "Workflow {$key} has an incomplete step.";
                }
            }
        }

        return $errors;
    }
}
