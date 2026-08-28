<?php

namespace Tests\Unit;

use App\Models\Report;
use App\Models\User;
use App\Services\AssignmentAuthorizationService;
use App\Services\ReportReadAuthorizationService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReportReadAuthorizationServiceTest extends TestCase
{
    #[DataProvider('pdfReportTypeProvider')]
    public function test_submitted_pdf_report_uses_the_module_view_permission(
        string $reportType,
        string $permission,
    ): void {
        $user = new User;
        $report = new Report([
            'report_type' => $reportType,
            'status' => 'Submitted',
        ]);
        $authorization = Mockery::mock(AssignmentAuthorizationService::class);
        $authorization
            ->shouldReceive('hasPermission')
            ->once()
            ->with($user, "reports.manage|{$permission}")
            ->andReturnTrue();

        $service = new ReportReadAuthorizationService($authorization);

        $this->assertTrue($service->canDownloadPdf($user, $report));
        $this->assertTrue($service->canViewModule($user, $reportType));
    }

    public function test_draft_and_unsupported_report_types_are_not_downloadable(): void
    {
        $user = new User;
        $authorization = Mockery::mock(AssignmentAuthorizationService::class);
        $authorization->shouldNotReceive('hasPermission');
        $service = new ReportReadAuthorizationService($authorization);

        $this->assertFalse($service->canDownloadPdf($user, new Report([
            'report_type' => 'inspection',
            'status' => 'Draft',
        ])));
        $this->assertFalse($service->canDownloadPdf($user, new Report([
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
        ])));
        $this->assertFalse($service->canDownloadPdf($user, new Report([
            'report_type' => 'inspection',
            'status' => 'Unknown',
        ])));
        $this->assertFalse($service->canViewModule($user, 'unknown-report-type'));
    }

    #[DataProvider('erAssessmentPdfStatusProvider')]
    public function test_er_assessment_uses_status_labelled_snapshot_pdf_policy(
        string $status,
        bool $expected,
    ): void {
        $user = new User;
        $authorization = Mockery::mock(AssignmentAuthorizationService::class);
        if ($expected) {
            $authorization
                ->shouldReceive('hasPermission')
                ->once()
                ->with($user, 'reports.manage|reports.er_assessment.view')
                ->andReturnTrue();
        } else {
            $authorization->shouldNotReceive('hasPermission');
        }
        $service = new ReportReadAuthorizationService($authorization);

        $this->assertSame($expected, $service->canDownloadPdf($user, new Report([
            'report_type' => 'er-assessment',
            'status' => $status,
        ])));
    }

    public static function pdfReportTypeProvider(): array
    {
        return [
            'ERCO' => ['erco', 'reports.erco.view'],
            'drill' => ['drill', 'reports.drill.view'],
            'inspection' => ['inspection', 'reports.inspection.view'],
            'ER Assessment' => ['er-assessment', 'reports.er_assessment.view'],
        ];
    }

    public static function erAssessmentPdfStatusProvider(): array
    {
        return [
            'Draft denied' => ['Draft', false],
            'Submitted snapshot' => ['Submitted', true],
            'Reviewed snapshot' => ['Reviewed', true],
            'Approved snapshot' => ['Approved', true],
            'Rejected snapshot' => ['Rejected', true],
            'Cancelled snapshot' => ['Cancelled', true],
        ];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
