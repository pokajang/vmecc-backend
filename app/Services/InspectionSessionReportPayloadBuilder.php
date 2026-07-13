<?php

namespace App\Services;

use App\Models\InspectionExtinguisherResult;
use App\Models\InspectionSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class InspectionSessionReportPayloadBuilder
{
    private const FIRE_EXTINGUISHER_TYPE = 'Fire Extinguisher Inspection';

    private const CHECKLIST_FIELDS = [
        'physicalCondition' => 'Physical Condition',
        'signageCondition' => 'Signage Condition',
        'boxKeyAvailability' => 'Box Key Availability',
        'boxGlassAvailability' => 'Box Glass Availability',
        'operationalCondition' => 'Operational Condition',
    ];

    public function build(InspectionSession $session, iterable $completedResults, Carbon $submittedAt): array
    {
        $checks = collect($completedResults)
            ->map(fn (InspectionExtinguisherResult $result): array => array_merge(
                is_array($result->check_payload) ? $result->check_payload : [],
                [
                    'inspectionSessionUid' => $session->session_uid,
                    'inspectionResultId' => $result->id,
                    'checkedBy' => $result->checkedBy?->name ?? '',
                    'checkedAt' => $result->checked_at?->toIso8601String() ?? '',
                ],
            ))
            ->values()
            ->all();
        $derived = $this->deriveFromChecks($checks);
        $scope = is_array($session->scope) ? $session->scope : [];

        return [
            'inspectionSessionUid' => $session->session_uid,
            'compiledAt' => $submittedAt->toIso8601String(),
            'inspectedAt' => $submittedAt->toIso8601String(),
            'incidentType' => self::FIRE_EXTINGUISHER_TYPE,
            'inspectionType' => self::FIRE_EXTINGUISHER_TYPE,
            ...$derived,
            'fireExtinguisherInspectedBy' => $session->startedBy?->name ?? '',
            'fireExtinguisherInspectionDate' => $submittedAt->toDateString(),
            'description' => sprintf(
                'Fire extinguisher inspection session %s. %d extinguisher(s) checked.',
                $session->session_uid,
                count($checks),
            ),
            'reportRemarks' => '',
            'photos' => [],
            'fireExtinguisherChecks' => $checks,
            'checklist' => $this->compiledChecklist($checks),
            'summary' => [
                'checkedCount' => count($checks),
                'locationCount' => count($derived['inspectionLocations']),
                'itemEvidencePhotoCount' => $derived['itemEvidencePhotoCount'],
                'generalPhotoCount' => 0,
                'evidencePhotoCount' => $derived['evidencePhotoCount'],
                'scope' => $scope,
            ],
        ];
    }

    public function normalizeDerivedFields(array $payload): array
    {
        $checks = $payload['fireExtinguisherChecks'] ?? $payload['fire_extinguisher_checks'] ?? [];
        if (! is_array($checks) || $checks === []) {
            return $payload;
        }

        $derived = $this->deriveFromChecks($checks);
        $generalPhotoCount = $this->evidencePhotoCount(['photos' => $payload['photos'] ?? []]);
        $totalEvidencePhotoCount = $this->evidencePhotoCount($payload);
        $derived['generalPhotoCount'] = $generalPhotoCount;
        $derived['evidencePhotoCount'] = $totalEvidencePhotoCount;
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        return array_replace($payload, $derived, [
            'summary' => array_replace($summary, [
                'checkedCount' => count($checks),
                'locationCount' => count($derived['inspectionLocations']),
                'itemEvidencePhotoCount' => $derived['itemEvidencePhotoCount'],
                'generalPhotoCount' => $generalPhotoCount,
                'evidencePhotoCount' => $totalEvidencePhotoCount,
            ]),
        ]);
    }

    public function isSessionFireExtinguisherPayload(array $payload): bool
    {
        $sessionUid = $this->text($payload['inspectionSessionUid'] ?? $payload['inspection_session_uid'] ?? '');
        $type = $this->text($payload['incidentType'] ?? '');
        if ($type === '') {
            $type = $this->text($payload['inspectionType'] ?? '');
        }

        return $sessionUid !== '' && Str::slug($type) === 'fire-extinguisher-inspection';
    }

    private function deriveFromChecks(array $checks): array
    {
        $locations = $this->locations($checks);
        $mainLocations = $this->uniqueValues($locations, 'mainLocation');
        $zones = $this->uniqueValues($locations, 'zone');
        $itemEvidencePhotoCount = $this->evidencePhotoCount($checks);
        $locationSummary = $this->locationSummary($locations);
        $allHaveZones = $this->allHaveValue($locations, 'zone');
        $allHaveMainLocations = $this->allHaveValue($locations, 'mainLocation');

        return [
            'location' => $locationSummary,
            'selectedLocation' => $locationSummary,
            'zone' => $allHaveZones && count($zones) === 1 ? $zones[0] : '',
            'mainLocation' => $allHaveMainLocations && count($mainLocations) === 1 ? $mainLocations[0] : '',
            'subLocation' => count($locations) === 1 ? $locations[0]['subLocation'] : '',
            'locationPath' => count($locations) === 1
                ? array_values(array_filter([
                    $this->zoneLabel($locations[0]['zone']),
                    $locations[0]['mainLocation'],
                    $locations[0]['subLocation'],
                ]))
                : [],
            'inspectionLocations' => $locations,
            'itemEvidencePhotoCount' => $itemEvidencePhotoCount,
            'generalPhotoCount' => 0,
            'evidencePhotoCount' => $itemEvidencePhotoCount,
        ];
    }

    private function locations(array $checks): array
    {
        $locations = [];
        foreach ($checks as $check) {
            if (! is_array($check)) {
                continue;
            }
            $location = [
                'zone' => $this->text($check['zone'] ?? ''),
                'mainLocation' => $this->text($check['mainLocation'] ?? $check['main_location'] ?? $check['location'] ?? ''),
                'subLocation' => $this->text($check['subLocation'] ?? $check['sub_location'] ?? ''),
            ];
            if ($location['zone'] === '' && $location['mainLocation'] === '' && $location['subLocation'] === '') {
                continue;
            }
            $key = collect($location)->map(fn (string $value): string => Str::lower($value))->implode("\0");
            $locations[$key] ??= $location;
        }

        $locations = array_values($locations);
        usort($locations, fn (array $left, array $right): int => strnatcasecmp(
            implode('|', $left),
            implode('|', $right),
        ));

        return $locations;
    }

    private function locationSummary(array $locations): string
    {
        if ($locations === []) {
            return '';
        }
        if (count($locations) === 1) {
            return implode(' > ', array_filter([
                $this->zoneLabel($locations[0]['zone']),
                $locations[0]['mainLocation'],
                $locations[0]['subLocation'],
            ]));
        }

        $zones = $this->uniqueValues($locations, 'zone');
        $mainLocations = $this->uniqueValues($locations, 'mainLocation');
        $allHaveZones = $this->allHaveValue($locations, 'zone');
        $allHaveMainLocations = $this->allHaveValue($locations, 'mainLocation');
        if ($allHaveMainLocations && count($mainLocations) === 1) {
            return implode(' > ', array_filter([
                $allHaveZones && count($zones) === 1 ? $this->zoneLabel($zones[0]) : '',
                $mainLocations[0],
            ])).' · '.count($locations).' locations';
        }
        if (! $allHaveMainLocations) {
            return sprintf('%d inspection locations', count($locations));
        }

        return sprintf('%d locations across %d areas', count($locations), count($mainLocations));
    }

    private function uniqueValues(array $rows, string $key): array
    {
        $values = [];
        foreach ($rows as $row) {
            $value = $this->text($row[$key] ?? '');
            if ($value !== '') {
                $values[Str::lower($value)] ??= $value;
            }
        }

        return array_values($values);
    }

    private function allHaveValue(array $rows, string $key): bool
    {
        return $rows !== [] && collect($rows)->every(
            fn (array $row): bool => $this->text($row[$key] ?? '') !== '',
        );
    }

    private function evidencePhotoCount(array $checks): int
    {
        $identities = [];
        $walk = function (mixed $node, string $key = '') use (&$walk, &$identities): void {
            if (! is_array($node)) {
                return;
            }
            if (preg_match('/(^photos$|photos$|_photos$)/i', $key) === 1) {
                foreach ($node as $photo) {
                    if (! is_array($photo)) {
                        continue;
                    }
                    $mediaId = $this->text($photo['mediaId'] ?? $photo['media_id'] ?? '');
                    $id = $this->text($photo['id'] ?? $photo['photoId'] ?? $photo['photo_id'] ?? '');
                    $url = $this->text($photo['url'] ?? $photo['dataUrl'] ?? $photo['data_url'] ?? '');
                    if ($mediaId === '' && $id === '' && $url === '') {
                        continue;
                    }
                    $identity = $mediaId !== '' ? 'media:'.$mediaId : ($id !== '' ? 'id:'.$id : 'url:'.$url);
                    $identities[$identity] = true;
                }

                return;
            }
            foreach ($node as $childKey => $child) {
                $walk($child, (string) $childKey);
            }
        };
        $walk($checks);

        return count($identities);
    }

    private function compiledChecklist(array $checks): array
    {
        return collect(self::CHECKLIST_FIELDS)
            ->map(fn (string $label, string $key): array => [
                'id' => 'fire-extinguisher-'.Str::slug($key),
                'label' => $label,
                'selected' => collect($checks)->contains(
                    fn (array $row): bool => $this->text($row[$key] ?? '') !== '',
                ),
            ])
            ->values()
            ->all();
    }

    private function zoneLabel(string $zone): string
    {
        if ($zone === '' || Str::startsWith(Str::lower($zone), 'zone ')) {
            return $zone;
        }

        return 'Zone '.$zone;
    }

    private function text(mixed $value): string
    {
        return Str::of((string) $value)->squish()->toString();
    }
}
