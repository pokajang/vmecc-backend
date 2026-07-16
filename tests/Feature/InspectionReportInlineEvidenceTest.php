<?php

namespace Tests\Feature;

use Tests\TestCase;

class InspectionReportInlineEvidenceTest extends TestCase
{
    public function test_item_evidence_is_rendered_between_its_check_row_and_the_next_row_for_all_equipment_inspections(): void
    {
        $photo = [[
            'description' => 'Inline defect photo.',
            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
        ]];

        $scenarios = [
            'fire extinguisher' => [
                'record' => $this->record('Fire Extinguisher Inspection', [
                    'fireExtinguisherChecks' => [
                        [
                            'idLocNo' => 'FE-INLINE-FIRST',
                            'barcodeNo' => 'BAR-FIRST',
                            'physicalCondition' => 'Not Good',
                            'physicalConditionRemarks' => 'Dented cylinder.',
                            'physicalConditionPhotos' => $photo,
                        ],
                        [
                            'idLocNo' => 'FE-INLINE-SECOND',
                            'barcodeNo' => 'BAR-SECOND',
                            'physicalCondition' => 'Good',
                        ],
                    ],
                ]),
                'row' => 'FE-INLINE-FIRST',
                'evidence' => 'Defect Evidence: FE-INLINE-FIRST - FE Physical Condition',
                'next' => 'FE-INLINE-SECOND',
            ],
            'hydraulic' => [
                'record' => $this->record('Hydraulic Rescue Tools Inspection', [
                    'hydraulicChecks' => [
                        [
                            'equipment' => 'HYD-INLINE-FIRST',
                            'physicalCondition' => 'Defect',
                            'physicalConditionRemarks' => 'Cracked housing.',
                            'physicalConditionPhotos' => $photo,
                        ],
                        ['equipment' => 'HYD-INLINE-SECOND', 'physicalCondition' => 'OK'],
                    ],
                ]),
                'row' => 'HYD-INLINE-FIRST',
                'evidence' => 'Defect Evidence: HYD-INLINE-FIRST - Physical Condition',
                'next' => 'HYD-INLINE-SECOND',
            ],
            'ER Aux' => [
                'record' => $this->record('ER Aux Equipment Inspection', [
                    'erAuxChecks' => [
                        [
                            'equipment' => 'ER-AUX-INLINE-FIRST',
                            'condition' => 'Defect',
                            'defectRemarks' => 'Damaged connector.',
                            'defectPhotos' => $photo,
                        ],
                        ['equipment' => 'ER-AUX-INLINE-SECOND', 'condition' => 'OK'],
                    ],
                ]),
                'row' => 'ER-AUX-INLINE-FIRST',
                'evidence' => 'Defect Evidence: ER-AUX-INLINE-FIRST',
                'next' => 'ER-AUX-INLINE-SECOND',
            ],
            'High Angle' => [
                'record' => $this->record('High Angle Rescue Equipment Inspection', [
                    'highAngleChecks' => [
                        [
                            'rowNumber' => '1',
                            'mainLocation' => 'Kit A',
                            'location' => 'Bag A',
                            'equipment' => 'HIGH-ANGLE-INLINE-FIRST',
                            'condition' => 'Not Good',
                            'conditionRemarks' => 'Gate sticks.',
                            'conditionPhotos' => $photo,
                        ],
                        [
                            'rowNumber' => '2',
                            'mainLocation' => 'Kit A',
                            'location' => 'Bag A',
                            'equipment' => 'HIGH-ANGLE-INLINE-SECOND',
                            'condition' => 'Good',
                        ],
                    ],
                ]),
                'row' => 'HIGH-ANGLE-INLINE-FIRST',
                'evidence' => 'Issue Evidence: HIGH-ANGLE-INLINE-FIRST',
                'next' => 'HIGH-ANGLE-INLINE-SECOND',
            ],
            'SCBA' => [
                'record' => $this->record('SCBA Inspection', [
                    'scbaBackPlateChecks' => [
                        [
                            'brand' => 'SCBA-INLINE-FIRST',
                            'serialNo' => 'SCBA-01',
                            'highPressureHose' => 'Not Good',
                            'highPressureHoseRemarks' => 'Coupling worn.',
                            'highPressureHosePhotos' => $photo,
                        ],
                        [
                            'brand' => 'SCBA-INLINE-SECOND',
                            'serialNo' => 'SCBA-02',
                            'highPressureHose' => 'Good',
                        ],
                    ],
                ]),
                'row' => 'SCBA-INLINE-FIRST',
                'evidence' => 'Issue Evidence: SCBA-INLINE-FIRST SCBA-01 - High Pressure Hose',
                'next' => 'SCBA-INLINE-SECOND',
            ],
        ];

        foreach ($scenarios as $type => $scenario) {
            $html = view('pdf.inspection_report', ['record' => $scenario['record']])->render();

            $this->assertInlineOrder(
                $html,
                $scenario['row'],
                $scenario['evidence'],
                $scenario['next'],
                $type,
            );
        }
    }

