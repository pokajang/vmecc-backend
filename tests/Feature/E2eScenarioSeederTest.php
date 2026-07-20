<?php

namespace Tests\Feature;

use App\Models\LeaveAssignment;
use App\Models\OvertimeRecord;
use App\Models\User;
use App\Services\OvertimeManagementScopeService;
use Database\Seeders\E2eScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class E2eScenarioSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_deterministic_cross_role_personas_and_reachable_overtime_approval(): void
    {
        $this->seed(E2eScenarioSeeder::class);

        foreach (E2eScenarioSeeder::PERSONAS as $persona) {
            $this->assertDatabaseHas('users', [
                'email' => $persona['email'],
                'status' => 'Active',
            ]);
        }

        $owner = User::query()
            ->where('email', 'codex.smoke.tactical-response-team@vmecc.local')
            ->firstOrFail();
        $approver = User::query()
            ->where('email', E2eScenarioSeeder::PERSONAS['client_cm_alpha']['email'])
            ->firstOrFail();
        $record = OvertimeRecord::query()->create([
            'user_id' => $owner->id,
            'display_id' => 'E2E-OT-SCOPE-PROBE',
            'overtime_type' => 'weekday',
            'claim_date' => now()->subDay()->toDateString(),
            'start_time' => '18:00',
            'end_time' => '20:00',
            'is_overnight' => false,
            'duration_minutes' => 120,
            'reason' => 'Verify final approver scope for the isolated E2E fixture.',
            'status' => 'Pending',
            'workflow_stage' => 'approve',
            'workflow_snapshot' => [
                'approveRole' => 'Client Contract Manager',
                'requireRecommendation' => true,
                'enforceDistinctApprovers' => true,
            ],
            'next_action_role' => 'Client Contract Manager',
            'applicant_roles' => ['Tactical Response Team'],
            'approval_history' => [],
            'submitted_by' => $owner->name,
            'version' => 1,
        ]);

        $scope = app(OvertimeManagementScopeService::class);

        $this->assertTrue($scope->canManageRecord($approver, $record));
        $this->assertTrue(
            $scope->canPerformWorkflowRole($approver, $record, 'Client Contract Manager'),
        );
        $this->assertTrue(
            LeaveAssignment::query()
                ->where('user_id', $owner->id)
                ->where('year', now()->addYear()->year)
                ->where('leave_type', 'Annual Leave')
                ->where('entitlement', 30)
                ->exists(),
        );
    }
}
