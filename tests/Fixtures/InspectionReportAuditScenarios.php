<?php

namespace Tests\Fixtures;

class InspectionReportAuditScenarios
{
    private static array $imageCache = [];

    public static function all(): array
    {
        return [
            'general' => self::common('General Inspection', [
                'description' => str_repeat('General inspection summary with controlled long text. ', 8),
                'checklist' => [[
                    'label' => 'General inspection item with an intentionally long identifier '.str_repeat('ABC123', 8),
                    'inspectionType' => 'General Inspection',
                ]],
                'photos' => [
                    self::photo('First report-level photograph.', 'landscape'),
                    self::photo('Second report-level photograph.', 'portrait'),
                    self::photo('Third report-level photograph.'),
                    [
                        'description' => 'Caption retained when a legacy image is unavailable.',
                        'url' => 'https://example.com/blocked-legacy-image.jpg',
                    ],
                ],
            ]),
            'er-aux' => self::common('ER Aux Equipment Inspection', [
                'erAuxChecks' => [[
                    'location' => 'Emergency Store',
                    'equipment' => 'Portable auxiliary equipment '.str_repeat('LONG-ID-', 5),
                    'quantity' => '2',
                    'condition' => 'Defect',
                    'defectRemarks' => str_repeat('Damaged connector requires replacement. ', 6),
                    'defectPhotos' => [self::photo('ER Aux defect evidence.')],
                    'additionalNotes' => 'Replacement has been requested.',
                    'photos' => [self::photo('ER Aux additional evidence.', 'portrait')],
                ]],
            ]),
            'fire-extinguisher' => self::common('Fire Extinguisher Inspection', [
                'fireExtinguisherChecks' => [[
                    'idLocNo' => 'FE-'.str_repeat('1234567890', 5),
                    'barcodeNo' => 'BAR-'.str_repeat('9876543210', 4),
                    'location' => 'Process Area > Long Rack Name',
                    'feType' => 'CO2 5KG',
                    'physicalCondition' => 'Not Good',
                    'physicalConditionRemarks' => 'Cylinder body has visible damage.',
                    'physicalConditionPhotos' => [self::photo('Fire extinguisher defect evidence.')],
                    'signageCondition' => 'Good',
                    'boxKeyAvailability' => 'Yes',
                    'boxGlassAvailability' => 'Yes',
                    'operationalCondition' => 'Operational',
                ]],
            ]),
            'frt' => self::common('Fire Truck Daily Readiness', [
                'frtTruckReference' => ['plateNo' => 'AUDIT-FRT-01'],
                'frtDailyChecks' => self::frtRows(),
                'frtDailyRemarks' => str_repeat('Daily readiness remarks. ', 6),
                'frtOneOffChecks' => self::frtOneOffRows(),
            ]),
            'high-angle' => self::common('High Angle Rescue Equipment Inspection', [
                'highAngleChecks' => [[
                    'rowNumber' => '1',
                    'mainLocation' => 'Response Kit #1',
                    'location' => 'Organizer Bag',
                    'subLocation' => 'Main Compartment',
                    'equipment' => 'Locking carabiner with long manufacturer description '.str_repeat('X', 40),
                    'quantity' => '10',
                    'condition' => 'Not Good',
                    'remarks' => 'Gate spring is sticking.',
                    'conditionPhotos' => [self::photo('High Angle defect evidence.')],
                ]],
            ]),
            'hydraulic' => self::common('Hydraulic Rescue Tools Inspection', [
                'hydraulicChecks' => [[
                    'location' => 'FRT Rescue Bay',
                    'equipment' => 'Hydraulic combination tool '.str_repeat('SERIAL-', 6),
                    'physicalCondition' => 'Defect',
                    'physicalConditionRemarks' => 'Protective housing is cracked.',
                    'physicalConditionPhotos' => [self::photo('Hydraulic defect evidence.')],
                    'mechanicalCondition' => 'OK',
                    'noLeakage' => 'OK',
                    'functionTest' => 'OK',
                ]],
            ]),
            'scba' => self::common('SCBA Inspection', [
                'scbaBackPlateChecks' => [[
                    'location' => 'FRT',
                    'brand' => 'MSA',
                    'serialNo' => 'SCBA-'.str_repeat('9876543210', 4),
                    'backPlateHarnessCondition' => 'Good',
                    'highPressureHose' => 'Not Good',
                    'highPressureHoseRemarks' => 'Hose coupling is worn.',
                    'highPressureHosePhotos' => [self::photo('SCBA hose evidence.')],
                    'pressureGauge' => 'Good',
                    'alarmDevice' => 'Good',
                    'demandValve' => 'Good',
                    'sealing' => 'Good',
                    'cleanliness' => 'Good',
                ]],
            ]),
            'hse' => self::common('Health Safety Environment Inspection', [
                'hseSelections' => ['unsafeAct', 'environmental'],
                'hseUnsafeActDetails' => str_repeat('Worker entered a controlled area without authorization. ', 5),
                'hseEnvironmentalDetails' => str_repeat('Minor oil sheen observed near the protected drain. ', 5),
                'hseSeverity' => 'High',
                'hseImmediateAction' => 'Stopped work and isolated the area.',
                'hseCorrectiveAction' => str_repeat('Brief all contractors and verify corrective controls. ', 4),
                'hseResponsiblePerson' => 'Élodie François - Area Supervisor',
                'hseTargetDate' => '2026-07-20',
            ]),
            'hse-v2' => self::common('Health Safety Environment Inspection', [
                'displayId' => 'AUDIT-HSE-V2-LEAN-OBSERVATION',
                'hsePayloadVersion' => 2,
                'hseInspectedBy' => 'HSE Inspector',
                'hseInspectionDate' => '2026-07-13',
                'inspectedAt' => '2026-07-13T11:45:00+08:00',
                'hseSelections' => ['unsafeCondition'],
                'hseUnsafeActDetails' => 'Stale unsafe-act details must not render.',
                'hseUnsafeConditionDetails' => str_repeat(
                    'An open edge beside the access route was missing its protective barrier. ',
                    5,
                ),
                'hseSeverity' => 'Critical',
                'hseImmediateAction' => 'Stopped access and installed a temporary barrier.',
                'hseCorrectiveAction' => 'Legacy corrective action must not render.',
                'hseResponsiblePerson' => 'Legacy responsible person must not render.',
                'hseTargetDate' => '2026-07-20',
                'photos' => [self::photo('Unsafe condition observation.', 'portrait')],
            ]),
        ];
    }