    public function test_frt_daily_and_one_off_evidence_is_inline_with_each_source_row(): void
    {
        $photo = [[
            'description' => 'Inline FRT issue photo.',
            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
        ]];
        $record = $this->record('FRT Daily Inspection', [
            'frtDailyChecks' => [
                ['rowNumber' => '1', 'location' => 'Locker', 'equipment' => 'FRT-DAILY-INLINE-FIRST', 'status' => 'Issue', 'remarks' => 'Damaged.', 'photos' => $photo],
                ['rowNumber' => '2', 'location' => 'Locker', 'equipment' => 'FRT-DAILY-INLINE-SECOND', 'status' => 'Checked'],
            ],
            'frtOneOffChecks' => [
                ['rowNumber' => '1', 'location' => 'Truck', 'equipment' => 'FRT-ONE-OFF-INLINE-FIRST', 'condition' => 'Not Good', 'remarks' => 'Switch sticks.', 'photos' => $photo],
                ['rowNumber' => '2', 'location' => 'Truck', 'equipment' => 'FRT-ONE-OFF-INLINE-SECOND', 'condition' => 'Good'],
            ],
        ]);

        $html = view('pdf.inspection_report', ['record' => $record])->render();

        $this->assertInlineOrder($html, 'FRT-DAILY-INLINE-FIRST', 'Issue Evidence - Row 1: FRT-DAILY-INLINE-FIRST', 'FRT-DAILY-INLINE-SECOND', 'FRT daily');
        $this->assertInlineOrder($html, 'FRT-ONE-OFF-INLINE-FIRST', 'Issue Evidence - Row 1: FRT-ONE-OFF-INLINE-FIRST', 'FRT-ONE-OFF-INLINE-SECOND', 'FRT one-off');
    }

    public function test_general_finding_photos_remain_inside_their_source_finding(): void
    {
        $photo = fn (string $description): array => [
            'description' => $description,
            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
        ];
        $record = $this->record('General Inspection', [
            'inspectionIssues' => [
                [
                    'description' => 'GENERAL-INLINE-FIRST-FINDING',
                    'photos' => [$photo('First finding photo.')],
                ],
                [
                    'description' => 'GENERAL-INLINE-SECOND-FINDING',
                    'photos' => [$photo('Second finding photo.')],
                ],
            ],
        ]);

        $html = view('pdf.inspection_report', ['record' => $record])->render();
        $firstFinding = strpos($html, 'GENERAL-INLINE-FIRST-FINDING');
        $firstPhotos = strpos($html, 'Finding Photos - Finding 1');
        $secondFinding = strpos($html, 'GENERAL-INLINE-SECOND-FINDING');

        $this->assertNotFalse($firstFinding);
        $this->assertNotFalse($firstPhotos);
        $this->assertNotFalse($secondFinding);
        $this->assertLessThan($firstPhotos, $firstFinding);
        $this->assertLessThan($secondFinding, $firstPhotos);
        $this->assertSame(2, substr_count($html, 'class="issue-block"'));
    }

    private function record(string $type, array $data): array
    {
        return array_merge([
            'displayId' => 'INS-INLINE-EVIDENCE',
            'status' => 'Submitted',
            'incidentType' => $type,
            'location' => 'Audit location',
            'photos' => [],
        ], $data);
    }

    private function assertInlineOrder(string $html, string $row, string $evidence, string $next, string $type): void
    {
        $rowPosition = strpos($html, $row);
        $evidencePosition = strpos($html, $evidence);
        $nextPosition = strpos($html, $next);

        $this->assertNotFalse($rowPosition, "{$type} source row was not rendered.");
        $this->assertNotFalse($evidencePosition, "{$type} evidence was not rendered.");
        $this->assertNotFalse($nextPosition, "{$type} following row was not rendered.");
        $this->assertLessThan($evidencePosition, $rowPosition, "{$type} evidence must follow its source row.");
        $this->assertLessThan($nextPosition, $evidencePosition, "{$type} evidence must precede the next check row.");
        $this->assertStringContainsString('inspection-check-evidence-row', $html);
    }
}
