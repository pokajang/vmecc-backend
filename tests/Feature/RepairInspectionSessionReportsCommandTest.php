<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairInspectionSessionReportsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_apply_and_repeat_are_safe_and_idempotent(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $report = Report::query()->create([
            'report_uid' => 'report-ins-session-repair',
            'display_id' => 'INS-FE-REPAIR',
            'owner_user_id' => $owner->id,
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'version' => 3,
            'revision' => 2,
            'payload' => $this->stalePayload(),
        ]);

        $this->artisan('inspection:repair-session-reports', ['--dry-run' => true])
            ->expectsTable(
                ['Mode', 'Scanned', 'Eligible', 'Unchanged', 'Would repair', 'Repaired', 'Rejected'],
                [['dry-run', 1, 1, 0, 1, 0, 0]],
            )->assertExitCode(Command::SUCCESS);
        $this->assertSame('', $report->fresh()->payload['location']);

        $this->artisan('inspection:repair-session-reports')
            ->expectsTable(
                ['Mode', 'Scanned', 'Eligible', 'Unchanged', 'Would repair', 'Repaired', 'Rejected'],
                [['apply', 1, 1, 0, 0, 1, 0]],
            )->assertExitCode(Command::SUCCESS);

        $repaired = $report->fresh();
        $this->assertSame('Zone 1 > Manjung Hub > Reception', $repaired->payload['location']);
        $this->assertSame(3, $repaired->version);
        $this->assertSame(2, $repaired->revision);

        $this->artisan('inspection:repair-session-reports')
            ->expectsTable(
                ['Mode', 'Scanned', 'Eligible', 'Unchanged', 'Would repair', 'Repaired', 'Rejected'],
                [['apply', 1, 1, 1, 0, 0, 0]],
            )->assertExitCode(Command::SUCCESS);
    }

    private function stalePayload(): array
    {
        return [
            'inspectionSessionUid' => 'inspection-session-repair',
            'incidentType' => 'Fire Extinguisher Inspection',
            'inspectionType' => 'Fire Extinguisher Inspection',
            'location' => '',
            'selectedLocation' => '',
            'photos' => [],
            'fireExtinguisherChecks' => [[
                'id' => 'fe-1',
                'zone' => '1',
                'mainLocation' => 'Manjung Hub',
                'subLocation' => 'Reception',
                'physicalCondition' => 'Good',
                'signageCondition' => 'Good',
                'boxKeyAvailability' => 'N/A',
                'boxGlassAvailability' => 'N/A',
                'operationalCondition' => 'Good',
                'photos' => [],
            ]],
        ];
    }
}
