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
        if (! in_array($answerMode, ['product_capability', 'product_navigation', 'product_workflow', 'product_clarification'], true)) {
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
            $workflow['requested_operations'] = array_values((array) ($analysis['operation_keys'] ?? []));
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

        if (is_array($context['clarification'] ?? null)) {
            return $this->renderClarification($context['clarification'], $malay);
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

    /** @return array{reason: string, options: array<int, array{key: string, label: string}>}|null */
    private function clarificationFor(array $analysis, AiHelperKnowledgeAudience $audience): ?array
    {
        if (! ($analysis['clarification_required'] ?? false)) {
            return null;
        }

        $reason = (string) ($analysis['clarification_reason'] ?? '');
        $options = collect($analysis['clarification_option_keys'] ?? [])
            ->map(function (string $key) use ($analysis, $reason, $audience): ?array {
                $option = $this->clarificationOption(
                    $reason,
                    $key,
                    (array) ($analysis['operation_keys'] ?? []),
                );
                if ($option === null) {
                    return null;
                }
                $guideKey = $option['guide_key'] ?? null;
                if ($guideKey !== null) {
                    $definition = $this->guides->definition($guideKey);
                    if (! is_array($definition) || ! $this->allowsDefinition($definition, $audience)) {
                        return null;
                    }
                }

                return ['key' => $key, 'label' => $option['label']];
            })
            ->filter()
            ->values()
            ->all();

        return $reason === '' ? null : ['reason' => $reason, 'options' => $options];
    }

    /** @return array{label: string, guide_key?: string}|null */
    private function clarificationOption(string $reason, string $key, array $operationKeys): ?array
    {
        return match ($reason.'.'.$key) {
            'missing_report_type.erco' => ['label' => 'ERCO', 'guide_key' => 'erco-reports'],
            'missing_report_type.drill' => ['label' => 'Drill', 'guide_key' => 'drill-reports'],
            'missing_report_type.fitness' => ['label' => 'Fitness Test', 'guide_key' => 'fitness-reports'],
            'missing_report_type.inspection' => [
                'label' => 'Inspection',
                'guide_key' => array_intersect($operationKeys, ['edit', 'submit']) !== []
                    ? 'inspection-manage'
                    : 'inspection-view',
            ],
            'ambiguous_action.view' => ['label' => 'View or check the report', 'guide_key' => 'reports-navigation'],
            'ambiguous_action.review' => ['label' => 'Perform a formal review', 'guide_key' => 'report-management'],
            'compound_request.inspection_workflow' => ['label' => 'System inspection workflow', 'guide_key' => 'inspection-manage'],
            'compound_request.physical_maintenance' => ['label' => 'Physical equipment maintenance'],
            default => null,
        };
    }

    private function renderClarification(array $clarification, bool $malay): string
    {
        $reason = (string) ($clarification['reason'] ?? '');
        $rawOptions = collect($clarification['options'] ?? [])->values();
        $options = $rawOptions
            ->pluck('label')
            ->filter()
            ->map(fn (string $label): string => "**{$label}**")
            ->values();
        $optionText = $options->isEmpty() ? '' : $options->join($malay ? ' atau ' : ' or ');

        return match ($reason) {
            'missing_report_type' => $options->isEmpty()
                ? ($malay
                    ? 'Saya tidak menemui jenis laporan yang boleh diakses oleh akaun anda. Minta pentadbir menyemak akses laporan anda.'
                    : 'I could not find a report type available to your account. Ask an administrator to check your report access.')
                : ($malay
                    ? "Jenis laporan menentukan langkah yang betul. Adakah anda maksudkan {$optionText}?"
                    : "The report type determines the correct steps. Do you mean {$optionText}?"),
            'ambiguous_action' => $options->isEmpty()
                ? ($malay
                    ? 'Saya tidak menemui tindakan laporan yang tersedia untuk akaun anda. Minta pentadbir menyemak akses laporan anda.'
                    : 'I could not find a report action available to your account. Ask an administrator to check your report access.')
                : ($malay
                    ? "Tindakan manakah yang anda maksudkan: {$optionText}?"
                    : "Which action do you mean: {$optionText}?"),
            'missing_record_context' => $malay
                ? 'Rekod manakah yang anda maksudkan? Beritahu jenis rekod atau buka rekod tersebut supaya saya boleh beri langkah yang betul.'
                : 'Which record do you mean? Name the record type or open that record so I can give the correct steps.',
            'unsupported_action' => $malay
                ? 'Tindakan itu tidak didokumenkan sebagai aliran kerja yang disokong. Beritahu jenis rekod dan status semasa supaya saya boleh terangkan tindakan yang tersedia.'
                : 'That action is not documented as a supported workflow. Tell me the record type and current status so I can explain the available actions.',
            'compound_request' => $this->renderCompoundClarification($rawOptions, $malay),
            default => $malay
                ? 'Sila berikan sedikit lagi maklumat supaya saya boleh beri langkah yang betul.'
                : 'Please provide a little more information so I can give the correct steps.',
        };
    }

    private function renderCompoundClarification($options, bool $malay): string
    {
        $keys = $options->pluck('key');
        $inspection = $keys->contains('inspection_workflow');
        $maintenance = $keys->contains('physical_maintenance');

        if ($inspection && $maintenance) {
            return $malay
                ? 'Untuk merekod pemeriksaan dalam VMECC, buka menu **Inspection**. Adakah anda mahu langkah merekod pemeriksaan dalam sistem atau prosedur penyelenggaraan fizikal peralatan?'
                : 'To record an inspection in VMECC, open the **Inspection** menu. Do you want the system inspection workflow or the physical equipment maintenance procedure?';
        }
        if ($inspection) {
            return $malay
                ? 'Akaun anda mempunyai akses kepada aliran kerja pemeriksaan sistem. Adakah anda mahu langkah merekod pemeriksaan dalam menu **Inspection**?'
                : 'Your account has access to the system inspection workflow. Do you want the steps for recording an inspection in the **Inspection** menu?';
        }
        if ($maintenance) {
            return $malay
                ? 'Akaun anda tidak mempunyai akses kepada aliran kerja pemeriksaan sistem. Adakah anda maksudkan prosedur penyelenggaraan fizikal peralatan?'
                : 'Your account does not have access to the system inspection workflow. Do you mean the physical equipment maintenance procedure?';
        }

        return $malay
            ? 'Saya tidak menemui pilihan pemeriksaan atau penyelenggaraan yang tersedia untuk akaun anda.'
            : 'I could not find an inspection or maintenance option available to your account.';
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
