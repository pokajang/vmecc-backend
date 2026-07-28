<?php

namespace App\Services\InspectionReports;

class InspectionReportHseViewDataBuilder
{
    private const LABELS = [
        'unsafeAct' => 'Unsafe Act',
        'unsafeCondition' => 'Unsafe Condition',
    ];

    private const DETAIL_FIELDS = [
        'unsafeAct' => ['camel' => 'hseUnsafeActDetails', 'snake' => 'hse_unsafe_act_details'],
        'unsafeCondition' => ['camel' => 'hseUnsafeConditionDetails', 'snake' => 'hse_unsafe_condition_details'],
    ];

    public function build(array $record, array $reportEvidence, bool $isHseInspection): array
    {
        $selections = array_values(array_filter(
            is_array($record['hseSelections'] ?? $record['hse_selections'] ?? null)
                ? ($record['hseSelections'] ?? $record['hse_selections'])
                : [],
            fn (mixed $selection): bool => is_string($selection) && array_key_exists($selection, self::LABELS),
        ));
        $details = [];
        foreach ($selections as $selection) {
            $field = self::DETAIL_FIELDS[$selection] ?? null;
            if ($field === null) {
                continue;
            }
            $value = $this->text($record[$field['camel']] ?? $record[$field['snake']] ?? '');
            if ($value !== '') {
                $details[] = ['label' => 'Description', 'value' => $value];
            }
        }

        $optional = [];
        $immediateAction = $this->text($record['hseImmediateAction'] ?? $record['hse_immediate_action'] ?? '');
        if ($immediateAction !== '') {
            $optional[] = ['label' => 'Immediate Corrective Action', 'value' => $immediateAction];
        }

        $photos = $reportEvidence['photos'] ?? [];

        return [
            'inspectedBy' => $this->text($record['hseInspectedBy'] ?? $record['hse_inspected_by'] ?? ''),
            'observedAt' => $this->text($record['inspectedAt'] ?? $record['inspected_at'] ?? ''),
            'location' => $this->text($record['selectedLocation'] ?? $record['location'] ?? ''),
            'selections' => $selections,
            'selectionLabels' => self::LABELS,
            'details' => $details,
            'optional' => $optional,
            'photoGroups' => $reportEvidence['groups'] ?? [],
            'photoCount' => count($photos),
            // Only hide the generic evidence section when the HSE section has
            // a renderable gallery. Corrupt or unavailable media can then
            // still fall back to the generic report-evidence presentation.
            'consumesReportEvidence' => $isHseInspection && count($photos) > 0,
            'hasObservation' => $isHseInspection && (
                $selections !== []
                || $this->text($record['hseInspectedBy'] ?? $record['hse_inspected_by'] ?? '') !== ''
                || $this->text($record['inspectedAt'] ?? $record['inspected_at'] ?? '') !== ''
            ),
        ];
    }

    private function text(mixed $value): string
    {
        return trim((string) $value);
    }
}
