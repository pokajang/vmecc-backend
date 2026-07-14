<?php

namespace App\Services\Inspection;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HsePayloadService
{
    public const VERSION_2 = 2;

    private const LEGACY_SELECTIONS = [
        'areaSatisfactory',
        'unsafeAct',
        'unsafeCondition',
        'environmental',
    ];

    private const VERSION_2_SELECTIONS = ['unsafeAct', 'unsafeCondition'];

    private const SEVERITIES = ['Low', 'Medium', 'High', 'Critical'];

    private const TEXT_FIELDS = [
        'hseInspectedBy',
        'hseInspectionDate',
        'hseAreaConditionRemarks',
        'hseUnsafeActDetails',
        'hseUnsafeConditionDetails',
        'hseEnvironmentalDetails',
        'hseImmediateAction',
        'hseCorrectiveAction',
        'hseResponsiblePerson',
        'hseTargetDate',
        'hseRemarks',
    ];

    public function isVersion2(array $payload): bool
    {
        return $this->payloadVersion($payload) === self::VERSION_2;
    }

    public function normalize(array $payload): array
    {
        if (array_key_exists('hsePayloadVersion', $payload) || array_key_exists('hse_payload_version', $payload)) {
            $version = $this->payloadVersion($payload);
            if ($version === 0) {
                unset($payload['hsePayloadVersion'], $payload['hse_payload_version']);
            }
            if ($version !== self::VERSION_2) {
                if ($version !== 0) {
                    throw ValidationException::withMessages([
                        'payload.hsePayloadVersion' => ['HSE payload version is not supported.'],
                    ]);
                }
            } else {
                $payload['hsePayloadVersion'] = self::VERSION_2;
                unset($payload['hse_payload_version']);
            }
        }

        foreach (self::TEXT_FIELDS as $field) {
            $snakeField = Str::snake($field);
            if (array_key_exists($field, $payload) || array_key_exists($snakeField, $payload)) {
                $payload[$field] = trim((string) ($payload[$field] ?? $payload[$snakeField] ?? ''));
                unset($payload[$snakeField]);
            }
        }

        if (array_key_exists('hseSelections', $payload) || array_key_exists('hse_selections', $payload)) {
            $payload['hseSelections'] = $this->normalizeSelections(
                $payload['hseSelections'] ?? $payload['hse_selections']
            );
            unset($payload['hse_selections']);
        }

        if (array_key_exists('hseSeverity', $payload) || array_key_exists('hse_severity', $payload)) {
            $payload['hseSeverity'] = $this->normalizeSeverity(
                $payload['hseSeverity'] ?? $payload['hse_severity'] ?? ''
            );
            unset($payload['hse_severity']);
        }

        if ($this->isVersion2($payload)) {
            $selection = $payload['hseSelections'][0] ?? '';
            $payload['hseSelections'] = in_array($selection, self::VERSION_2_SELECTIONS, true)
                ? [$selection]
                : ($payload['hseSelections'] ?? []);

            if ($selection === 'unsafeAct') {
                $payload['hseUnsafeConditionDetails'] = '';
            } elseif ($selection === 'unsafeCondition') {
                $payload['hseUnsafeActDetails'] = '';
            }

            foreach ([
                'hseAreaConditionRemarks',
                'hseEnvironmentalDetails',
                'hseSeverity',
                'hseCorrectiveAction',
                'hseResponsiblePerson',
                'hseTargetDate',
                'hseRemarks',
            ] as $obsoleteField) {
                $payload[$obsoleteField] = '';
            }
            $payload['inspectionIssues'] = [];
            $payload['issues'] = [];
            $payload['reportRemarks'] = '';
        }

        return $payload;
    }

    public function validateForDraft(array $payload): void
    {
        $this->validateVersion($payload);

        if (array_key_exists('hseSelections', $payload) || array_key_exists('hse_selections', $payload)) {
            $selections = $this->normalizeSelections($payload['hseSelections'] ?? $payload['hse_selections']);
            if ($this->isVersion2($payload)) {
                $this->validateVersion2Selections($selections, false);
            }
        }

        if (! $this->isVersion2($payload) && (array_key_exists('hseSeverity', $payload) || array_key_exists('hse_severity', $payload))) {
            $this->normalizeSeverity($payload['hseSeverity'] ?? $payload['hse_severity'] ?? '');
        }
    }

