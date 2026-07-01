<?php

namespace App\Services;

use App\Models\Setting;

class ReportingWorkflowService
{
    private const SETTINGS_KEY = 'reporting_workflow_rules';
    private const LEGACY_INSPECTION_SETTING_KEY = 'inspection_workflow_rules';

    private const REPORTING_MODULE_KEYS = [
        'inspection',
        'erco',
        'drill',
        'fitness-test',
    ];

    private const MODULE_DEFAULTS = [
        'inspection' => [
            'fallbackReviewRole' => 'Incident Commander',
            'reviewRole' => 'Assistant Incident Commander',
            'approveRole' => 'Incident Commander',
            'options' => [
                'useTeamScopedAic' => true,
                'allowSubmitWithoutTeam' => true,
                'allowIcFallbackReview' => true,
                'preventSelfReview' => true,
                'preventSelfApprove' => true,
            ],
        ],
        'erco' => [
            'fallbackReviewRole' => 'Incident Commander',
            'reviewRole' => 'Incident Commander',
            'approveRole' => 'Incident Commander',
            'options' => [
                'useTeamScopedAic' => true,
                'allowSubmitWithoutTeam' => true,
                'allowIcFallbackReview' => true,
                'preventSelfReview' => true,
                'preventSelfApprove' => true,
            ],
        ],
        'drill' => [
            'fallbackReviewRole' => 'Incident Commander',
            'reviewRole' => 'Incident Commander',
            'approveRole' => 'Incident Commander',
            'options' => [
                'useTeamScopedAic' => true,
                'allowSubmitWithoutTeam' => true,
                'allowIcFallbackReview' => true,
                'preventSelfReview' => true,
                'preventSelfApprove' => true,
            ],
        ],
        'fitness-test' => [
            'fallbackReviewRole' => 'Incident Commander',
            'reviewRole' => 'Incident Commander',
            'approveRole' => 'Incident Commander',
            'options' => [
                'useTeamScopedAic' => true,
                'allowSubmitWithoutTeam' => true,
                'allowIcFallbackReview' => true,
                'preventSelfReview' => true,
                'preventSelfApprove' => true,
            ],
        ],
    ];

    public function __construct(private readonly InspectionWorkflowService $inspectionWorkflowService)
    {
    }

    public function loadWorkflowRules(): array
    {
        $setting = Setting::query()->where('key', self::SETTINGS_KEY)->first();
        if (is_array($setting?->value ?? null)) {
            return $this->normalizeWorkflowRules($setting->value);
        }

        $legacySetting = Setting::query()->where('key', self::LEGACY_INSPECTION_SETTING_KEY)->first();
        if (is_array($legacySetting?->value ?? null)) {
            return $this->normalizeWorkflowRules([
                'modules' => [
                    'inspection' => $legacySetting->value,
                ],
            ]);
        }

        return $this->normalizeWorkflowRules([]);
    }

    public function saveWorkflowRules(array $payload): array
    {
        $normalized = $this->normalizeWorkflowRules($payload);
        Setting::query()->updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            ['value' => $normalized],
        );

        return $normalized;
    }

    public function normalizeWorkflowRules(mixed $value): array
    {
        $source = is_array($value) ? $value : [];
        $sourceModules = is_array($source['modules'] ?? null) ? $source['modules'] : [];

        if (empty($sourceModules) && (isset($source['fallback']) || isset($source['options']))) {
            $sourceModules = ['inspection' => $source];
        }

        $normalizedModules = [];
        foreach (self::REPORTING_MODULE_KEYS as $moduleKey) {
            $normalizedModules[$moduleKey] = $this->normalizeModuleRules(
                $moduleKey,
                is_array($sourceModules[$moduleKey] ?? null) ? $sourceModules[$moduleKey] : [],
            );
        }

        return [
            'modules' => $normalizedModules,
        ];
    }

    public function loadInspectionWorkflowRules(): array
    {
        return $this->loadWorkflowRules()['modules']['inspection']
            ?? $this->normalizeModuleRules('inspection', []);
    }

    private function normalizeModuleRules(string $moduleKey, array $source): array
    {
        $defaults = self::MODULE_DEFAULTS[$moduleKey] ?? self::MODULE_DEFAULTS['inspection'];
        $sourceRules = $this->extractModuleRuleShape($source);

        if ($moduleKey === 'inspection' && $source !== [] && isset($source['fallback'])) {
            $inspectionNormalized = $this->inspectionWorkflowService->normalizeWorkflowRules($source);
            $sourceRules = [
                'fallback' => $inspectionNormalized['fallback'] ?? [],
                'options' => $inspectionNormalized['options'] ?? [],
            ];
        }

        $fallbackSource = is_array($sourceRules['fallback'] ?? null) ? $sourceRules['fallback'] : [];
        $optionsSource = is_array($sourceRules['options'] ?? null) ? $sourceRules['options'] : [];

        return [
            'fallback' => [
                'reviewRole' => $this->normalizeRole(
                    $fallbackSource['reviewRole'] ?? $defaults['reviewRole'],
                    $defaults['reviewRole'],
                ),
                'fallbackReviewRole' => $this->normalizeRole(
                    $fallbackSource['fallbackReviewRole'] ?? $defaults['fallbackReviewRole'],
                    $defaults['fallbackReviewRole'],
                ),
                'approveRole' => $this->normalizeRole(
                    $fallbackSource['approveRole'] ?? $defaults['approveRole'],
                    $defaults['approveRole'],
                ),
            ],
            'options' => [
                'useTeamScopedAic' => ($optionsSource['useTeamScopedAic'] ?? true) !== false,
                'allowSubmitWithoutTeam' => ($optionsSource['allowSubmitWithoutTeam'] ?? true) !== false,
                'allowIcFallbackReview' => ($optionsSource['allowIcFallbackReview'] ?? true) !== false,
                'preventSelfReview' => ($optionsSource['preventSelfReview'] ?? true) !== false,
                'preventSelfApprove' => ($optionsSource['preventSelfApprove'] ?? true) !== false,
            ],
        ];
    }

    private function extractModuleRuleShape(array $source): array
    {
        if (isset($source['fallback'])) {
            return $source;
        }

        if (isset($source['rules'])) {
            return is_array($source['rules']) ? $source['rules'] : [];
        }

        return [];
    }

    private function normalizeRole(string $value, string $default): string
    {
        $value = trim($value);
        if ($value === '') {
            return $default;
        }

        if (in_array($value, RoleCatalog::ROLES, true)) {
            return $value;
        }

        return $default;
    }
}
