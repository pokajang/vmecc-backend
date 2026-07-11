<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DrillPayloadService
{
    private const SCHEMA_VERSION = 2;

    private const EXERCISE_CATEGORIES = [
        'Fire',
        'Rescue',
        'Hazmat / Oil Spill',
        'Special Assistance',
    ];

    private const EXCLUSIVE_ROLES = [
        'SC',
        'ASC',
        'TRT1',
        'TRT2',
        'TRT3',
        'TRT4',
    ];

    public function validateForDraft(array $payload): void
    {
        if (! $this->usesV2($payload)) {
            return;
        }

        $this->validateV2($payload, false);
    }

    public function validateForSubmit(array $payload): void
    {
        if (! $this->usesV2($payload)) {
            return;
        }

        $this->validateV2($payload, true);
    }

    private function usesV2(array $payload): bool
    {
        if (! array_key_exists('schemaVersion', $payload)) {
            return false;
        }

        if (! is_int($payload['schemaVersion']) && ! ctype_digit((string) $payload['schemaVersion'])) {
            throw ValidationException::withMessages([
                'schemaVersion' => ['The Drill schema version must be an integer.'],
            ]);
        }

        $version = (int) $payload['schemaVersion'];
        if ($version !== self::SCHEMA_VERSION) {
            throw ValidationException::withMessages([
                'schemaVersion' => ["Drill schema version {$version} is not supported."],
            ]);
        }

        return true;
    }

    private function validateV2(array $payload, bool $forSubmit): void
    {
        $rules = [
            'schemaVersion' => ['required', 'integer', 'in:2'],
            'reportDate' => ['nullable', 'date_format:Y-m-d'],
            'reportTime' => ['nullable', 'date_format:H:i'],
            'reportIssuanceDate' => ['nullable', 'date_format:Y-m-d'],
            'weather' => ['nullable', 'string', 'max:190'],
            'incidentType' => ['nullable', 'string', 'max:190'],
            'exerciseCategories' => ['nullable', 'array', 'max:4'],
            'exerciseCategories.*' => ['string', 'distinct', Rule::in(self::EXERCISE_CATEGORIES)],
            'location' => ['nullable', 'string', 'max:190'],
            'exerciseTitle' => ['nullable', 'string', 'max:190'],
            'details' => ['nullable', 'string', 'max:20000'],
            'summary' => ['nullable', 'string', 'max:20000'],
            'exerciseObjectives' => ['nullable', 'array', 'max:25'],
            'exerciseObjectives.*' => ['array'],
            'exerciseObjectives.*.id' => ['nullable', 'string', 'max:190'],
            'exerciseObjectives.*.text' => ['nullable', 'string', 'max:2000'],
            'erpReferences' => ['nullable', 'array', 'max:25'],
            'erpReferences.*' => ['array'],
            'erpReferences.*.id' => ['nullable', 'string', 'max:190'],
            'erpReferences.*.annexNumber' => ['nullable', 'string', 'max:190'],
            'erpReferences.*.title' => ['nullable', 'string', 'max:500'],
            'respondingTeamName' => ['nullable', 'string', 'max:190'],
            'respondingTeamShift' => ['nullable', 'string', 'max:190'],
            'respondingAttendance' => ['nullable', 'array', 'max:100'],
            'respondingAttendance.*' => ['array'],
            'respondingAttendance.*.memberKey' => ['nullable', 'string', 'max:190'],
            'respondingAttendance.*.memberId' => ['nullable', 'string', 'max:190'],
            'respondingAttendance.*.name' => ['nullable', 'string', 'max:190'],
            'respondingAttendance.*.role' => ['nullable', 'string', 'max:190'],
            'respondingAttendance.*.exerciseRole' => ['nullable', 'string', 'max:190'],
            'respondingAttendance.*.teamName' => ['nullable', 'string', 'max:190'],
            'respondingAttendance.*.present' => ['nullable', 'boolean'],
            'respondingAttendance.*.source' => ['nullable', 'string', 'max:80'],
            'respondingTeam' => ['nullable', 'array'],
            'respondingTeam.name' => ['nullable', 'string', 'max:190'],
            'respondingTeam.shift' => ['nullable', 'string', 'max:190'],
            'respondingTeam.attendance' => ['nullable', 'array', 'max:100'],
            'respondingTeam.attendance.*' => ['array'],
            'respondingTeam.attendance.*.memberId' => ['nullable', 'string', 'max:190'],
            'respondingTeam.attendance.*.name' => ['nullable', 'string', 'max:190'],
            'respondingTeam.attendance.*.role' => ['nullable', 'string', 'max:190'],
            'respondingTeam.attendance.*.exerciseRole' => ['nullable', 'string', 'max:190'],
            'respondingTeam.attendance.*.teamName' => ['nullable', 'string', 'max:190'],
            'respondingTeam.attendance.*.source' => ['nullable', 'string', 'max:80'],
            'chronology' => ['nullable', 'array', 'max:250'],
            'chronology.*' => ['array'],
            'chronology.*.id' => ['nullable', 'string', 'max:190'],
            'chronology.*.time' => ['nullable', 'date_format:H:i'],
            'chronology.*.action' => ['nullable', 'string', 'max:4000'],
            'postIncidentAnalysis' => ['nullable', 'array'],
            'postIncidentAnalysis.strengths' => ['nullable', 'array', 'max:50'],
            'postIncidentAnalysis.strengths.*' => ['nullable', 'string', 'max:2000'],
            'postIncidentAnalysis.resourcesMobilised' => ['nullable', 'array', 'max:50'],
            'postIncidentAnalysis.resourcesMobilised.*' => ['nullable', 'string', 'max:2000'],
            'postIncidentAnalysis.improvementOpportunities' => ['nullable', 'array', 'max:50'],
            'postIncidentAnalysis.improvementOpportunities.*' => ['nullable', 'string', 'max:2000'],
            'postIncidentAnalysis.photos' => ['nullable', 'array', 'max:10'],
            'postIncidentAnalysis.photos.*' => ['array'],
            'postIncidentAnalysis.photos.*.id' => ['nullable', 'string', 'max:190'],
            'postIncidentAnalysis.photos.*.mediaId' => ['required', 'string', 'max:80'],
            'postIncidentAnalysis.photos.*.url' => ['nullable', 'string', 'max:2048'],
            'postIncidentAnalysis.photos.*.thumbnailUrl' => ['nullable', 'string', 'max:2048'],
            'postIncidentAnalysis.photos.*.fileName' => ['nullable', 'string', 'max:255'],
            'postIncidentAnalysis.photos.*.mimeType' => ['nullable', 'string', 'max:80'],
            'postIncidentAnalysis.photos.*.sizeBytes' => ['nullable', 'integer', 'min:0'],
            'postIncidentAnalysis.photos.*.width' => ['nullable', 'integer', 'min:0'],
            'postIncidentAnalysis.photos.*.height' => ['nullable', 'integer', 'min:0'],
            'postIncidentAnalysis.photos.*.description' => ['nullable', 'string', 'max:2000'],
        ];

        if ($forSubmit) {
            $rules = array_replace($rules, [
                'reportDate' => ['required', 'date_format:Y-m-d'],
                'reportTime' => ['required', 'date_format:H:i'],
                'weather' => ['required', 'string', 'max:190'],
                'incidentType' => ['required', 'string', 'max:190'],
                'location' => ['required', 'string', 'max:190'],
                'details' => ['required', 'string', 'max:20000'],
                'summary' => ['required', 'string', 'max:20000'],
                'chronology' => ['required', 'array', 'min:1', 'max:250'],
                'chronology.*.time' => ['required', 'date_format:H:i'],
                'chronology.*.action' => ['required', 'string', 'max:4000'],
            ]);
        }

        $validator = Validator::make($payload, $rules);
        $validator->after(function ($validator) use ($payload, $forSubmit): void {
            $this->validatePhotoCollection($validator, $payload);
            if ($forSubmit) {
                $this->validateErpPairs($validator, $payload);
                $this->validateExclusiveRoles($validator, $payload);
            }
        });
        $validator->validate();
    }

    private function validatePhotoCollection($validator, array $payload): void
    {
        if (is_array($payload['photos'] ?? null) && $payload['photos'] !== []) {
            $validator->errors()->add(
                'photos',
                'Drill V2 photos must be stored in postIncidentAnalysis.photos.',
            );
        }

        $photos = data_get($payload, 'postIncidentAnalysis.photos', []);
        if (! is_array($photos)) {
            return;
        }

        $seen = [];
        foreach ($photos as $index => $photo) {
            if (! is_array($photo)) {
                continue;
            }
            $mediaId = trim((string) ($photo['mediaId'] ?? ''));
            if ($mediaId !== '') {
                if (isset($seen[$mediaId])) {
                    $validator->errors()->add(
                        "postIncidentAnalysis.photos.{$index}.mediaId",
                        'Each managed Drill photo may be referenced only once.',
                    );
                }
                $seen[$mediaId] = true;
            }

            $url = trim((string) ($photo['url'] ?? ''));
            if ($url !== '' && ! $this->urlMatchesMediaId($url, $mediaId)) {
                $validator->errors()->add(
                    "postIncidentAnalysis.photos.{$index}.url",
                    'Drill V2 photo URLs must reference their managed report media ID.',
                );
            }
        }
    }

    private function validateErpPairs($validator, array $payload): void
    {
        $rows = is_array($payload['erpReferences'] ?? null) ? $payload['erpReferences'] : [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $number = trim((string) ($row['annexNumber'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            if (($number === '') !== ($title === '')) {
                $validator->errors()->add(
                    "erpReferences.{$index}",
                    'Enter both the ERP/Annex number and title.',
                );
            }
        }
    }

    private function validateExclusiveRoles($validator, array $payload): void
    {
        $rows = data_get($payload, 'respondingTeam.attendance');
        if (! is_array($rows)) {
            $rows = is_array($payload['respondingAttendance'] ?? null)
                ? $payload['respondingAttendance']
                : [];
        }

        $counts = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['present'] ?? true) === false) {
                continue;
            }
            $role = strtoupper(trim((string) ($row['exerciseRole'] ?? '')));
            if (in_array($role, self::EXCLUSIVE_ROLES, true)) {
                $counts[$role] = ($counts[$role] ?? 0) + 1;
            }
        }
        $duplicates = array_keys(array_filter($counts, fn (int $count): bool => $count > 1));
        if ($duplicates !== []) {
            $validator->errors()->add(
                'respondingTeam.attendance',
                'Assign each command/TRT role once. Duplicated: '.implode(', ', $duplicates).'.',
            );
        }
    }

    private function urlMatchesMediaId(string $url, string $mediaId): bool
    {
        if ($mediaId === '') {
            return false;
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) {
            return false;
        }

        return str_ends_with(rtrim($path, '/'), '/report-media/'.rawurlencode($mediaId))
            || str_ends_with(rtrim($path, '/'), '/report-media/'.$mediaId);
    }
}
