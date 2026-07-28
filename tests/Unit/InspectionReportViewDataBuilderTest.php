<?php

namespace Tests\Unit;

use App\Services\InspectionReports\InspectionReportType;
use App\Services\InspectionReports\InspectionReportTypeResolver;
use App\Services\InspectionReports\InspectionReportViewDataBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InspectionReportViewDataBuilderTest extends TestCase
{
    #[DataProvider('inspectionTypes')]
    public function test_it_resolves_supported_inspection_type_names(string $name, InspectionReportType $expected): void
    {
        $this->assertSame($expected, app(InspectionReportTypeResolver::class)->resolve($name));
    }

    public static function inspectionTypes(): array
    {
        return [
            'ER Aux' => ['ER Aux Equipment Inspection', InspectionReportType::ErAux],
            'historical ER Aux' => ['Emergency Response Auxiliary Inspection', InspectionReportType::ErAux],
            'fire extinguisher' => ['Fire Extinguisher Inspection', InspectionReportType::FireExtinguisher],
            'hydraulic' => ['Hydraulic Rescue Tools Inspection', InspectionReportType::Hydraulic],
            'FRT' => ['Fire Truck Daily Readiness', InspectionReportType::Frt],
            'high angle' => ['High Angle Rescue Equipment Inspection', InspectionReportType::HighAngle],
            'SCBA' => ['SCBA Inspection', InspectionReportType::Scba],
            'HSE' => ['HSE Inspection', InspectionReportType::Hse],
            'general' => ['General Inspection', InspectionReportType::General],
            'unknown' => ['Fire Pump House Inspection', InspectionReportType::Unknown],
        ];
    }

    public function test_it_builds_common_view_data_from_historical_field_aliases(): void
    {
        $viewData = app(InspectionReportViewDataBuilder::class)->build([
            'displayId' => 'INS-TEST-1',
            'status' => 'Reviewed',
            'inspection_type' => 'HSE Inspection',
            'selectedLocation' => 'Zone 1',
            'description' => 'Inspection complete.',
            'report_remarks' => 'Follow up tomorrow.',
        ]);

        $this->assertSame('INS-TEST-1', $viewData['displayId']);
        $this->assertSame('HSE Inspection', $viewData['inspectionType']);
        $this->assertSame('Zone 1', $viewData['location']);
        $this->assertSame('Follow up tomorrow.', $viewData['reportRemarks']);
        $this->assertTrue($viewData['reportEvidence']['visible']);
        $this->assertSame('Follow up tomorrow.', $viewData['reportEvidence']['remarks']);
        $this->assertTrue($viewData['isHseInspection']);
        $this->assertFalse($viewData['isGeneralInspection']);
    }

    public function test_it_prepares_filtered_section_rows_from_historical_aliases(): void
    {
        $viewData = app(InspectionReportViewDataBuilder::class)->build([
            'incidentType' => 'ER Aux Equipment Inspection',
            'checklist' => [
                ['label' => 'Matching item', 'inspectionType' => 'ER Aux Equipment Inspection'],
                ['label' => 'Other type item', 'inspectionType' => 'HSE Inspection'],
                ['label' => '', 'inspectionType' => 'ER Aux Equipment Inspection'],
            ],
            'er_aux_checks' => [
                ['equipment' => 'Portable Pump'],
                ['equipment' => ''],
                'invalid-row',
            ],
        ]);

        $this->assertSame('Matching item', $viewData['sections']['checklist'][0]['label']);
        $this->assertCount(1, $viewData['sections']['checklist']);
        $this->assertSame('Portable Pump', $viewData['sections']['erAuxChecks'][0]['equipment']);
        $this->assertCount(1, $viewData['sections']['erAuxChecks']);
    }

    public function test_it_preserves_partial_structured_report_rows_without_catalog_expansion(): void
    {
        $frtViewData = app(InspectionReportViewDataBuilder::class)->build([
            'incidentType' => 'FRT Daily Inspection',
            'location' => 'FIRE TRUCK',
            'frtDailyChecks' => [],
            'frtOneOffChecks' => [[
                'id' => 'one-off:fire-truck:45',
                'location' => 'CREW CABIN',
                'equipment' => 'BA SET : 4',
                'condition' => 'Good',
            ]],
        ]);

        $this->assertSame([], $frtViewData['sections']['frtDailyChecks']);
        $this->assertSame(
            ['one-off:fire-truck:45'],
            array_column($frtViewData['sections']['frtOneOffChecks'], 'id'),
        );

        $highAngleViewData = app(InspectionReportViewDataBuilder::class)->build([
            'incidentType' => 'High Angle Rescue Equipment Inspection',
            'highAngleChecks' => [[
                'id' => 'response-kit-1:3',
                'equipment' => 'Locking Carabiner',
                'mainLocation' => 'Response Kit #1',
                'location' => 'Heavy Duty Organizer Bag',
                'subLocation' => 'Main Compartment',
            ]],
        ]);

        $this->assertSame(
            ['response-kit-1:3'],
            array_column($highAngleViewData['sections']['highAngleChecks'], 'id'),
        );

        $scbaViewData = app(InspectionReportViewDataBuilder::class)->build([
            'incidentType' => 'SCBA Inspection',
            'scbaBackPlateChecks' => [[
                'id' => 'backPlate:frt:msa:06',
                'serialNo' => '06',
                'location' => 'FRT',
            ]],
            'scbaCylinderChecks' => [],
            'scbaFaceMaskChecks' => [],
        ]);

        $this->assertSame(
            ['backPlate:frt:msa:06'],
            array_column($scbaViewData['sections']['scbaBackPlateChecks'], 'id'),
        );
        $this->assertSame([], $scbaViewData['sections']['scbaCylinderChecks']);
        $this->assertSame([], $scbaViewData['sections']['scbaFaceMaskChecks']);
    }

    #[DataProvider('multiLocationInspectionRecords')]
    public function test_it_derives_multiple_locations_for_supported_row_based_types(
        array $record,
        string $expectedSummary,
        array $expectedPaths,
    ): void {
        $viewData = app(InspectionReportViewDataBuilder::class)->build($record);

        $this->assertSame($expectedSummary, $viewData['location']);
        $this->assertSame($expectedPaths, $viewData['inspectionLocationPaths']);
    }

    public static function multiLocationInspectionRecords(): array
    {
        return [
            'fire extinguisher' => [[
                'incidentType' => 'Fire Extinguisher Inspection',
                'fireExtinguisherChecks' => [
                    ['idLocNo' => 'FE-1', 'zone' => '1', 'mainLocation' => 'Hub', 'subLocation' => 'Reception'],
                    ['idLocNo' => 'FE-2', 'zone' => '1', 'mainLocation' => 'Hub', 'subLocation' => 'Workshop'],
                ],
            ], 'Zone 1 > Hub · 2 locations', [
                'Zone 1 > Hub > Reception',
                'Zone 1 > Hub > Workshop',
            ]],
            'hydraulic' => [[
                'incidentType' => 'Hydraulic Rescue Tools Inspection',
                'hydraulicChecks' => [
                    ['equipment' => 'Pump', 'location' => 'FRT Bay'],
                    ['equipment' => 'Cutter', 'location' => 'Rescue Store'],
                ],
            ], '2 locations across 2 areas', ['FRT Bay', 'Rescue Store']],
            'ER Aux' => [[
                'incidentType' => 'ER Aux Equipment Inspection',
                'erAuxChecks' => [
                    ['equipment' => 'Generator', 'location' => 'Aux Store'],
                    ['equipment' => 'Light', 'location' => 'Staging Bay'],
                ],
            ], '2 locations across 2 areas', ['Aux Store', 'Staging Bay']],
            'high angle' => [[
                'incidentType' => 'High Angle Rescue Equipment Inspection',
                'highAngleChecks' => [
                    ['equipment' => 'Rope', 'mainLocation' => 'Response Kit', 'location' => 'Rope Store', 'subLocation' => 'Compartment 1'],
                    ['equipment' => 'Harness', 'mainLocation' => 'Response Kit', 'location' => 'Rope Store', 'subLocation' => 'Compartment 2'],
                ],
            ], 'Response Kit · 2 locations', [
                'Response Kit > Rope Store > Compartment 1',
                'Response Kit > Rope Store > Compartment 2',
            ]],
            'SCBA including custom sections' => [[
                'incidentType' => 'SCBA Inspection',
                'scbaBackPlateChecks' => [['serialNo' => 'BP-1', 'location' => 'SCBA Room']],
                'scbaCustomSections' => [[
                    'title' => 'Telemetry',
                    'rows' => [['brand' => 'Telemetry', 'location' => 'Control Room']],
                ]],
            ], '2 locations across 2 areas', ['Control Room', 'SCBA Room']],
        ];
    }

    #[DataProvider('singleLocationInspectionRecords')]
    public function test_it_preserves_single_location_policies_for_excluded_types(array $record): void
    {
        $viewData = app(InspectionReportViewDataBuilder::class)->build($record);

        $this->assertSame($record['location'], $viewData['location']);
        $this->assertSame([], $viewData['inspectionLocations']);
        $this->assertSame([], $viewData['inspectionLocationPaths']);
    }

    public static function singleLocationInspectionRecords(): array
    {
        return [
            'HSE' => [[
                'incidentType' => 'HSE Inspection',
                'location' => 'Zone A > Dock',
                'erAuxChecks' => [['location' => 'Unrelated row location']],
            ]],
            'General' => [[
                'incidentType' => 'General Inspection',
                'location' => 'Main Yard',
                'hydraulicChecks' => [['location' => 'Unrelated row location']],
            ]],
            'FRT' => [[
                'incidentType' => 'FRT Daily Inspection',
                'location' => 'AJG9555',
                'frtDailyChecks' => [
                    ['location' => 'Locker 1'],
                    ['location' => 'Locker 2'],
                ],
            ]],
        ];
    }

    public function test_it_adds_complete_display_locations_to_equipment_rows(): void
    {
        $viewData = app(InspectionReportViewDataBuilder::class)->build([
            'incidentType' => 'Fire Extinguisher Inspection',
            'fireExtinguisherChecks' => [[
                'zone' => '2',
                'mainLocation' => 'Pump House',
                'subLocation' => 'Entrance',
                'idLocNo' => 'FE-1',
            ]],
        ]);

        $this->assertSame(
            'Zone 2 > Pump House > Entrance',
            $viewData['sections']['fireExtinguisherChecks'][0]['displayLocation'],
        );
    }

    public function test_it_uses_stored_locations_for_historical_row_based_reports(): void
    {
        $viewData = app(InspectionReportViewDataBuilder::class)->build([
            'incidentType' => 'Fire Extinguisher Inspection',
            'location' => 'Legacy summary',
            'fireExtinguisherChecks' => [['idLocNo' => 'FE-LEGACY']],
            'inspectionLocations' => [
                ['zone' => '1', 'mainLocation' => 'Hub', 'subLocation' => 'Reception'],
                ['zone' => '1', 'mainLocation' => 'Hub', 'subLocation' => 'Workshop'],
            ],
        ]);

        $this->assertSame('Zone 1 > Hub · 2 locations', $viewData['location']);
        $this->assertSame([
            'Zone 1 > Hub > Reception',
            'Zone 1 > Hub > Workshop',
        ], $viewData['inspectionLocationPaths']);
    }

    public function test_it_builds_the_current_hse_view_model_that_owns_report_evidence(): void
    {
        $viewData = app(InspectionReportViewDataBuilder::class)->build([
            'incidentType' => 'Health Safety Environment Inspection',
            'hsePayloadVersion' => 2,
            'selectedLocation' => 'Zone A > Dock',
            'inspectedAt' => '2026-07-14T09:30:00+08:00',
            'hseSelections' => ['unsafeCondition'],
            'hseUnsafeConditionDetails' => 'Open edge without protection.',
            'hseImmediateAction' => 'Access was stopped.',
            'photos' => [[
                'description' => 'Open edge',
                'url' => 'data:image/png;base64,AA==',
            ]],
        ]);

        $this->assertTrue($viewData['hse']['consumesReportEvidence']);
        $this->assertSame('Description', $viewData['hse']['details'][0]['label']);
        $this->assertSame('Immediate Corrective Action', $viewData['hse']['optional'][0]['label']);
        $this->assertSame(1, $viewData['hse']['photoCount']);
    }

    public function test_hse_v2_keeps_generic_evidence_fallback_when_no_photo_can_render(): void
    {
        $viewData = app(InspectionReportViewDataBuilder::class)->build([
            'incidentType' => 'Health Safety Environment Inspection',
            'hsePayloadVersion' => 2,
            'hseSelections' => ['unsafeAct'],
            'hseUnsafeActDetails' => 'Unsafe act imported from an incomplete historical record.',
            'reportRemarks' => 'The original image is unavailable; see the source record.',
            'photos' => [],
        ]);

        $this->assertFalse($viewData['hse']['consumesReportEvidence']);
        $this->assertTrue($viewData['reportEvidence']['visible']);
        $this->assertSame(0, $viewData['hse']['photoCount']);
    }
}
