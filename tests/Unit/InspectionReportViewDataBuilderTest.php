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
}