    public function validateForSubmit(array $payload): void
    {
        $this->validateVersion($payload);

        if ($this->isVersion2($payload)) {
            $this->validateVersion2Submit($payload);

            return;
        }

        $this->validateLegacySubmit($payload);
    }

    public function normalizeSelections(mixed $value): array
    {
        $source = is_array($value) ? $value : [$value];
        $rows = [];

        foreach ($source as $item) {
            $normalized = $this->normalizeSelection($item);
            if ($normalized !== '' && ! in_array($normalized, $rows, true)) {
                $rows[] = $normalized;
            }
        }

        return $rows;
    }

    private function validateVersion(array $payload): void
    {
        if (! array_key_exists('hsePayloadVersion', $payload) && ! array_key_exists('hse_payload_version', $payload)) {
            return;
        }

        $version = $this->payloadVersion($payload);
        if ($version === 0) {
            return;
        }
        if ($version !== self::VERSION_2) {
            throw ValidationException::withMessages([
                'payload.hsePayloadVersion' => ['HSE payload version is not supported.'],
            ]);
        }
    }

    private function validateVersion2Submit(array $payload): void
    {
        $inspectionType = Str::of((string) ($payload['incidentType'] ?? $payload['inspectionType'] ?? ''))
            ->squish()
            ->lower()
            ->toString();
        $inspectedBy = trim((string) ($payload['hseInspectedBy'] ?? $payload['hse_inspected_by'] ?? ''));
        $inspectedAt = trim((string) ($payload['inspectedAt'] ?? $payload['inspected_at'] ?? ''));
        $location = trim((string) ($payload['selectedLocation'] ?? $payload['location'] ?? ''));
        $selections = $this->normalizeSelections($payload['hseSelections'] ?? $payload['hse_selections'] ?? []);

        if ($inspectionType !== 'health safety environment inspection') {
            throw ValidationException::withMessages([
                'payload.incidentType' => ['HSE payload version 2 is only valid for Health Safety Environment Inspection.'],
            ]);
        }
        if ($inspectedBy === '') {
            throw ValidationException::withMessages([
                'payload.hseInspectedBy' => ['Unable to identify the HSE inspector.'],
            ]);
        }
        if ($inspectedAt === '') {
            throw ValidationException::withMessages([
                'payload.inspectedAt' => ['Observation date and time are required.'],
            ]);
        }
        $parsedInspectedAt = date_parse($inspectedAt);
        if (
            ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?$/', $inspectedAt)
            || ($parsedInspectedAt['warning_count'] ?? 0) > 0
            || ($parsedInspectedAt['error_count'] ?? 0) > 0
        ) {
            throw ValidationException::withMessages([
                'payload.inspectedAt' => ['Observation date and time must be a valid ISO 8601 value.'],
            ]);
        }
        if ($location === '') {
            throw ValidationException::withMessages([
                'payload.selectedLocation' => ['Observation location is required.'],
            ]);
        }

        $this->validateVersion2Selections($selections, true);
        $selection = $selections[0];
        $detailField = $selection === 'unsafeAct' ? 'hseUnsafeActDetails' : 'hseUnsafeConditionDetails';
        if (trim((string) ($payload[$detailField] ?? $payload[Str::snake($detailField)] ?? '')) === '') {
            throw ValidationException::withMessages([
                'payload.'.$detailField => ['Observation description is required.'],
            ]);
        }

        $photos = $payload['photos'] ?? [];
        if (! is_array($photos) || count(array_filter($photos, 'is_array')) === 0) {
            throw ValidationException::withMessages([
                'payload.photos' => ['Attach at least one observation photo.'],
            ]);
        }
    }

    private function validateVersion2Selections(array $selections, bool $required): void
    {
        if (! $required && $selections === []) {
            return;
        }
        if (count($selections) !== 1 || ! in_array($selections[0], self::VERSION_2_SELECTIONS, true)) {
            throw ValidationException::withMessages([
                'payload.hseSelections' => ['Select exactly one observation type: Unsafe Act or Unsafe Condition.'],
            ]);
        }
    }

