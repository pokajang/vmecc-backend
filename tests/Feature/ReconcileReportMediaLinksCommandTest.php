<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\ReportMedia;
use App\Models\ReportMediaLink;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReconcileReportMediaLinksCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_dry_run_reports_missing_link_without_committing_and_apply_is_idempotent(): void
    {
        [$report, $media] = $this->createReportAndMedia('erco', 'ERCO-RECONCILE');
        ReportMediaLink::query()->create([
            'report_media_id' => $media->id,
            'parent_type' => 'report_draft',
            'parent_key' => 'drf_existing_erco',
        ]);

        $this->artisan('report-media:reconcile-reports', [
            '--module' => 'erco',
            '--dry-run' => true,
            '--batch' => 1,
        ])->expectsTable(
            ['Mode', 'Module', 'Scanned', 'Already correct', 'Would repair', 'Repaired', 'Rejected'],
            [['dry-run', 'erco', 1, 0, 1, 0, 0]],
        )->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseMissing('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report',
            'parent_key' => $report->report_uid,
        ]);

        $this->artisan('report-media:reconcile-reports', [
            '--module' => 'erco',
        ])->expectsTable(
            ['Mode', 'Module', 'Scanned', 'Already correct', 'Would repair', 'Repaired', 'Rejected'],
            [['apply', 'erco', 1, 0, 0, 1, 0]],
        )->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report',
            'parent_key' => $report->report_uid,
        ]);
        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report_draft',
            'parent_key' => 'drf_existing_erco',
        ]);

        $this->artisan('report-media:reconcile-reports', [
            '--module' => 'erco',
        ])->expectsTable(
            ['Mode', 'Module', 'Scanned', 'Already correct', 'Would repair', 'Repaired', 'Rejected'],
            [['apply', 'erco', 1, 1, 0, 0, 0]],
        )->assertExitCode(Command::SUCCESS);
    }

    public function test_module_mismatch_is_reported_and_never_relabelled(): void
    {
        [$report, $media] = $this->createReportAndMedia('erco', 'ERCO-MISMATCH', 'drill');

        $this->artisan('report-media:reconcile-reports', [
            '--module' => 'erco',
            '--dry-run' => true,
        ])->expectsOutputToContain('Rejected report')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseHas('report_media', [
            'id' => $media->id,
            'module' => 'drill',
        ]);
        $this->assertDatabaseMissing('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report',
            'parent_key' => $report->report_uid,
        ]);
    }

    public function test_command_requires_supported_module_and_bounded_batch(): void
    {
        $this->artisan('report-media:reconcile-reports')->assertExitCode(Command::INVALID);
        $this->artisan('report-media:reconcile-reports', [
            '--module' => 'unknown',
        ])->assertExitCode(Command::INVALID);
        $this->artisan('report-media:reconcile-reports', [
            '--module' => 'drill',
            '--batch' => 501,
        ])->assertExitCode(Command::INVALID);
    }

    /**
     * @return array{Report, ReportMedia}
     */
    private function createReportAndMedia(
        string $reportType,
        string $displayId,
        ?string $mediaModule = null,
    ): array {
        $owner = User::factory()->create(['status' => 'active']);
        $media = ReportMedia::query()->create([
            'public_id' => 'rpm_'.strtolower($displayId),
            'user_id' => $owner->id,
            'module' => $mediaModule ?? $reportType,
            'disk' => 'local',
            'storage_path' => 'report-media/'.$displayId.'.jpg',
            'original_name' => $displayId.'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
            'width' => 100,
            'height' => 100,
        ]);
        Storage::disk('local')->put($media->storage_path, 'image');
        $report = Report::query()->create([
            'report_uid' => strtolower($displayId),
            'display_id' => $displayId,
            'owner_user_id' => $owner->id,
            'report_type' => $reportType,
            'status' => 'Submitted',
            'version' => 1,
            'revision' => 1,
            'payload' => [
                'incidentType' => 'Fire',
                'postIncidentAnalysis' => [
                    'photos' => [[
                        'mediaId' => $media->public_id,
                        'url' => '/api/report-media/'.$media->public_id,
                    ]],
                ],
            ],
        ]);

        return [$report, $media];
    }
}
