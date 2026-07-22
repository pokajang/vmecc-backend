<?php

namespace App\Services;

final class AiHelperUiStateNormalizer
{
    /** @return array<string, mixed> */
    public function normalize(array $rawState): array
    {
        return array_filter([
            'record_status' => $this->token($rawState['record_status'] ?? null),
            'current_step' => $this->token($rawState['current_step'] ?? null),
            'record_kind' => $this->token($rawState['record_kind'] ?? null),
            'selected_type' => $this->token($rawState['selected_type'] ?? null),
            'missing_fields' => $this->tokens($rawState['missing_fields'] ?? [], 12),
            'available_actions' => $this->tokens($rawState['available_actions'] ?? [], 8),
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    /** @return array<string, mixed> */
    public function forWorkflow(array $state, array $workflow): array
    {
        $ui = (array) ($workflow['ui'] ?? []);
        $stepMap = collect($workflow['steps'] ?? [])->keyBy('key');
        $actionMap = collect($ui['actions'] ?? []);
        $fieldMap = collect($ui['fields'] ?? []);
        $statusMap = collect($ui['statuses'] ?? []);

        return array_filter([
            'record_status' => $statusMap->get($state['record_status'] ?? ''),
            'current_step' => $stepMap->has($state['current_step'] ?? '') ? $state['current_step'] : null,
            'missing_fields' => collect($state['missing_fields'] ?? [])
                ->map(fn (string $key) => $fieldMap->get($key))
                ->filter()
                ->values()
                ->all(),
            'available_actions' => collect($state['available_actions'] ?? [])
                ->map(fn (string $key) => $actionMap->get($key))
                ->filter()
                ->values()
                ->all(),
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    private function token(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $value) === 1 ? $value : null;
    }

    /** @return array<int, string> */
    private function tokens(mixed $values, int $limit): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->map(fn ($value) => $this->token($value))
            ->filter()
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }
}
