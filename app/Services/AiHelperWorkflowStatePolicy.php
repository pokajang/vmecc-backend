<?php

namespace App\Services;

final class AiHelperWorkflowStatePolicy
{
    /** @return array<int, array<string, mixed>> */
    public function stepsFor(array $workflow, array $uiState): array
    {
        $steps = array_values($workflow['steps'] ?? []);
        $key = (string) ($workflow['key'] ?? '');
        $status = (string) ($uiState['record_status'] ?? '');
        $operation = $this->requestedOperation($workflow);

        if (str_starts_with($key, 'reports.') && str_ends_with($key, '.manage')) {
            if ($status !== '' && $status !== 'Draft') {
                return $this->existingRecordSteps($steps, $status === 'Approved' && $operation === 'download');
            }

            return $this->reportAuthoringSteps($steps, $operation);
        }

        if ($key === 'reports.review' && in_array($status, ['Approved', 'Rejected'], true)) {
            return array_values(array_filter(
                $steps,
                static fn (array $step): bool => ($step['key'] ?? null) !== 'choose_action',
            ));
        }

        return $steps;
    }

    public function stateMessage(array $workflow, array $uiState, bool $malay): ?string
    {
        $status = (string) ($uiState['record_status'] ?? '');
        $operation = $this->requestedOperation($workflow);
        if ($status === '' || $operation === null) {
            return null;
        }

        $allowed = $this->allowedOperations((string) ($workflow['key'] ?? ''), $status);
        if ($allowed === null || in_array($operation, $allowed, true)) {
            return null;
        }

        $available = collect($uiState['available_actions'] ?? [])->filter()->values();
        $availableText = $available->isEmpty()
            ? ($malay ? 'Gunakan hanya tindakan yang dipaparkan pada rekod.' : 'Use only the actions displayed on the record.')
            : ($malay
                ? 'Tindakan yang tersedia: **'.$available->join('**, **').'**.'
                : 'Available actions: **'.$available->join('**, **').'**.');

        return $malay
            ? "Rekod berstatus **{$status}**, jadi tindakan **{$operation}** tidak tersedia. {$availableText}"
            : "The record is **{$status}**, so **{$operation}** is not available. {$availableText}";
    }

    /** @return array<int, string>|null */
    private function allowedOperations(string $workflowKey, string $status): ?array
    {
        if (! str_starts_with($workflowKey, 'reports.')) {
            return null;
        }

        return match ($status) {
            'Draft' => ['view', 'edit', 'submit'],
            'Submitted' => ['view', 'review', 'reject'],
            'Reviewed' => ['view', 'approve', 'reject'],
            'Approved' => ['view', 'download'],
            'Rejected' => ['view'],
            default => [],
        };
    }

    private function requestedOperation(array $workflow): ?string
    {
        $priority = ['reject', 'approve', 'review', 'submit', 'edit', 'create', 'download', 'view'];
        $requested = (array) ($workflow['requested_operations'] ?? []);

        return collect($priority)->first(fn (string $operation) => in_array($operation, $requested, true));
    }

    /** @return array<int, array<string, mixed>> */
    private function reportAuthoringSteps(array $steps, ?string $operation): array
    {
        if ($operation === null || $operation === 'create') {
            return $steps;
        }

        $byKey = collect($steps)->keyBy('key');
        $base = array_values(array_filter([
            $byKey->get('open_reports'),
            $byKey->get('open_report_type'),
            [
                'key' => 'open_existing',
                'kind' => 'select',
                'target' => $operation === 'download' ? 'Existing Record' : 'Existing Draft',
            ],
        ]));

        return match ($operation) {
            'view' => [...$base, $byKey->get('verify_record')],
            'edit' => [
                ...$base,
                $byKey->get('complete_report'),
                ['key' => 'save_changes', 'kind' => 'select', 'target' => 'Save Draft'],
                $byKey->get('verify_record'),
            ],
            'submit' => [
                ...$base,
                $byKey->get('complete_report'),
                ['key' => 'submit_report', 'kind' => 'select', 'target' => 'Submit'],
                $byKey->get('verify_record'),
            ],
            'download' => [
                ...$base,
                ['key' => 'download_report', 'kind' => 'select', 'target' => 'Download'],
                $byKey->get('verify_record'),
            ],
            default => $steps,
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function existingRecordSteps(array $steps, bool $includeDownload): array
    {
        $byKey = collect($steps)->keyBy('key');

        return array_values(array_filter([
            $byKey->get('open_reports'),
            $byKey->get('open_report_type'),
            ['key' => 'open_existing', 'kind' => 'select', 'target' => 'Existing Record'],
            $includeDownload ? ['key' => 'download_report', 'kind' => 'select', 'target' => 'Download'] : null,
            $byKey->get('verify_record'),
        ]));
    }
}
