<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AuditManagedReportPayloadsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_is_read_only_and_reports_invalid_and_polluted_payloads(): void
    {
        $owner = User::factory()->create();
        $valid = Report::query()->create([
            'report_uid' => 'fitness-audit-valid',
            'display_id' => 'FIT-AUDIT-VALID',
            'owner_user_id' => $owner->id,
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'version' => 1,
            'revision' => 1,
            'payload' => $this->validFitnessPayload(),
        ]);
        $invalid = Report::query()->create([
            'report_uid' => 'fitness-audit-invalid',
            'display_id' => 'FIT-AUDIT-INVALID',
            'owner_user_id' => $owner->id,
            'report_type' => 'fitness-test',
            'status' => 'Submitted',
            'version' => 1,
            'revision' => 1,
            'payload' => [
                'schemaVersion' => 1,
                'incidentType' => 'Endurance Test',
                'workflowStage' => 'review',
            ],
        ]);
        $timestamps = fn (): array => Report::query()
            ->get(['report_uid', 'updated_at'])
            ->mapWithKeys(fn (Report $report): array => [
                $report->report_uid => $report->updated_at?->toIso8601String(),
            ])
            ->all();
        $before = $timestamps();

        $exitCode = Artisan::call('reports:audit-payloads', [
            '--module' => 'fitness-test',
            '--json' => true,
        ]);
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame(2, $result['scanned']);
        $this->assertSame(1, $result['valid']);
        $this->assertSame(1, $result['invalid']);
        $this->assertSame('fitness-audit-invalid', $result['reports'][1]['reportUid']);

        $this->assertSame($before, $timestamps());
        $this->assertDatabaseHas('reports', ['id' => $valid->id]);
        $this->assertDatabaseHas('reports', ['id' => $invalid->id]);
    }

    private function validFitnessPayload(): array
    {
        return [
            'schemaVersion' => 1,
            'reportDate' => '2026-07-13',
            'reportTime' => '09:00',
            'weather' => 'Routine',
            'incidentType' => 'Endurance Test',
            'location' => 'Training yard',
            'details' => 'Fitness test details.',
            'summary' => 'Fitness test summary.',
            'chronology' => [['time' => '09:00', 'action' => 'Fitness test started.']],
        ];
    }
}