    private function payloadVersion(array $payload): int
    {
        if (! array_key_exists('hsePayloadVersion', $payload) && ! array_key_exists('hse_payload_version', $payload)) {
            return 0;
        }

        $value = $payload['hsePayloadVersion'] ?? $payload['hse_payload_version'];
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value))) {
            return (int) trim($value);
        }

        throw ValidationException::withMessages([
            'payload.hsePayloadVersion' => ['HSE payload version must be an integer.'],
        ]);
    }

    private function validateLegacySubmit(array $payload): void
    {
        $inspectedBy = trim((string) ($payload['hseInspectedBy'] ?? $payload['hse_inspected_by'] ?? ''));
        $inspectionDate = trim((string) ($payload['hseInspectionDate'] ?? $payload['hse_inspection_date'] ?? ''));
        $selections = $this->normalizeSelections($payload['hseSelections'] ?? $payload['hse_selections'] ?? []);
        $severity = $this->normalizeSeverity($payload['hseSeverity'] ?? $payload['hse_severity'] ?? '');

        if ($inspectedBy === '') {
            throw ValidationException::withMessages(['payload.hseInspectedBy' => ['HSE inspected by is required.']]);
        }
        if ($inspectionDate === '') {
            throw ValidationException::withMessages(['payload.hseInspectionDate' => ['HSE inspection date is required.']]);
        }
        if ($selections === []) {
            throw ValidationException::withMessages(['payload.hseSelections' => ['Select Area Satisfactory or at least one HSE finding.']]);
        }
        if (in_array('areaSatisfactory', $selections, true) && count($selections) > 1) {
            throw ValidationException::withMessages(['payload.hseSelections' => ['Area Satisfactory cannot be combined with HSE findings.']]);
        }
        if (in_array('areaSatisfactory', $selections, true)) {
            if (trim((string) ($payload['hseAreaConditionRemarks'] ?? $payload['hse_area_condition_remarks'] ?? '')) === '') {
                throw ValidationException::withMessages(['payload.hseAreaConditionRemarks' => ['Area condition remarks are required for Area Satisfactory.']]);
            }

            return;
        }
        if ($severity === '') {
            throw ValidationException::withMessages(['payload.hseSeverity' => ['HSE severity is required when findings are selected.']]);
        }

        $detailFields = [
            'unsafeAct' => ['hseUnsafeActDetails', 'Unsafe act details are required.'],
            'unsafeCondition' => ['hseUnsafeConditionDetails', 'Unsafe condition details are required.'],
            'environmental' => ['hseEnvironmentalDetails', 'Environmental details are required.'],
        ];
        foreach ($detailFields as $selection => [$field, $message]) {
            if (in_array($selection, $selections, true) && trim((string) ($payload[$field] ?? $payload[Str::snake($field)] ?? '')) === '') {
                throw ValidationException::withMessages(['payload.'.$field => [$message]]);
            }
        }
    }

    private function normalizeSelection(mixed $value): string
    {
        $key = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', trim((string) $value)));
        $aliases = [
            'areasatisfactory' => 'areaSatisfactory',
            'satisfactory' => 'areaSatisfactory',
            'unsafeact' => 'unsafeAct',
            'act' => 'unsafeAct',
            'unsafecondition' => 'unsafeCondition',
            'condition' => 'unsafeCondition',
            'environmental' => 'environmental',
            'environment' => 'environmental',
        ];

        if (isset($aliases[$key])) {
            return $aliases[$key];
        }
        if (in_array((string) $value, self::LEGACY_SELECTIONS, true)) {
            return (string) $value;
        }

        throw ValidationException::withMessages([
            'payload.hseSelections' => ['HSE selection value is not valid.'],
        ]);
    }

    private function normalizeSeverity(mixed $value): string
    {
        $severity = trim((string) $value);
        if ($severity === '') {
            return '';
        }
        foreach (self::SEVERITIES as $allowed) {
            if (strcasecmp($severity, $allowed) === 0) {
                return $allowed;
            }
        }

        throw ValidationException::withMessages([
            'payload.hseSeverity' => ['HSE severity must be Low, Medium, High, or Critical.'],
        ]);
    }
}
