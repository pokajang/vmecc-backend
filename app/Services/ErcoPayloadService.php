<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;

final class ErcoPayloadService
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

    private function validate(array $payload, bool $forSubmit): void
    {
        $rules = [
            'schemaVersion' => [$forSubmit ? 'required' : 'nullable', 'integer', 'in:'.self::SCHEMA_VERSION],
            'incidentDate' => ['nullable', 'date_format:Y-m-d'],
            'incidentTime' => ['nullable', 'date_format:H:i'],
            'reportDate' => ['nullable', 'date_format:Y-m-d'],
            'reportTime' => ['nullable', 'date_format:H:i'],
            'weather' => ['nullable', 'string', 'max:190'],
            'incidentType' => ['nullable', 'string', 'max:190'],
            'location' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail) use ($forSubmit): void {
                    if ($forSubmit && ! is_string($value)) {
                        $fail('The location field must be a string.');

                        return;
                    }
                    if (! is_string($value) && ! is_array($value)) {
                        $fail('The location field must be a string or a list of locations.');

                        return;
                    }
                    if (is_string($value) && mb_strlen($value) > 1000) {
                        $fail('The location field must not exceed 1000 characters.');
                    }
                    if (is_array($value) && collect($value)->contains(
                        fn ($item) => ! is_string($item) || trim($item) === '' || mb_strlen($item) > 190
                    )) {
                        $fail('Each location must be a non-empty string of 190 characters or fewer.');
                    }
                },
            ],
            'details' => ['nullable', 'string', 'max:20000'],
            'detailsSource' => ['nullable', 'string', 'max:80'],
            'summary' => ['nullable', 'string', 'max:20000'],
            'respondingTeam' => ['nullable', 'array'],
            'respondingTeam.name' => ['nullable', 'string', 'max:500'],
            'respondingTeam.shift' => ['nullable', 'string', 'max:190'],
            'respondingTeam.attendance' => ['nullable', 'array', 'max:100'],
            'respondingTeam.attendance.*' => ['array'],
            'respondingTeam.attendance.*.memberId' => ['nullable', 'string', 'max:190'],
            'respondingTeam.attendance.*.name' => ['nullable', 'string', 'max:190'],
            'respondingTeam.attendance.*.role' => ['nullable', 'string', 'max:190'],
            'chronology' => ['nullable', 'array', 'max:250'],
            'chronology.*' => ['array'],
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
            'postIncidentAnalysis.photos.*.mediaId' => ['nullable', 'string', 'max:80'],
            'postIncidentAnalysis.photos.*.url' => ['required', 'string', 'max:2097152'],
            'postIncidentAnalysis.photos.*.fileName' => ['nullable', 'string', 'max:255'],
            'postIncidentAnalysis.photos.*.description' => ['nullable', 'string', 'max:2000'],
        ];

        if ($forSubmit) {
            $rules = array_replace($rules, [
                'incidentDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'incidentTime' => ['required', 'date_format:H:i'],
                'weather' => ['required', 'string', 'max:190'],
                'incidentType' => ['required', 'string', 'max:190'],
                'location' => ['required', 'string', 'max:1000'],
                'details' => ['required', 'string', 'max:20000'],
                'summary' => ['required', 'string', 'max:20000'],
                'respondingTeam' => ['required', 'array'],
                'respondingTeam.attendance' => ['required', 'array', 'min:1', 'max:100'],
                'chronology' => ['required', 'array', 'min:1', 'max:250'],
                'chronology.*.time' => ['required', 'date_format:H:i'],
                'chronology.*.action' => ['required', 'string', 'max:4000'],
                'postIncidentAnalysis' => ['required', 'array'],
                'postIncidentAnalysis.strengths' => ['required', 'array', 'min:1', 'max:50'],
                'postIncidentAnalysis.strengths.*' => ['required', 'string', 'max:2000'],
                'postIncidentAnalysis.photos' => ['required', 'array', 'min:1', 'max:10'],
            ]);
        }

        $validator = Validator::make($payload, $rules);
        $validator->after(function ($validator) use ($payload, $forSubmit): void {
            if ($forSubmit) {
                $incidentDate = trim((string) ($payload['incidentDate'] ?? ''));
                $incidentTime = trim((string) ($payload['incidentTime'] ?? ''));
                if (
                    $incidentDate === now()->format('Y-m-d')
                    && preg_match('/^\d{2}:\d{2}$/', $incidentTime)
                    && $incidentTime > now()->format('H:i')
                ) {
                    $validator->errors()->add(
                        'incidentTime',
                        'The incident time cannot be in the future.',
                    );
                }

                $attendance = data_get($payload, 'respondingTeam.attendance', []);
                $hasResponder = is_array($attendance) && collect($attendance)->contains(
                    fn ($row) => is_array($row)
                        && (trim((string) ($row['memberId'] ?? '')) !== '' || trim((string) ($row['name'] ?? '')) !== '')
                );
                if (! $hasResponder) {
                    $validator->errors()->add('respondingTeam.attendance', 'At least one responding member is required.');
                }
            }

            $seenMediaIds = [];
            $photos = data_get($payload, 'postIncidentAnalysis.photos', []);
            foreach (is_array($photos) ? $photos : [] as $index => $photo) {
                if (! is_array($photo)) {
                    continue;
                }
                $mediaId = trim((string) ($photo['mediaId'] ?? ''));
                $url = trim((string) ($photo['url'] ?? ''));
                if ($mediaId === '') {
                    if (! preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,(.+)$/is', $url, $match)) {
                        $validator->errors()->add(
                            "postIncidentAnalysis.photos.{$index}.url",
                            'ERCO photos must use managed report media or a legacy inline image.',
                        );
                    } else {
                        $decoded = base64_decode((string) preg_replace('/\s+/', '', $match[2]), true);
                        if ($decoded === false || strlen($decoded) > 1572864) {
                            $validator->errors()->add(
                                "postIncidentAnalysis.photos.{$index}.url",
                                'Legacy inline ERCO photos must be valid and 1.5 MB or smaller.',
                            );
                        }
                    }
                } elseif (! $this->urlMatchesMediaId($url, $mediaId)) {
                    $validator->errors()->add(
                        "postIncidentAnalysis.photos.{$index}.url",
                        'Managed ERCO photo URLs must reference their media ID.',
                    );
                }
                if ($mediaId === '' || ! isset($seenMediaIds[$mediaId])) {
                    if ($mediaId !== '') {
                        $seenMediaIds[$mediaId] = true;
                    }

                    continue;
                }
                $validator->errors()->add(
                    "postIncidentAnalysis.photos.{$index}.mediaId",
                    'Each managed ERCO photo may be referenced only once.',
                );
            }
        });
        $validator->validate();
    }

    private function urlMatchesMediaId(string $url, string $mediaId): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) {
            return false;
        }

        return str_ends_with(rtrim($path, '/'), '/report-media/'.rawurlencode($mediaId))
            || str_ends_with(rtrim($path, '/'), '/report-media/'.$mediaId);
    }
}
