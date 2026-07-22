<?php

namespace App\Services;

use App\Models\AiHelperKnowledgeEntry;
use App\Models\User;

final class AiHelperProductContextService
{
    public function __construct(
        private readonly AiHelperKnowledgeAudienceResolver $audiences,
        private readonly AiHelperSystemGuideCatalog $guides,
        private readonly AiHelperWorkflowRegistry $workflows,
        private readonly AiHelperWorkflowRenderer $workflowRenderer,
        private readonly AiHelperUiStateNormalizer $uiState,
    ) {}

    /** @return array<string, mixed>|null */
    public function forRequest(array $page, ?User $user, array $analysis, array $uiState = []): ?array
    {
        if (! $user) {
            return null;
        }

        $answerMode = (string) ($analysis['answer_mode'] ?? 'operational_knowledge');
        if (! in_array($answerMode, ['product_capability', 'product_navigation', 'product_workflow'], true)) {
            return null;
        }

        $audience = $this->audiences->resolve($user, $page);
        $context = [
            'authority' => 'live_product_registry',
            'answer_mode' => $answerMode,
            'current_page' => [
                'route_key' => $page['route_key'] ?? $audience->routeKey,
                'title' => $page['title'] ?? $page['route_name'] ?? 'Current page',
                'module_key' => $page['module_key'] ?? $audience->moduleKey,
            ],
        ];

        if ($answerMode === 'product_capability') {
            $context['capabilities'] = $this->visibleCapabilities($audience);
        }

        $workflowAnalysis = $this->analysisWithUiState($analysis, $uiState, $context['current_page']);
        $workflow = $this->workflowFor($workflowAnalysis, $audience);
        if ($workflow !== null) {
            $context['target'] = [
                'module' => $workflow['module'],
                'action' => $workflow['action'],
                'type' => $workflow['type'],
            ];
            $context['workflow'] = $workflow;
            $runtimeState = $this->uiState->forWorkflow($uiState, $workflow);
            if ($runtimeState !== []) {
                $context['ui_state'] = $runtimeState;
            }
        }

        if ($workflow === null) {
            $clarification = $this->clarificationFor($analysis, $audience);
            if ($clarification !== null) {
                $context['clarification'] = $clarification;
            }
        }

        if ($answerMode === 'product_navigation' && ! isset($context['workflow'])) {
            $context['page_help'] = $this->pageHelp($context['current_page']);
        }

        return $context;
    }

    public function deterministicResponse(?array $context, string $responseLanguage, string $message): ?string
    {
        if (! is_array($context)) {
            return null;
        }

        $malay = $this->useBahasaMelayu($responseLanguage, $message);
        if (isset($context['workflow'])) {
            return $this->workflowRenderer->render(
                $context['workflow'],
                $malay,
                (array) ($context['ui_state'] ?? []),
            );
        }

        if (($context['clarification'] ?? null) === 'inspection_or_physical_maintenance') {
            return $malay
                ? 'Untuk merekod pemeriksaan dalam VMECC, buka menu **Inspection**. Adakah anda mahu langkah merekod pemeriksaan dalam sistem atau prosedur penyelenggaraan fizikal peralatan?'
                : 'To record an inspection in VMECC, open the **Inspection** menu. Do you want the system inspection workflow or the physical equipment maintenance procedure?';
        }

        if (($context['answer_mode'] ?? null) === 'product_capability') {
            $capabilities = collect($context['capabilities'] ?? [])->values();
            if ($capabilities->isEmpty()) {
                return null;
            }
            $lines = $capabilities->map(function (array $capability, int $index): string {
                return sprintf('%d. **%s** - %s', $index + 1, $capability['label'], $capability['description']);
            });
            $intro = $malay
                ? 'VMECC menyediakan fungsi berikut berdasarkan akses akaun anda:'
                : 'VMECC provides the following capabilities based on your account access:';
            $closing = $malay
                ? 'Saya juga boleh terangkan langkah untuk mana-mana menu yang tersedia.'
                : 'I can also explain the steps for any available menu.';

            return $intro."\n\n".$lines->join("\n")."\n\n{$closing}";
        }

        $pageHelp = $context['page_help'] ?? null;
        if (is_array($pageHelp)) {
            if (($pageHelp['route_key'] ?? null) === 'dashboard') {
                return $malay
                    ? 'Dashboard memberikan ringkasan baca sahaja tentang rekod, kad ringkasan dan tindakan yang tersedia mengikut akses anda. Untuk menjalankan tugas tertentu, buka menu modul berkaitan.'
                    : 'The Dashboard gives you a read-only overview of records, summary cards, and available actions based on your access. To perform a task, open the relevant module menu.';
            }

            $label = (string) ($pageHelp['label'] ?? 'current page');
            $description = (string) ($pageHelp['description'] ?? '');

            return $malay
                ? "Menu **{$label}** digunakan untuk {$description}"
                : "The **{$label}** menu is used for {$description}";
        }

        return null;
    }

