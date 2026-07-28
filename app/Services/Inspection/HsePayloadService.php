<?php

namespace App\Services\Inspection;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HsePayloadService
{
    public const VERSION_2 = 2;

    private const SELECTIONS = ['unsafeAct', 'unsafeCondition'];

    private const TEXT_FIELDS = [
        'hseInspectedBy',
        'hseUnsafeActDetails',
        'hseUnsafeConditionDetails',
        'hseImmediateAction',
    ];

    private const REMOVED_FIELDS = [
        'hseInspectionDate',
        'hseAreaConditionRemarks',
        'hseEnvironmentalDetails',
        'hseSeverity',
        'hseCorrectiveAction',
        'hseResponsiblePerson',
        'hseTargetDate',
        'hseRemarks',
    ];

    public function normalize(array $payload): array
    {
        if (! $this->isHseInspection($payload)) {
            return $this->removeUnusedVersionMarker($payload);
        }

        $this->validateVersion($payload);
        $payload['hsePayloadVersion'] = self::VERSION_2;
        unset($payload['hse_payload_version']);

        foreach (self::TEXT_FIELDS as $field) {
            $snakeField = Str::snake($field);
            $payload[$field] = trim((string) ($payload[$field] ?? $payload[$snakeField] ?? ''));
            unset($payload[$snakeField]);
        }

        $payload['hseSelections'] = $this->normalizeSelections(
            $payload['hseSelections'] ?? $payload['hse_selections'] ?? [],
        );
        unset($payload['hse_selections']);

        $selection = $payload['hseSelections'][0] ?? '';
        if ($selection === 'unsafeAct') {
            $payload['hseUnsafeConditionDetails'] = '';
        } elseif ($selection === 'unsafeCondition') {
            $payload['hseUnsafeActDetails'] = '';
        }

        foreach (self::REMOVED_FIELDS as $field) {
            unset($payload[$field], $payload[Str::snake($field)]);
        }

        $payload['inspectionIssues'] = [];
        $payload['issues'] = [];
        $payload['reportRemarks'] = '';

        return $payload;
    }

    public function validateForDraft(array $payload): void
    {
        $this->validateVersion($payload);
        $this->validateSelections(
            $this->normalizeSelections($payload['hseSelections'] ?? $payload['hse_selections'] ?? []),
            false,
        );
    }

    public function validateForSubmit(array $payload): void
    {
        $this->validateVersion($payload);
        $this->validateSubmit($payload);
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
            throw ValidationException::withMessages([
                'payload.hsePayloadVersion' => ['HSE payload version 2 is required.'],
            ]);
        }

        if ($this->payloadVersion($payload) !== self::VERSION_2) {
            throw ValidationException::withMessages([
                'payload.hsePayloadVersion' => ['HSE payload version 2 is required.'],
            ]);
        }
    }

    private function validateSubmit(array $payload): void
    {
        $inspectedBy = trim((string) ($payload['hseInspectedBy'] ?? $payload['hse_inspected_by'] ?? ''));
        $inspectedAt = trim((string) ($payload['inspectedAt'] ?? $payload['inspected_at'] ?? ''));
        $location = trim((string) ($payload['selectedLocation'] ?? $payload['location'] ?? ''));
        $selections = $this->normalizeSelections($payload['hseSelections'] ?? $payload['hse_selections'] ?? []);

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

        $this->validateSelections($selections, true);
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

    private function validateSelections(array $selections, bool $required): void
    {
        if (! $required && $selections === []) {
            return;
        }
        if (count($selections) !== 1 || ! in_array($selections[0], self::SELECTIONS, true)) {
            throw ValidationException::withMessages([
                'payload.hseSelections' => ['Select exactly one observation type: Unsafe Act or Unsafe Condition.'],
            ]);
        }
    }

    private function payloadVersion(array $payload): int
    {
        $value = $payload['hsePayloadVersion'] ?? $payload['hse_payload_version'] ?? null;
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

    private function normalizeSelection(mixed $value): string
    {
        $key = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', trim((string) $value)));
        $normalized = match ($key) {
            'unsafeact', 'act' => 'unsafeAct',
            'unsafecondition', 'condition' => 'unsafeCondition',
            default => '',
        };
        if ($normalized !== '') {
            return $normalized;
        }

        throw ValidationException::withMessages([
            'payload.hseSelections' => ['HSE selection value is not valid.'],
        ]);
    }

    private function isHseInspection(array $payload): bool
    {
        return Str::of((string) ($payload['incidentType'] ?? $payload['inspectionType'] ?? ''))
            ->squish()
            ->lower()
            ->toString() === 'health safety environment inspection';
    }

    private function removeUnusedVersionMarker(array $payload): array
    {
        if (! array_key_exists('hsePayloadVersion', $payload) && ! array_key_exists('hse_payload_version', $payload)) {
            return $payload;
        }

        if ($this->payloadVersion($payload) !== 0) {
            throw ValidationException::withMessages([
                'payload.hsePayloadVersion' => ['HSE payload version is only valid for Health Safety Environment Inspection.'],
            ]);
        }

        unset($payload['hsePayloadVersion'], $payload['hse_payload_version']);

        return $payload;
    }
}
