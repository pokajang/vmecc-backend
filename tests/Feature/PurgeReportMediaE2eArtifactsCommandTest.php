<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\ReportDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeReportMediaE2eArtifactsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_only_purges_explicitly_marked_e2e_rows(): void
    {
        $user = User::factory()->create();
        $e2eReport = Report::query()->create([
            'report_uid' => 'report-erco-e2e-cleanup',
            'display_id' => 'ERCO-E2E-CLEANUP',
            'submission_key' => 'e2e-report-media-erco-cleanup',
            'owner_user_id' => $user->id,
            'report_type' => 'erco',
            'status' => 'Submitted',
            'version' => 1,
            'revision' => 1,
            'payload' => [],
        ]);
        $normalReport = Report::query()->create([
            'report_uid' => 'report-erco-normal-cleanup',
            'display_id' => 'ERCO-NORMAL-CLEANUP',
            'submission_key' => 'normal-submission-key',
            'owner_user_id' => $user->id,
            'report_type' => 'erco',
            'status' => 'Submitted',
            'version' => 1,
            'revision' => 1,
            'payload' => [],
        ]);
        $draft = ReportDraft::query()->create([
            'user_id' => $user->id,
            'draft_id' => 'drf_e2ecleanup',
            'report_type' => 'erco',
            'title' => 'ERCO authenticated media smoke',
            'payload' => [],
            'saved_at' => now(),
            'version' => 1,
        ]);

        $e2eReport->delete();

        $this->artisan('reports:purge-media-e2e-artifacts', [
            '--report-id' => [$e2eReport->report_uid, $normalReport->report_uid],
            '--draft-id' => [$draft->draft_id],
        ])->assertSuccessful();

        $this->assertDatabaseMissing('reports', ['id' => $e2eReport->id]);
        $this->assertDatabaseHas('reports', ['id' => $normalReport->id]);
        $this->assertDatabaseMissing('report_drafts', ['id' => $draft->id]);
    }
}