    /** @return array<int, array{key: string, label: string, description: string}> */
    private function visibleCapabilities(AiHelperKnowledgeAudience $audience): array
    {
        $moduleRegistry = collect(ModuleCatalog::registryPayload())->keyBy('key');

        return collect($this->guides->all())
            ->filter(fn (array $definition) => $this->allowsDefinition($definition, $audience))
            ->map(function (array $definition) use ($moduleRegistry): ?array {
                $module = $moduleRegistry->get($definition['module_key']);
                if (! is_array($module)) {
                    return null;
                }

                return [
                    'key' => (string) $module['key'],
                    'label' => (string) $module['label'],
                    'description' => (string) ($module['description'] ?? ''),
                ];
            })
            ->filter()
            ->unique('key')
            ->sortBy('label')
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function workflowFor(array $analysis, AiHelperKnowledgeAudience $audience): ?array
    {
        if (! (bool) config('ai_helper.product_workflows_enabled', false)) {
            return null;
        }

        return collect($this->workflows->candidatesFor($analysis))
            ->first(function (array $workflow) use ($audience): bool {
                $definition = $this->guides->definition((string) ($workflow['guide_key'] ?? ''));

                return is_array($definition) && $this->allowsDefinition($definition, $audience);
            });
    }

    /** @return array<string, mixed> */
    private function analysisWithUiState(array $analysis, array $uiState, array $currentPage): array
    {
        if (($analysis['answer_mode'] ?? null) !== 'product_navigation'
            || ! in_array($currentPage['module_key'] ?? null, ['inspection', 'reports.inspection'], true)
            || ! empty($analysis['task_keys'])) {
            return $analysis;
        }

        $entity = match ($uiState['selected_type'] ?? null) {
            'fire_truck_daily' => 'fire_truck',
            'fire_extinguisher' => 'extinguisher',
            'hse' => 'hse_inspection',
            'scba' => 'scba_inspection',
            'hydraulic_rescue' => 'hydraulic_rescue_inspection',
            default => null,
        };
        if ($entity === null) {
            return $analysis;
        }

        return [
            ...$analysis,
            'task_keys' => ['inspection.conduct'],
            'entity_keys' => [$entity],
        ];
    }

    private function clarificationFor(array $analysis, AiHelperKnowledgeAudience $audience): ?string
    {
        $tasks = collect($analysis['task_keys'] ?? []);
        if (! $tasks->contains('inspection.conduct') || ! $tasks->contains('inspection.physical.maintain')) {
            return null;
        }
        $definition = $this->guides->definition('inspection-manage');

        return is_array($definition) && $this->allowsDefinition($definition, $audience)
            ? 'inspection_or_physical_maintenance'
            : null;
    }

    private function allowsDefinition(array $definition, AiHelperKnowledgeAudience $audience): bool
    {
        if (! ($audience->moduleStates[$definition['module_gate']] ?? false)
            || ! $this->audiences->matchesPermissions(
                $definition['permissions'] ?? [],
                $definition['permission_match'] ?? AiHelperKnowledgeEntry::PERMISSION_MATCH_ANY,
                $audience,
            )) {
            return false;
        }

        $roles = collect($definition['roles'] ?? [])->filter();

        return $audience->systemAdministrator
            || $roles->isEmpty()
            || $roles->contains(fn (string $role) => in_array($role, $audience->roleNames, true));
    }

    /** @return array{route_key: string|null, label: string, description: string} */
    private function pageHelp(array $currentPage): array
    {
        $moduleKey = (string) ($currentPage['module_key'] ?? '');
        $module = collect(ModuleCatalog::registryPayload())->firstWhere('key', $moduleKey);

        return [
            'route_key' => $currentPage['route_key'] ?? null,
            'label' => (string) ($module['label'] ?? $currentPage['title'] ?? 'Current page'),
            'description' => (string) ($module['description'] ?? 'viewing the information available to your account.'),
        ];
    }

    private function useBahasaMelayu(string $responseLanguage, string $message): bool
    {
        if ($responseLanguage === 'bm') {
            return true;
        }
        if ($responseLanguage === 'en') {
            return false;
        }

        return preg_match('/\b(?:apa|apakah|macam|mana|nak|boleh|buat|sistem|menu|pemeriksaan|lori|saya|ni|ini)\b/iu', $message) === 1;
    }
}
