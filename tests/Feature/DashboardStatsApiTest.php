<?php

namespace Tests\Feature;

use App\Models\Leave;
use App\Models\OvertimeRecord;
use App\Models\PayrollClaim;
use App\Models\Report;
use App\Models\Roster;
use App\Models\SalaryAssignment;
use App\Models\SalaryAssignmentDraft;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardStatsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_stats_requires_auth_and_section_permission(): void
    {
        $this->getJson('/api/stats/payroll')->assertStatus(401);

        $user = $this->createDashboardUser(['self.dashboard']);
        $this->actingAs($user);

        $this->getJson('/api/stats/payroll')->assertStatus(403);
    }

    public function test_payroll_stats_use_persisted_data_and_selected_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00'));

        $user = $this->createDashboardUser(['self.dashboard', 'dashboard.payroll.view']);
        $employee = User::factory()->create(['status' => 'Active']);
        $this->actingAs($user);

        SalaryAssignment::query()->create([
            'employee_user_id' => $user->id,
            'status' => 'Active',
            'effective_from' => '2026-01-01',
            'basic_salary' => 5000,
            'allowance_total' => 0,
        ]);
        SalaryAssignmentDraft::query()->create([
            'user_id' => $user->id,
            'payload' => ['employeeUserId' => $employee->id],
            'saved_at' => now(),
        ]);

        $this->createPayrollClaim($employee, [
            'display_id' => 'PC-001',
            'claim_type' => 'salary',
            'status' => 'Pending',
            'workflow_stage' => 'review',
            'submitted_at' => '2026-06-04 08:00:00',
            'projected_net_payout' => 1000,
        ]);
        $this->createPayrollClaim($employee, [
            'display_id' => 'PC-002',
            'claim_type' => 'expense',
            'status' => 'Paid',
            'submitted_at' => '2026-06-05 08:00:00',
            'paid_at' => '2026-06-06 08:00:00',
            'projected_net_payout' => 250,
        ]);
        $this->createPayrollClaim($employee, [
            'display_id' => 'PC-003',
            'claim_type' => 'exceptional',
            'status' => 'Approved',
            'submitted_at' => '2026-06-07 08:00:00',
            'projected_net_payout' => 400,
        ]);
        $this->createPayrollClaim($employee, [
            'display_id' => 'PC-OLD',
            'claim_type' => 'salary',
            'status' => 'Paid',
            'submitted_at' => '2026-05-03 08:00:00',
            'paid_at' => '2026-05-04 08:00:00',
            'projected_net_payout' => 999,
        ]);

        $response = $this->getJson('/api/stats/payroll?period=this_month');

        $response->assertOk()
            ->assertJsonPath('pendingApprovals', 1)
            ->assertJsonPath('approvedUnpaidCount', 1)
            ->assertJsonPath('approvedUnpaidTotalMyr', 400)
            ->assertJsonPath('paidThisMonthCount', 1)
            ->assertJsonPath('paidThisMonthTotalMyr', 250)
            ->assertJsonPath('byType.salary', 1)
            ->assertJsonPath('byType.expense', 1)
            ->assertJsonPath('byType.other', 1)
            ->assertJsonPath('byStatus.pendingReview', 1)
            ->assertJsonPath('activeAssignments', 1)
            ->assertJsonPath('assignmentDrafts', 1);

        $lastMonth = $this->getJson('/api/stats/payroll?period=last_month');
        $lastMonth->assertOk()
            ->assertJsonPath('paidThisMonthCount', 1)
            ->assertJsonPath('paidThisMonthTotalMyr', 999)
            ->assertJsonPath('byType.salary', 1)
            ->assertJsonPath('byType.expense', 0);
    }

    public function test_overtime_leave_roster_and_report_stats_use_real_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00'));

        $user = $this->createDashboardUser([
            'self.dashboard',
            'dashboard.overtime.view',
            'dashboard.leave.view',
            'dashboard.roster.view',
            'dashboard.reports.view',
        ]);
        $employee = User::factory()->create(['name' => 'Employee One', 'status' => 'Active']);
        $team = Team::query()->create(['name' => 'Alpha', 'status' => 'On Duty']);
        TeamMember::query()->create([
            'team_id' => $team->id,
            'user_id' => $employee->id,
            'name' => $employee->name,
        ]);
        TeamMember::query()->create([
            'team_id' => $team->id,
            'user_id' => User::factory()->create(['status' => 'Active'])->id,
            'name' => 'Future Member',
            'started_at' => '2026-07-01',
        ]);
        $this->actingAs($user);

        $this->createOvertimeRecord($employee, [
            'display_id' => 'OT-001',
            'overtime_type' => 'weekend',
            'status' => 'Approved',
            'claim_date' => '2026-06-03',
            'duration_minutes' => 180,
        ]);
        $this->createOvertimeRecord($employee, [
            'display_id' => 'OT-002',
            'overtime_type' => 'holiday',
            'status' => 'Pending',
            'claim_date' => '2026-06-04',
            'duration_minutes' => 60,
        ]);

        Leave::query()->create([
            'user_id' => $employee->id,
            'display_id' => 'LV-001',
            'leave_type' => 'Annual Leave',
            'status' => 'Approved',
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
            'days' => 2,
            'applied_at' => '2026-06-01 08:00:00',
        ]);
        Leave::query()->create([
            'user_id' => $employee->id,
            'display_id' => 'LV-002',
            'leave_type' => 'Medical Leave',
            'status' => 'Pending',
            'start_date' => '2026-06-20',
            'end_date' => '2026-06-20',
            'days' => 1,
            'applied_at' => '2026-06-02 08:00:00',
        ]);

        Roster::query()->create([
            'date' => '2026-06-10',
            'shift' => 'day',
            'team_id' => $team->id,
            'status' => 'published',
        ]);
        Roster::query()->create([
            'date' => '2026-06-11',
            'shift' => 'night',
            'team_id' => $team->id,
            'status' => 'draft',
        ]);

        Report::query()->create([
            'report_uid' => 'rep-001',
            'display_id' => 'ERCO-001',
            'owner_user_id' => $employee->id,
            'report_type' => 'erco',
            'status' => 'Submitted',
            'payload' => ['incident_type' => 'Fire'],
            'submitted_at' => '2026-06-03 08:00:00',
        ]);
        Report::query()->create([
            'report_uid' => 'rep-002',
            'display_id' => 'DRILL-001',
            'owner_user_id' => $employee->id,
            'report_type' => 'drill',
            'status' => 'Reviewed',
            'payload' => ['incidentType' => 'Fire Drill'],
            'submitted_at' => '2026-06-04 08:00:00',
        ]);

        $this->getJson('/api/stats/overtime?period=this_month')
            ->assertOk()
            ->assertJsonPath('pendingApprovals', 1)
            ->assertJsonPath('approvedHoursThisPeriod', 3)
            ->assertJsonPath('submittedThisPeriod', 2)
            ->assertJsonPath('byType.weekend', 1)
            ->assertJsonPath('byType.holiday', 1)
            ->assertJsonPath('byTeam.0.team', 'Alpha');

        $this->getJson('/api/stats/leave?period=this_month')
            ->assertOk()
            ->assertJsonPath('pendingApprovals', 1)
            ->assertJsonPath('approvedDaysThisPeriod', 2)
            ->assertJsonPath('staffCurrentlyOnLeave', 1)
            ->assertJsonPath('byTeam.0.team', 'Alpha');

        $this->getJson('/api/stats/roster?period=this_month')
            ->assertOk()
            ->assertJsonPath('teamsOnDuty', 1)
            ->assertJsonPath('draftsPendingPublish', 1)
            ->assertJsonPath('teams.0.name', 'Alpha')
            ->assertJsonPath('teams.0.memberCount', 1)
            ->assertJsonPath('teams.0.dayShifts', 1)
            ->assertJsonPath('monthlyTrend.0.scheduledDays', 1);

        $this->getJson('/api/stats/reports?period=this_month')
            ->assertOk()
            ->assertJsonPath('pendingReview', 1)
            ->assertJsonPath('pendingApproval', 1)
            ->assertJsonPath('submittedThisPeriod', 2)
            ->assertJsonPath('byType.erco', 1)
            ->assertJsonPath('byType.drill', 1)
            ->assertJsonPath('ercoByIncidentType.0.type', 'Fire')
            ->assertJsonPath('byPersonnel.0.name', 'Employee One');
    }

    public function test_dashboard_stats_empty_modules_return_zero_safe_shapes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00'));

        $user = $this->createDashboardUser([
            'self.dashboard',
            'dashboard.payroll.view',
            'dashboard.overtime.view',
            'dashboard.leave.view',
            'dashboard.roster.view',
            'dashboard.reports.view',
        ]);
        SalaryAssignment::query()->create([
            'employee_user_id' => $user->id,
            'status' => 'Active',
            'effective_from' => '2026-01-01',
            'basic_salary' => 1,
            'allowance_total' => 0,
        ]);
        $this->actingAs($user);

        $this->getJson('/api/stats/payroll')->assertOk()
            ->assertJsonPath('pendingApprovals', 0)
            ->assertJsonPath('monthlyTrend.0.count', 0);
        $this->getJson('/api/stats/overtime')->assertOk()
            ->assertJsonPath('pendingApprovals', 0)
            ->assertJsonPath('byTeam', []);
        $this->getJson('/api/stats/leave')->assertOk()
            ->assertJsonPath('approvedDaysThisPeriod', 0)
            ->assertJsonPath('byTeam', []);
        $this->getJson('/api/stats/roster')->assertOk()
            ->assertJsonPath('draftsPendingPublish', 0)
            ->assertJsonPath('teams', []);
        $this->getJson('/api/stats/reports')->assertOk()
            ->assertJsonPath('submittedThisPeriod', 0)
            ->assertJsonPath('byPersonnel', []);
    }

    private function createDashboardUser(array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::query()->firstOrCreate(['name' => 'Dashboard Test Role', 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['status' => 'Active']);
        $user->assignRole($role);

        return $user;
    }

    private function createPayrollClaim(User $user, array $overrides = []): PayrollClaim
    {
        return PayrollClaim::query()->create(array_merge([
            'user_id' => $user->id,
            'display_id' => 'PC-' . uniqid(),
            'claim_type' => 'expense',
            'amount' => 0,
            'approved_overtime_payout' => 0,
            'adjustments_total' => 0,
            'projected_net_payout' => 0,
            'status' => 'Pending',
            'submitted_at' => now(),
            'workflow_stage' => 'review',
            'workflow_snapshot' => [],
            'approval_history' => [],
        ], $overrides));
    }

    private function createOvertimeRecord(User $user, array $overrides = []): OvertimeRecord
    {
        return OvertimeRecord::query()->create(array_merge([
            'user_id' => $user->id,
            'display_id' => 'OT-' . uniqid(),
            'overtime_type' => 'weekday',
            'claim_date' => now()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'is_overnight' => false,
            'duration_minutes' => 60,
            'reason' => 'Dashboard stats test',
            'status' => 'Pending',
            'applied_at' => now(),
            'workflow_stage' => 'review',
            'workflow_snapshot' => [],
            'applicant_roles' => [],
            'approval_history' => [],
        ], $overrides));
    }
}