    private static function common(string $type, array $typeData): array
    {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $type));

        return array_merge([
            'displayId' => 'AUDIT-'.strtoupper($key).'-'.str_repeat('LONG-ID-', 5),
            'status' => 'Submitted',
            'incidentType' => $type,
            'location' => 'Kawasan pemeriksaan - Zone A',
            'submittedBy' => 'Élodie François',
            'submittedAt' => '2026-07-13T12:30:00+08:00',
            'reportRemarks' => str_repeat('Whole-report remarks with façade and multilingual names. ', 5),
            'photos' => [
                self::photo('First report-level photograph.', 'landscape'),
                self::photo('Second report-level photograph.', 'portrait'),
                self::photo('Third report-level photograph.'),
            ],
        ], $typeData);
    }

    private static function photo(string $description, string $orientation = 'landscape'): array
    {
        return ['description' => $description, 'url' => self::imageData($orientation)];
    }

    private static function imageData(string $orientation): string
    {
        $orientation = $orientation === 'portrait' ? 'portrait' : 'landscape';
        if (isset(self::$imageCache[$orientation])) {
            return self::$imageCache[$orientation];
        }

        [$width, $height] = $orientation === 'portrait' ? [180, 320] : [320, 180];
        $image = imagecreatetruecolor($width, $height);
        if (! $image) {
            throw new \RuntimeException('Unable to create deterministic PDF audit image.');
        }
        $background = imagecolorallocate($image, $orientation === 'portrait' ? 38 : 12, 148, 143);
        $accent = imagecolorallocate($image, 219, 234, 254);
        $dark = imagecolorallocate($image, 30, 64, 175);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $background);
        imagefilledrectangle($image, 14, 14, $width - 15, $height - 15, $accent);
        imagefilledrectangle($image, 28, 28, $width - 29, $height - 29, $dark);
        imagestring($image, 5, 36, 40, strtoupper($orientation), $accent);
        imagestring($image, 3, 36, 62, $width.' x '.$height, $accent);

        ob_start();
        imagepng($image, null, 6);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return self::$imageCache[$orientation] = 'data:image/png;base64,'.base64_encode($bytes);
    }

    private static function frtRows(): array
    {
        $rows = [];
        for ($index = 1; $index <= 95; $index++) {
            $rows[] = [
                'rowNumber' => (string) $index,
                'location' => 'LOCKER '.str_pad((string) (int) ceil($index / 12), 2, '0', STR_PAD_LEFT),
                'equipment' => 'FRT audit equipment '.$index,
                'quantity' => '1',
                'rowKind' => 'status',
                'status' => $index === 1 ? 'Issue' : 'Checked',
                'remarks' => $index === 1 ? 'Issue retained for repair.' : '',
                'photos' => $index === 1 ? [self::photo('FRT item evidence.')] : [],
            ];
        }

        return $rows;
    }

    private static function frtOneOffRows(): array
    {
        return array_map(fn (int $index): array => [
            'rowNumber' => (string) $index,
            'location' => 'TRUCK CHECKLIST',
            'equipment' => 'One-off readiness item '.$index,
            'condition' => $index === 1 ? 'Not Good' : 'Good',
            'remarks' => $index === 1 ? 'Follow-up required.' : '',
            'photos' => $index === 1 ? [self::photo('FRT one-off evidence.')] : [],
        ], range(1, 24));
    }
}
