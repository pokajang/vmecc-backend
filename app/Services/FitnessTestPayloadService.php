<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class FitnessTestPayloadService
{
    private const SCHEMA_VERSION = 1;

    public function validateForDraft(array $payload): void
    {
        $this->validate($payload, false);
    }

    public function validateForSubmit(array $payload): void
    {
        $this->validate($payload, true);
    }

    public function normalizeForProjection(array $payload): array
    {
        $normalized = $payload;
        $normalized['shiftGroups'] = $this->normalizeShiftGroups($payload);

        return $normalized;
    }

    private function validate(array $payload, bool $forSubmit): void
    {
        $usesCanonical = $this->usesCanonicalPayload($payload);

        $rules = [
            'schemaVersion' => [$forSubmit ? 'required' : 'nullable', 'integer', 'in:'.self::SCHEMA_VERSION],
            'fitnessSchemaVersion' => ['nullable', 'integer', 'in:3'],
            'reportDate' => ['nullable', 'date_format:Y-m-d'],
            'reportTime' => ['nullable', 'date_format:H:i'],
            'weather' => ['nullable', 'string', 'max:190'],
            'incidentType' => ['nullable', 'string', 'max:190'],
            'location' => ['nullable', 'string', 'max:1000'],
            'details' => ['nullable', 'string', 'max:20000'],
            'summary' => ['nullable', 'string', 'max:20000'],
            'chronology' => ['nullable', 'array', 'max:250'],
            'chronology.*' => ['array'],
            'chronology.*.time' => ['nullable', 'date_format:H:i'],
            'chronology.*.action' => ['nullable', 'string', 'max:4000'],
            'reportingMonth' => ['nullable', 'string', 'max:7'],
            'documentReference' => ['nullable', 'string', 'max:190'],
            'protocolRevision' => ['nullable', 'string', 'max:64'],
        ];

        if ($usesCanonical && ! $forSubmit) {
            $rules['reportingMonth'] = ['nullable', 'date_format:Y-m'];
            $rules += [
                'shiftGroups' => ['nullable', 'array', 'max:100'],
                'shiftGroups.*' => ['required', 'array'],
                'shiftGroups.*.id' => ['nullable', 'string', 'max:190'],
                'shiftGroups.*.teamId' => ['nullable', 'integer', 'min:1'],
                'shiftGroups.*.shiftName' => ['nullable', 'string', 'max:190'],
                'shiftGroups.*.assessor' => ['nullable', 'array'],
                'shiftGroups.*.assessor.userId' => ['nullable', 'integer', 'min:1'],
                'shiftGroups.*.participants' => ['nullable', 'array', 'max:500'],
                'shiftGroups.*.participants.*' => ['array'],
                'shiftGroups.*.participants.*.ageSnapshot' => ['nullable', 'integer', 'min:0', 'max:190'],
                'shiftGroups.*.participants.*.fitness' => ['nullable', 'array'],
                'shiftGroups.*.participants.*.fitness.result' => ['nullable', 'string', 'max:24'],
                'shiftGroups.*.participants.*.fitness.sitUps' => ['nullable', 'integer', 'min:0'],
                'shiftGroups.*.participants.*.fitness.jumpingJacks' => ['nullable', 'integer', 'min:0'],
                'shiftGroups.*.participants.*.fitness.pushUps' => ['nullable', 'integer', 'min:0'],
                'shiftGroups.*.participants.*.proficiency' => ['nullable', 'array'],
                'shiftGroups.*.participants.*.proficiency.result' => ['nullable', 'string', 'max:24'],
                'shiftGroups.*.participants.*.proficiency.durationSeconds' => ['nullable', 'integer', 'min:0'],
            ];
        }

        if ($forSubmit && ! $usesCanonical) {
            $rules = array_replace($rules, [
                'reportDate' => ['required', 'date_format:Y-m-d'],
                'reportTime' => ['required', 'date_format:H:i'],
                'weather' => ['required', 'string', 'max:190'],
                'incidentType' => ['required', 'string', 'max:190'],
                'location' => ['required', 'string', 'max:1000'],
                'details' => ['required', 'string', 'max:20000'],
                'summary' => ['required', 'string', 'max:20000'],
                'chronology' => ['required', 'array', 'min:1', 'max:250'],
                'chronology.*.time' => ['required', 'date_format:H:i'],
                'chronology.*.action' => ['required', 'string', 'max:4000'],
            ]);
        }

        if ($forSubmit && $usesCanonical) {
            $rules = array_replace($rules, [
                'reportingMonth' => ['required', 'date_format:Y-m'],
                'shiftGroups' => ['required', 'array', 'min:1', 'max:100'],
            ]);
        }

        $validator = Validator::make($payload, $rules);
        if ($forSubmit && $this->isFitnessV3($payload)) {
            $validator->after(fn ($validator) => $this->validateFitnessV3Submission($payload, $validator));
        }
        $validator->validate();
    }

    private function isFitnessV3(array $payload): bool
    {
        return (int) ($payload['fitnessSchemaVersion'] ?? 0) === 3;
    }

    private function validateFitnessV3Submission(array $payload, mixed $validator): void
    {
        $groupIds = [];
        $participantIds = [];
        foreach (array_values((array) ($payload['shiftGroups'] ?? [])) as $groupIndex => $group) {
            if (! is_array($group)) {
                continue;
            }
            $groupPath = "shiftGroups.{$groupIndex}";
            $groupId = trim((string) ($group['id'] ?? ''));
            if ($groupId === '') {
                $validator->errors()->add("{$groupPath}.id", 'A stable shift-group ID is required.');
            } elseif (isset($groupIds[$groupId])) {
                $validator->errors()->add("{$groupPath}.id", 'Shift-group IDs must be unique.');
            } else {
                $groupIds[$groupId] = true;
            }

            $assessor = is_array($group['assessor'] ?? null) ? $group['assessor'] : [];
            $assessorId = $assessor['userId'] ?? $assessor['user_id'] ?? null;
            $assessorName = trim((string) ($assessor['name'] ?? ''));
            if ($assessorName === '' && ! is_numeric($assessorId)) {
                $validator->errors()->add("{$groupPath}.assessor", 'Each shift group requires an assessor ID or name snapshot.');
            }

            foreach (array_values((array) ($group['participants'] ?? [])) as $participantIndex => $participant) {
                if (! is_array($participant)) {
                    continue;
                }
                $participantPath = "{$groupPath}.participants.{$participantIndex}";
                $participantId = trim((string) ($participant['id'] ?? ''));
                if ($participantId === '') {
                    $validator->errors()->add("{$participantPath}.id", 'A stable participant ID is required.');
                } elseif (isset($participantIds[$participantId])) {
                    $validator->errors()->add("{$participantPath}.id", 'A participant may appear only once in a report.');
                } else {
                    $participantIds[$participantId] = true;
                }

                $fitness = is_array($participant['fitness'] ?? null) ? $participant['fitness'] : [];
                foreach (['sitUps', 'jumpingJacks', 'pushUps'] as $field) {
                    if (! isset($fitness[$field]) && ! isset($fitness[Str::snake($field)])) {
                        $validator->errors()->add("{$participantPath}.fitness.{$field}", 'Fitness metrics are required.');
                    }
                }
                if (trim((string) ($fitness['testedOn'] ?? $fitness['tested_at'] ?? '')) === '') {
                    $validator->errors()->add("{$participantPath}.fitness.testedOn", 'Fitness test date is required.');
                }

                $proficiency = is_array($participant['proficiency'] ?? null) ? $participant['proficiency'] : [];
                if (! isset($proficiency['durationSeconds']) && ! isset($proficiency['duration_seconds'])) {
                    $validator->errors()->add("{$participantPath}.proficiency.durationSeconds", 'Proficiency duration is required.');
                }
                if (trim((string) ($proficiency['testedOn'] ?? $proficiency['tested_at'] ?? '')) === '') {
                    $validator->errors()->add("{$participantPath}.proficiency.testedOn", 'Proficiency test date is required.');
                }

                $checkpointCodes = [];
                $checkpoints = is_array($fitness['checkpoints'] ?? null) ? $fitness['checkpoints'] : [];
                foreach ($checkpoints as $checkpointIndex => $checkpoint) {
                    if (! is_array($checkpoint)) {
                        continue;
                    }
                    $code = strtoupper(trim((string) ($checkpoint['checkpointCode'] ?? $checkpoint['checkpoint_code'] ?? $checkpoint['code'] ?? '')));
                    if ($code === '') {
                        $validator->errors()->add("{$participantPath}.fitness.checkpoints.{$checkpointIndex}", 'Checkpoint code is required.');
                    } elseif (isset($checkpointCodes[$code])) {
                        $validator->errors()->add("{$participantPath}.fitness.checkpoints.{$checkpointIndex}", 'Checkpoint codes must be unique.');
                    } else {
                        $checkpointCodes[$code] = true;
                    }
                }
                foreach (['CP1', 'CP2', 'CP3', 'CP4', 'CP5', 'CP6'] as $code) {
                    if (! array_key_exists($code, $checkpointCodes)) {
                        $validator->errors()->add("{$participantPath}.fitness.checkpoints", "{$code} is required.");
                    }
                }
            }
        }
    }

    private function usesCanonicalPayload(array $payload): bool
    {
        if ($this->isFitnessV3($payload)) {
            return true;
        }

        if (! is_array($payload['shiftGroups'] ?? null)) {
            return false;
        }

        return ! $this->containsLegacyEnvelopeFields($payload);
    }

    private function containsLegacyEnvelopeFields(array $payload): bool
    {
        foreach (['reportDate', 'reportTime', 'weather', 'incidentType', 'location', 'details', 'summary', 'chronology'] as $field) {
            if (array_key_exists($field, $payload)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeShiftGroups(array $payload): array
    {
        $shiftGroups = is_array($payload['shiftGroups'] ?? null) ? $payload['shiftGroups'] : null;
        if ($shiftGroups === null) {
            $shiftGroups = is_array($payload['shift_groups'] ?? null) ? $payload['shift_groups'] : null;
        }
        if ($shiftGroups === null) {
            $shiftGroups = is_array($payload['groups'] ?? null) ? $payload['groups'] : null;
        }
        if (is_array($shiftGroups)) {
            return array_values(array_filter($shiftGroups, 'is_array'));
        }

        $legacyParticipants = is_array($payload['participants'] ?? null) ? $payload['participants'] : [];
        if ($legacyParticipants === []) {
            return [];
        }

        $assessor = $this->normalizeAssessorPayload($payload['assessor'] ?? null);
        if ($assessor !== null) {
            $assessorPayload = $assessor;
        } else {
            $assessorPayload = null;
        }

        return [
            [
                'shiftName' => $this->firstString([
                    $payload['shiftName'] ?? null,
                    $payload['shift'] ?? null,
                ]),
                'teamId' => $payload['teamId'] ?? $payload['team_id'] ?? null,
                'assessor' => $assessorPayload,
                'assessorUserId' => $payload['assessorUserId'] ?? null,
                'participants' => $legacyParticipants,
            ],
        ];
    }

    private function normalizeAssessorPayload(mixed $assessor): ?array
    {
        if (! is_array($assessor)) {
            return null;
        }

        $userId = $assessor['userId'] ?? $assessor['user_id'] ?? $assessor['id'] ?? null;
        $name = trim((string) ($assessor['name'] ?? $assessor['assessorName'] ?? $assessor['fullName'] ?? ''));

        return [
            'userId' => is_int($userId) || ctype_digit((string) $userId) ? (int) $userId : null,
            'name' => $name === '' ? null : $name,
        ];
    }

    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }
}
