<?php

namespace App\Services\InspectionReports;

class InspectionReportHseViewDataBuilder
{
    private const LABELS = [
        'areaSatisfactory' => 'Area Satisfactory',
        'unsafeAct' => 'Unsafe Act',
        'unsafeCondition' => 'Unsafe Condition',
        'environmental' => 'Environmental',
    ];

    private const DETAIL_FIELDS = [
        'areaSatisfactory' => ['label' => 'Area Condition Remarks', 'camel' => 'hseAreaConditionRemarks', 'snake' => 'hse_area_condition_remarks'],
        'unsafeAct' => ['label' => 'Unsafe Act Details', 'camel' => 'hseUnsafeActDetails', 'snake' => 'hse_unsafe_act_details'],
        'unsafeCondition' => ['label' => 'Unsafe Condition Details', 'camel' => 'hseUnsafeConditionDetails', 'snake' => 'hse_unsafe_condition_details'],
        'environmental' => ['label' => 'Environmental Details', 'camel' => 'hseEnvironmentalDetails', 'snake' => 'hse_environmental_details'],
    ];

    public function build(array $record, array $reportEvidence): array
    {
        $version = (int) ($record['hsePayloadVersion'] ?? $record['hse_payload_version'] ?? 0);
        $isVersion2 = $version === 2;
        $selections = array_values(array_filter(
            is_array($record['hseSelections'] ?? $record['hse_selections'] ?? null)
                ? ($record['hseSelections'] ?? $record['hse_selections'])
                : [],
            fn (mixed $selection): bool => is_string($selection) && trim($selection) !== '',
        ));
        $details = [];
        foreach ($selections as $selection) {
            $field = self::DETAIL_FIELDS[$selection] ?? null;
            if ($field === null) {
                continue;
            }
            $value = $this->text($record[$field['camel']] ?? $record[$field['snake']] ?? '');
            if ($value !== '') {
                $details[] = ['label' => $isVersion2 ? 'Description' : $field['label'], 'value' => $value];
            }
        }

        $optionalFields = $isVersion2
            ? [['label' => 'Immediate Corrective Action', 'camel' => 'hseImmediateAction', 'snake' => 'hse_immediate_action']]
            : [
                ['label' => 'Immediate Action', 'camel' => 'hseImmediateAction', 'snake' => 'hse_immediate_action'],
                ['label' => 'Corrective Action', 'camel' => 'hseCorrectiveAction', 'snake' => 'hse_corrective_action'],
                ['label' => 'Responsible Person', 'camel' => 'hseResponsiblePerson', 'snake' => 'hse_responsible_person'],
                ['label' => 'Target Date', 'camel' => 'hseTargetDate', 'snake' => 'hse_target_date'],
                ['label' => 'General HSE Remarks', 'camel' => 'hseRemarks', 'snake' => 'hse_remarks'],
            ];
        $optional = [];
        foreach ($optionalFields as $field) {
            $value = $this->text($record[$field['camel']] ?? $record[$field['snake']] ?? '');
            if ($value !== '') {
                $optional[] = ['label' => $field['label'], 'value' => $value];
            }
        }

        return [
            'version' => $version,
            'isVersion2' => $isVersion2,
            'inspectedBy' => $this->text($record['hseInspectedBy'] ?? $record['hse_inspected_by'] ?? ''),
            'inspectionDate' => $this->text($record['hseInspectionDate'] ?? $record['hse_inspection_date'] ?? ''),
            'observedAt' => $this->text($record['inspectedAt'] ?? $record['inspected_at'] ?? ''),
            'location' => $this->text($record['selectedLocation'] ?? $record['location'] ?? ''),
            'severity' => $isVersion2 ? '' : $this->text($record['hseSeverity'] ?? $record['hse_severity'] ?? ''),
            'selections' => $selections,
            'selectionLabels' => self::LABELS,
            'details' => $details,
            'optional' => $optional,
            'photoGroups' => $isVersion2 ? ($reportEvidence['groups'] ?? []) : [],
            'photoCount' => $isVersion2 ? count($reportEvidence['photos'] ?? []) : 0,
            // Only hide the generic evidence section when the HSE section has
            // a renderable gallery. Corrupt or unavailable media can then
            // still fall back to the generic report-evidence presentation.
            'consumesReportEvidence' => $isVersion2 && count($reportEvidence['photos'] ?? []) > 0,
            'hasObservation' => $selections !== []
                || $this->text($record['hseInspectedBy'] ?? $record['hse_inspected_by'] ?? '') !== ''
                || $this->text($record['hseInspectionDate'] ?? $record['hse_inspection_date'] ?? '') !== '',
        ];
    }

    private function text(mixed $value): string
    {
        return trim((string) $value);
    }
}
