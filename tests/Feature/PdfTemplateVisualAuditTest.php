<?php

namespace Tests\Feature;

use App\Services\InspectionFireExtinguishers\FireExtinguisherExceptionPdfRenderer;
use App\Support\PdfDocumentOptions;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Smalot\PdfParser\Parser;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('pdf-audit')]
class PdfTemplateVisualAuditTest extends TestCase
{
    public function test_all_non_inspection_pdf_templates_render_visual_audit_artifacts(): void
    {
        if (! filter_var(env('PDF_TEMPLATE_VISUAL_AUDIT', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set PDF_TEMPLATE_VISUAL_AUDIT=1 to generate PDF template audit artifacts.');
        }

        $pdftoppm = (new ExecutableFinder)->find('pdftoppm');
        $this->assertNotNull($pdftoppm, 'Poppler pdftoppm is required for the visual PDF audit.');

        Carbon::setTestNow('2026-09-03 10:30:00');
        $outputDirectory = base_path('output/pdf/template-audit');
        File::ensureDirectoryExists($outputDirectory);
        File::cleanDirectory($outputDirectory);
        $baselinePath = base_path('tests/Fixtures/pdf-template-visual-baseline.json');
        $updateBaseline = filter_var(env('PDF_TEMPLATE_UPDATE_BASELINE', false), FILTER_VALIDATE_BOOL);
        $baseline = ! $updateBaseline && File::exists($baselinePath)
            ? json_decode(File::get($baselinePath), true, flags: JSON_THROW_ON_ERROR)
            : [];
        $manifest = [];

        try {
            foreach ($this->scenarios() as $name => $scenario) {
                $startedAt = microtime(true);
                $pdf = $scenario['render']();
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                $pdfPath = $outputDirectory.'/'.$name.'.pdf';
                File::put($pdfPath, $pdf);

                $pages = (new Parser)->parseContent($pdf)->getPages();
                $text = collect($pages)->map->getText()->implode("\n");
                $normalizedText = mb_strtoupper($text, 'UTF-8');
                $this->assertNotEmpty($pages, "{$name} produced no PDF pages.");
                $this->assertLessThan(10 * 1024 * 1024, strlen($pdf), "{$name} PDF exceeded 10 MB.");
                $this->assertLessThan(20_000, $durationMs, "{$name} PDF exceeded the audit render budget.");
                foreach ($scenario['requiredText'] as $requiredText) {
                    $this->assertStringContainsString(
                        mb_strtoupper($requiredText, 'UTF-8'),
                        $normalizedText,
                        "{$name} omitted {$requiredText}.",
                    );
                }
                foreach ($pages as $pageIndex => $page) {
                    $this->assertNotSame('', trim($page->getText()), "{$name} page ".($pageIndex + 1).' has no extractable text.');
                }

                $prefix = $outputDirectory.'/'.$name;
                (new Process([$pdftoppm, '-q', '-r', '144', '-png', $pdfPath, $prefix]))
                    ->setTimeout(60)
                    ->mustRun();
                $pngs = glob($prefix.'-*.png') ?: [];
                $this->assertCount(count($pages), $pngs, "{$name} PNG page count differs from its PDF.");
                $pngPages = [];
                foreach ($pngs as $pageIndex => $png) {
                    $dimensions = getimagesize($png);
                    $this->assertNotFalse($dimensions, "{$png} is not a readable PNG.");
                    $this->assertGreaterThan(1000, $dimensions[0]);
                    $this->assertGreaterThan(1400, $dimensions[1]);
                    $averageHash = $this->averageHash($png);
                    $pngPages[] = [
                        'file' => basename($png),
                        'width' => $dimensions[0],
                        'height' => $dimensions[1],
                        'averageHash' => $averageHash,
                    ];

                    if (isset($baseline[$name]['pngPages'][$pageIndex])) {
                        $expected = $baseline[$name]['pngPages'][$pageIndex];
                        $this->assertSame($expected['width'], $dimensions[0], "{$name} page width changed.");
                        $this->assertSame($expected['height'], $dimensions[1], "{$name} page height changed.");
                        $this->assertLessThanOrEqual(
                            8,
                            $this->hashDistance((string) $expected['averageHash'], $averageHash),
                            "{$name} page ".($pageIndex + 1).' differs materially from its visual baseline.',
                        );
                    }
                }
                if (isset($baseline[$name]['pages'])) {
                    $this->assertSame($baseline[$name]['pages'], count($pages), "{$name} page count changed.");
                }

                $manifest[$name] = [
                    'pages' => count($pages),
                    'pdfBytes' => strlen($pdf),
                    'renderDurationMs' => $durationMs,
                    'pdfSha256' => hash('sha256', $pdf),
                    'pngPages' => $pngPages,
                ];
            }
        } finally {
            Carbon::setTestNow();
        }

        File::put($outputDirectory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        if ($updateBaseline) {
            $candidate = [];
            foreach ($manifest as $name => $result) {
                $candidate[$name] = [
                    'pages' => $result['pages'],
                    'pngPages' => array_map(
                        fn (array $page): array => [
                            'width' => $page['width'],
                            'height' => $page['height'],
                            'averageHash' => $page['averageHash'],
                        ],
                        $result['pngPages'],
                    ),
                ];
            }
            File::put(
                $outputDirectory.'/visual-baseline-candidate.json',
                json_encode($candidate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
            );
        }
    }

    /** @return array<string, array{render: callable(): string, requiredText: array<int, string>}> */
    private function scenarios(): array
    {
        $pixel = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAUAAAAC0CAIAAAD6arQ9AAAACXBIWXMAAAsTAAALEwEAmpwYAAAAB3RJTUUH5gYIBSc1WgCgagAAAB1pVFh0Q29tbWVudAAAAAAAQ3JlYXRlZCB3aXRoIEdJTVBkLmUHAAAAUUlEQVR42u3BAQ0AAADCoPdPbQ43oAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA+Bs4AAAF7ASwCAAAAAElFTkSuQmCC';
        $render = fn (string $view, array $data): string => Pdf::loadView($view, $data)
            ->setPaper('a4')
            ->setOption(PdfDocumentOptions::secure())
            ->output(['compress' => 1]);

        $timeline = [
            ['action' => 'Submitted', 'by' => 'Élodie François', 'at' => '2026-09-03T08:00:00+08:00'],
            ['action' => 'Reviewed', 'by' => 'Review Officer', 'at' => '2026-09-03T09:00:00+08:00'],
            ['action' => 'Approved', 'by' => 'Approve Officer', 'at' => '2026-09-03T10:00:00+08:00'],
        ];

        $drill = [
            'displayId' => 'DRL-AUDIT-2026-0001', 'status' => 'Approved', 'reportDate' => '2026-09-03',
            'reportTime' => '08:00', 'reportIssuanceDate' => '2026-09-03', 'weather' => 'Clear',
            'incidentType' => 'Fire and rescue drill', 'exerciseCategories' => ['Fire', 'Rescue'],
            'location' => 'Workshop and warehouse loading area', 'exerciseTitle' => 'Major workshop response exercise',
            'details' => str_repeat('A controlled scenario tested evacuation, incident command, casualty recovery, and handover. ', 8),
            'exerciseObjectives' => [['text' => 'Validate command readiness'], ['text' => 'Confirm evacuation accountability']],
            'erpReferences' => [['annexNumber' => 'ERP-13', 'title' => 'Fire and rescue response']],
            'summary' => str_repeat('The exercise was completed safely with several improvement opportunities recorded. ', 5),
            'respondingTeam' => ['name' => 'Alpha Team', 'shift' => 'Day', 'attendance' => [
                ['name' => 'Élodie François', 'role' => 'Station Commander', 'exerciseRole' => 'Incident commander', 'teamName' => 'VMECC'],
                ['name' => 'Muhammad Nur Aiman', 'role' => 'Firefighter', 'exerciseRole' => 'Rescue team', 'teamName' => 'VMECC'],
            ]],
            'chronology' => array_map(fn (int $index): array => [
                'time' => sprintf('08:%02d', $index),
                'action' => 'Operational event '.$index.' with accountability and communications detail.',
            ], range(1, 18)),
            'postIncidentAnalysis' => [
                'strengths' => ['Prompt mobilisation', 'Clear incident command'],
                'resourcesMobilised' => ['Fire appliance', 'Medical response kit'],
                'improvementOpportunities' => ['Improve staging-area lighting', 'Repeat radio protocol briefing'],
                'photos' => [
                    ['url' => $pixel, 'description' => 'Initial response and staging area.'],
                    ['url' => $pixel, 'description' => 'Casualty recovery team at the exercise area.'],
                    ['url' => $pixel, 'description' => 'Post-exercise debrief.'],
                ],
            ],
            'timeline' => $timeline,
        ];

        $erco = [
            'displayId' => 'ERCO-AUDIT-2026-0001', 'status' => 'Approved', 'incidentDate' => '2026-09-03',
            'incidentTime' => '08:00', 'weather' => 'Heavy rain', 'incidentType' => 'Special Assistance',
            'location' => ['Zone 2', 'Pier B'],
            'details' => str_repeat('Special assistance was requested for a complex operational event. ', 8),
            'summary' => str_repeat('The response concluded safely and the affected area was returned to operations. ', 5),
            'respondingTeam' => ['name' => 'Operations Supervisor Team', 'shift' => 'Shift B', 'attendance' => [
                ['name' => 'AIC Leader', 'role' => 'Assistant Incident Commander'],
                ['name' => 'Responder One', 'role' => 'Medic'],
            ]],
            'chronology' => array_map(fn (int $index): array => [
                'time' => sprintf('08:%02d', $index), 'action' => 'Response event '.$index.' with operational detail.',
            ], range(1, 14)),
            'postIncidentAnalysis' => [
                'strengths' => ['Strong radio handover'], 'resourcesMobilised' => ['Pump unit'],
                'improvementOpportunities' => ['Improve staging-area lighting'],
                'photos' => [['url' => $pixel, 'description' => 'Response activity overview.']],
            ],
            'timeline' => $timeline,
        ];

        $assessment = [
            'displayId' => 'ERA-AUDIT-2026-0001', 'status' => 'Approved',
            'document' => ['title' => 'Emergency Response Assessment', 'code' => 'VMECC-OPS-016', 'revision' => '0'],
            'company' => 'Example Contractor Sdn. Bhd.', 'assessmentDate' => '2026-09-03',
            'location' => 'Process Area A', 'assessmentTypeLabel' => 'Working at Height',
            'scopeOfWork' => str_repeat('Installing cable trays at roof level using scaffold and MEWP. ', 4),
            'worstCaseScenario' => 'Fall from height, suspension trauma, or injury caused by falling objects.',
            'responses' => [
                ['requirementId' => 'wah.scaffold-tagged', 'requirement' => 'Scaffold tagged and inspected', 'response' => 'Yes', 'remarks' => 'Current inspection tag verified.'],
                ['requirementId' => 'wah.fall-protection', 'requirement' => 'Fall protection system', 'response' => 'No', 'remarks' => 'Secondary lifeline must be installed.', 'photos' => [['url' => $pixel, 'description' => 'Proposed secondary lifeline position.']]],
                ['requirementId' => 'wah.escape-routes', 'requirement' => 'Escape routes to assembly area', 'response' => 'Yes', 'remarks' => 'Route is clear.', 'assemblyArea' => 'Assembly Area 2'],
            ],
            'rescuePlan' => str_repeat('Raise the alarm, isolate the area, recover the casualty, and transfer care to the medical team. ', 3),
            'rescueAccessLayout' => ['url' => $pixel], 'rescueEquipment' => ['Full-body harness', 'Rescue rope', 'Stretcher'],
            'inspectedBy' => ['name' => 'Élodie François', 'company' => 'VMECC', 'signature' => 'Élodie François'],
            'jobLeader' => ['name' => 'Job Leader', 'company' => 'Example Contractor', 'signature' => 'Job Leader'],
        ];

        $fitness = [
            'displayId' => 'FIT-AUDIT-2026-0001', 'reportType' => 'fitness-test', 'status' => 'Approved',
            'version' => 3, 'revision' => 1, 'reportingMonth' => 'September 2026',
            'documentReference' => 'VMECC-FIT-001', 'protocolRevision' => '2',
            'completionStatistics' => ['participantCount' => 2, 'passedAssessmentCount' => 1, 'failedAssessmentCount' => 1, 'incompleteAssessmentCount' => 0],
            'signoff' => ['submittedAt' => '03 Sep 2026, 08:00', 'reviewedAt' => '03 Sep 2026, 09:00', 'approvedAt' => '03 Sep 2026, 10:00'],
            'shiftGroups' => [[
                'id' => 'SHIFT-A', 'shiftName' => 'Shift A', 'teamName' => 'Alpha Team', 'assessor' => ['name' => 'Assessor One'],
                'participants' => array_map(fn (int $index): array => [
                    'name' => 'Participant '.$index.' With Extended Name', 'role' => 'Emergency responder', 'source' => 'Roster', 'ageSnapshot' => 30 + $index,
                    'fitness' => ['sitUps' => 12, 'jumpingJacks' => 15, 'pushUps' => 10, 'testedOn' => '2026-09-03', 'result' => $index === 1 ? 'Passed' : 'Failed'],
                    'proficiency' => ['durationSeconds' => 90, 'testedOn' => '2026-09-03', 'result' => 'Passed', 'checkpoints' => [
                        ['checkpointCode' => 'CP1', 'completed' => true, 'durationSeconds' => 20, 'attempts' => 1],
                        ['checkpointCode' => 'CP2', 'completed' => $index === 1, 'durationSeconds' => 30, 'attempts' => 2],
                    ]],
                    'assessmentStatus' => $index === 1 ? 'Passed' : 'Failed',
                ], range(1, 2)),
            ]],
            'photos' => [['url' => $pixel, 'description' => 'Fitness assessment station.']],
        ];

        $payslip = [
            'reference' => 'CLM-AUDIT-2026-0001',
            'period' => ['label' => 'September 2026', 'value' => '2026-09', 'startDate' => '2026-09-01', 'endDate' => '2026-09-30'],
            'paymentDate' => '2026-09-25', 'employeeName' => 'Élodie François',
            'employeeProfile' => ['icNumber' => '900101-01-1234'], 'employeeRoles' => ['Emergency Response Officer'],
            'employeeStatutory' => ['epfNo' => 'EPF-000001', 'perkesoNo' => 'PERKESO-000001', 'incomeTaxNo' => 'TAX-000001'],
            'employer' => ['name' => 'Vale Mineral Malaysia Sdn. Bhd.', 'registrationNumber' => 'REG-000001', 'myTaxNumber' => 'MYTAX-000001', 'email' => 'payroll@example.test', 'phone' => '+60 12-345 6789'],
            'totals' => ['baselineNetSalary' => 4966.40, 'adjustmentsTotal' => 125.50, 'approvedOvertimePayout' => 385.20, 'netPayable' => 5477.10],
            'baseline' => [
                'employeeContributions' => ['epf' => 550.00, 'perkeso' => 24.75, 'sip' => 9.90],
                'employerContributions' => ['epf' => 650.00, 'perkeso' => 86.65, 'sip' => 9.90],
            ],
            'generatedAt' => '2026-09-03T10:30:00+08:00',
        ];

        $exceptionItems = array_map(fn (int $index): array => [
            'zone' => 'Zone '.(intdiv($index - 1, 6) + 1), 'location' => 'Fire Station', 'subLocation' => 'Bay '.(($index % 3) + 1),
            'idLocNo' => sprintf('FE-AUDIT-%03d', $index), 'feType' => 'DP 9KG', 'barcodeNo' => sprintf('BAR-%03d', $index),
            'certificationValidity' => $index % 2 === 0 ? '05 May 2026' : '31 Dec 2026',
            'latestInspectionAt' => '2026-09-02T08:00:00+08:00', 'inspectedBy' => 'Inspector A',
            'isExpired' => $index % 2 === 0, 'isIssue' => $index % 3 === 0, 'daysExpired' => $index % 2 === 0 ? 121 : 0,
            'defects' => $index % 3 === 0 ? [['label' => 'Operational condition', 'remarks' => 'Pressure indicator failed and requires corrective action.', 'photos' => []]] : [],
        ], range(1, 18));
        $exception = [
            'title' => 'Fire Extinguisher Exception Report', 'layoutMode' => 'combined', 'categories' => ['issues', 'expired'],
            'generatedAtDisplay' => '03 Sep 2026, 10:30', 'generatedBy' => 'Audit User', 'asOfDateDisplay' => '03 Sep 2026',
            'appliedFilters' => [], 'summary' => ['total' => 18, 'issues' => 6, 'expired' => 9, 'overlap' => 3],
            'items' => $exceptionItems, 'renderMeta' => ['imageCount' => 0],
        ];

        return [
            'drill' => ['render' => fn (): string => $render('pdf.drill_report', ['record' => $drill]), 'requiredText' => ['Drill Report', 'DRL-AUDIT-2026-0001', 'Workflow Sign-Off']],
            'erco' => ['render' => fn (): string => $render('pdf.erco_report', ['record' => $erco]), 'requiredText' => ['Emergency Response Call Out Report', 'ERCO-AUDIT-2026-0001', 'Sign-Offs']],
            'er-assessment' => ['render' => fn (): string => $render('pdf.er_assessment_report', ['record' => $assessment]), 'requiredText' => ['Emergency Response Assessment', 'ERA-AUDIT-2026-0001', 'Sign-off']],
            'fitness-test' => ['render' => fn (): string => $render('pdf.fitness_test_report', ['record' => $fitness, 'isPdf' => true]), 'requiredText' => ['Fitness Test Report', 'FIT-AUDIT-2026-0001', 'Completion Statistics', 'Page 1']],
            'payroll-payslip' => ['render' => fn (): string => $render('pdf.payroll-payslip', ['payslip' => $payslip]), 'requiredText' => ['PAYSLIP', 'CLM-AUDIT-2026-0001', 'Net Payable']],
            'fire-extinguisher-exceptions' => [
                'render' => fn (): string => app(FireExtinguisherExceptionPdfRenderer::class)->render($exception),
                'requiredText' => ['FIRE EXTINGUISHER EXCEPTION REPORT', 'FE-AUDIT-003', 'Page 1 of'],
            ],
        ];
    }

    private function averageHash(string $path): string
    {
        $source = imagecreatefrompng($path);
        $this->assertNotFalse($source, "Unable to decode {$path} for visual comparison.");
        $sample = imagecreatetruecolor(8, 8);
        imagecopyresampled($sample, $source, 0, 0, 0, 0, 8, 8, imagesx($source), imagesy($source));
        $values = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $color = imagecolorat($sample, $x, $y);
                $values[] = (int) round((((($color >> 16) & 0xFF) * .299) + ((($color >> 8) & 0xFF) * .587) + (($color & 0xFF) * .114)));
            }
        }
        imagedestroy($sample);
        imagedestroy($source);
        $average = array_sum($values) / count($values);
        $bits = implode('', array_map(fn (int $value): string => $value >= $average ? '1' : '0', $values));
        $hash = '';
        for ($offset = 0; $offset < 64; $offset += 4) {
            $hash .= dechex(bindec(substr($bits, $offset, 4)));
        }

        return $hash;
    }

    private function hashDistance(string $left, string $right): int
    {
        if (strlen($left) !== strlen($right)) {
            return PHP_INT_MAX;
        }
        $distance = 0;
        for ($index = 0; $index < strlen($left); $index++) {
            $distance += substr_count(decbin(hexdec($left[$index]) ^ hexdec($right[$index])), '1');
        }

        return $distance;
    }
}
