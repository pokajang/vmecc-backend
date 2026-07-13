<?php

namespace Tests\Unit;

use App\Models\InspectionExtinguisherResult;
use App\Models\InspectionSession;
use App\Services\InspectionSessionReportPayloadBuilder;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InspectionSessionReportPayloadBuilderTest extends TestCase
{
    public function test_it_derives_multiple_locations_and_counts_unique_nested_evidence(): void
    {
        $photo = ['mediaId' => 'rpm-one', 'url' => '/api/report-media/rpm-one'];
        $checks = [
            $this->makeResult('1', 'Manjung Hub', 'Reception', ['physicalConditionPhotos' => [$photo]]),
            $this->makeResult('1', 'Manjung Hub', 'Workshop', [
                'operationalConditionPhotos' => [$photo, ['id' => 'legacy-two', 'url' => 'data:image/jpeg;base64,AA==']],
            ]),
        ];
        $session = new InspectionSession([
            'session_uid' => 'inspection-session-test',
            'scope' => ['scopeVersion' => 'v2', 'teamId' => 4],
        ]);

        $payload = app(InspectionSessionReportPayloadBuilder::class)->build(
            $session,
            $checks,
            Carbon::parse('2026-07-13T09:00:00+08:00'),
        );

        $this->assertSame('Zone 1 > Manjung Hub · 2 locations', $payload['location']);
        $this->assertSame('Manjung Hub', $payload['mainLocation']);
        $this->assertSame('', $payload['subLocation']);
        $this->assertCount(2, $payload['inspectionLocations']);
        $this->assertSame(2, $payload['evidencePhotoCount']);
        $this->assertSame(2, $payload['itemEvidencePhotoCount']);
        $this->assertSame(0, $payload['generalPhotoCount']);
        $this->assertSame(2, $payload['summary']['evidencePhotoCount']);
        $this->assertSame($photo, $payload['fireExtinguisherChecks'][0]['physicalConditionPhotos'][0]);
    }

    public function test_normalization_replaces_stale_derived_fields_without_touching_checks(): void
    {
        $checks = [[
            'zone' => '2',
            'mainLocation' => 'Pump House',
            'subLocation' => 'Entrance',
            'photos' => [],
        ]];
        $payload = [
            'inspectionSessionUid' => 'inspection-session-test',
            'incidentType' => 'Fire Extinguisher Inspection',
            'location' => 'Stale location',
            'photos' => [[
                'id' => 'general-photo',
                'url' => '/api/report-media/rpm-general-photo',
            ]],
            'fireExtinguisherChecks' => $checks,
        ];

        $normalized = app(InspectionSessionReportPayloadBuilder::class)->normalizeDerivedFields($payload);

        $this->assertSame('Zone 2 > Pump House > Entrance', $normalized['location']);
        $this->assertSame(1, $normalized['generalPhotoCount']);
        $this->assertSame(1, $normalized['evidencePhotoCount']);
        $this->assertSame($checks, $normalized['fireExtinguisherChecks']);
    }

    public function test_it_uses_a_neutral_summary_when_some_rows_have_incomplete_location_parts(): void
    {
        $payload = app(InspectionSessionReportPayloadBuilder::class)->normalizeDerivedFields([
            'inspectionSessionUid' => 'inspection-session-incomplete-location',
            'incidentType' => 'Fire Extinguisher Inspection',
            'fireExtinguisherChecks' => [
                ['zone' => '1', 'mainLocation' => 'Manjung Hub', 'subLocation' => 'Reception'],
                ['zone' => '1', 'mainLocation' => '', 'subLocation' => 'External Bay'],
            ],
        ]);

        $this->assertSame('2 inspection locations', $payload['location']);
        $this->assertSame('', $payload['mainLocation']);
        $this->assertSame('', $payload['subLocation']);
    }

    private function makeResult(string $zone, string $mainLocation, string $subLocation, array $extra = []): InspectionExtinguisherResult
    {
        return new InspectionExtinguisherResult([
            'check_payload' => [
                'id' => 'fe-'.$subLocation,
                'zone' => $zone,
                'mainLocation' => $mainLocation,
                'subLocation' => $subLocation,
                'physicalCondition' => 'Good',
                ...$extra,
            ],
            'checked_at' => '2026-07-13T09:00:00+08:00',
        ]);
    }
}
