<?php

namespace App\Services;

use App\Models\InspectionExtinguisherResult;
use App\Models\InspectionSession;
use App\Services\InspectionReports\InspectionReportLocationService;
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

    public function __construct(
        private readonly InspectionReportLocationService $locationService,
    ) {}

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
        $locationData = $this->locationService->derive(array_map(
            $this->locationService->fromRow(...),
            array_values(array_filter($checks, 'is_array')),
        ));
        $locations = $locationData['locations'];
        $itemEvidencePhotoCount = $this->evidencePhotoCount($checks);

        return [
            'location' => $locationData['summary'],
            'selectedLocation' => $locationData['summary'],
            'zone' => $locationData['zone'],
            'mainLocation' => $locationData['mainLocation'],
            'subLocation' => $locationData['subLocation'],
            'locationPath' => count($locations) === 1
                ? $locationData['pathParts'][0]
                : [],
            'inspectionLocations' => $locations,
            'itemEvidencePhotoCount' => $itemEvidencePhotoCount,
            'generalPhotoCount' => 0,
            'evidencePhotoCount' => $itemEvidencePhotoCount,
        ];
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

    private function text(mixed $value): string
    {
        return Str::of((string) $value)->squish()->toString();
    }
}
