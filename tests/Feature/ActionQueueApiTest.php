<?php

namespace Tests\Feature;

use App\Models\AiHelperResponseReport;
use App\Models\FeedbackReport;
use App\Models\InspectionFireExtinguisher;
use App\Models\InspectionFireExtinguisherIssue;
use App\Models\Leave;
use App\Models\PayrollClaim;
use App\Models\Report;
use App\Models\Roster;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ActionQueueApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_queue_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/action-queue')->assertUnauthorized();
    }

    public function test_action_queue_counts_only_work_assigned_to_the_current_role(): void
    {
        $actor = $this->createUserWithRole('Human Resource', [
            'self.dashboard',
            'staff.leave.manage',
        ]);
        $owner = User::factory()->create(['status' => 'Active']);

        $this->createLeave($owner, [
            'display_id' => 'LV-HR',
            'workflow_stage' => 'review',
            'next_action_role' => 'Human Resource',
        ]);
        $this->createLeave($owner, [
            'display_id' => 'LV-FIN',
            'workflow_stage' => 'review',
            'next_action_role' => 'Finance',
        ]);

        $response = $this->actingAs($actor)->getJson('/api/dashboard/action-queue');

        $response->assertOk()
            ->assertJsonPath('items.0.key', 'leave.review')
            ->assertJsonPath('items.0.count', 1)
            ->assertJsonPath('items.0.to', '/staff/leave-management/leaves?action=review');
        $this->assertCount(1, collect($response->json('items'))->where('module', 'leave'));

        $this->getJson('/api/staff/leave/records?action=review')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.display_id', 'LV-HR');
    }

    public function test_action_queue_splits_report_families_and_excludes_self_review(): void
    {
        $actor = $this->createUserWithRole('Incident Commander', [
            'self.dashboard',
            'reports.erco.view',
        ]);
        $owner = User::factory()->create(['status' => 'Active']);

        $team = Team::query()->create(['name' => 'Fallback Team', 'status' => 'On Duty']);
        $fallbackReport = $this->createReport($owner, 'erco', 'ERCO-OTHER');
        $fallbackReport->update(['scope_team_id' => $team->id]);
        $this->createReport($actor, 'erco', 'ERCO-SELF');
        $returnedReport = $this->createReport($actor, 'erco', 'ERCO-RETURNED');
        $returnedReport->update([
            'status' => 'Rejected',
            'workflow_stage' => 'done',
            'next_action_role' => null,
        ]);
        $this->createReport($owner, 'drill', 'DRILL-NO-PERMISSION');

        $response = $this->actingAs($actor)->getJson('/api/dashboard/action-queue');
        $items = collect($response->json('items'));

        $response->assertOk();
        $erco = $items->firstWhere('key', 'reports.erco.review');
        $this->assertSame(1, $erco['count']);
        $this->assertSame('/report/erco?scope=actionable&action=review', $erco['to']);
        $correction = $items->firstWhere('key', 'reports.erco.correction');
        $this->assertSame(1, $correction['count']);
        $this->assertSame('/report/erco?status=Rejected', $correction['to']);
        $this->assertNull($items->firstWhere('key', 'reports.drill.review'));

        $this->getJson('/api/reports?reportType=erco&scope=actionable&action=review')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.displayId', 'ERCO-OTHER');
        $this->getJson('/api/reports?reportType=erco&status=Rejected')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.displayId', 'ERCO-RETURNED');
    }

    public function test_payment_queue_includes_only_approved_unpaid_salary_claims(): void
    {
        $actor = $this->createUserWithRole('Finance', [
            'self.dashboard',
            'staff.salary.manage',
            'staff.salary.pay',
        ]);
        $owner = User::factory()->create(['status' => 'Active']);

        $this->createPayrollClaim($owner, ['claim_type' => 'salary', 'status' => 'Approved']);
        $this->createPayrollClaim($owner, ['claim_type' => 'expense', 'status' => 'Approved']);
        $this->createPayrollClaim($owner, [
            'claim_type' => 'salary',
            'status' => 'Paid',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($actor)->getJson('/api/dashboard/action-queue');
        $payment = collect($response->json('items'))->firstWhere('key', 'payroll.salary.mark-paid');

        $response->assertOk();
        $this->assertSame(1, $payment['count']);
        $this->assertSame('/staff/salary-claims/salary?action=mark_paid', $payment['to']);
    }

    public function test_roster_queue_links_to_complete_draft_attention_days(): void
    {
        $actor = $this->createUserWithRole('Roster Manager', [
            'self.dashboard',
            'rosters.manage',
        ]);
        $team = Team::query()->create(['name' => 'Roster Team', 'status' => 'On Duty']);

        Roster::query()->create(['date' => '2026-07-20', 'shift' => 'day', 'team_id' => $team->id, 'status' => 'draft']);
        Roster::query()->create(['date' => '2026-07-20', 'shift' => 'night', 'team_id' => $team->id, 'status' => 'published']);

        $response = $this->actingAs($actor)->getJson('/api/dashboard/action-queue');
        $roster = collect($response->json('items'))->firstWhere('key', 'roster.publish');

        $response->assertOk();
        $this->assertSame(1, $roster['count']);
        $this->assertSame('/roster/schedule?range=all&attention=draft', $roster['to']);
    }

    public function test_fire_extinguisher_issue_queue_surfaces_assigned_overdue_and_verification_work(): void
    {
        $actor = $this->createUserWithRole('Extinguisher Issue Manager', [
            'self.dashboard',
            'reports.inspection.issues.manage',
            'reports.inspection.issues.verify',
        ]);
        $asset = InspectionFireExtinguisher::query()->create([
            'main_location_name' => 'Action Queue Yard',
            'id_loc_no' => 'QUEUE-001',
            'source' => 'custom',
            'is_active' => true,
            'lifecycle_status' => 'active',
        ]);
        InspectionFireExtinguisherIssue::query()->create([
            'public_id' => (string) Str::uuid(),
            'fire_extinguisher_id' => $asset->id,
            'check_key' => 'operational-condition',
            'check_name' => 'Operational condition',
            'status' => 'pending_verification',
            'severity' => 'high',
            'title' => 'Gauge failed',
            'assigned_to_user_id' => $actor->id,
            'due_at' => now()->subDay(),
            'first_detected_at' => now()->subDays(2),
            'last_detected_at' => now()->subDays(2),
            'active_key' => 'fire-extinguisher:'.$asset->id.':operational-condition',
        ]);

        $items = collect($this->actingAs($actor)->getJson('/api/dashboard/action-queue')
            ->assertOk()->json('items'));

        $this->assertSame(1, $items->firstWhere('key', 'inspection.extinguisher-issues.assigned')['count']);
        $this->assertSame(1, $items->firstWhere('key', 'inspection.extinguisher-issues.overdue')['count']);
        $this->assertSame(1, $items->firstWhere('key', 'inspection.extinguisher-issues.verify')['count']);
    }

    public function test_admin_moderation_links_include_new_and_reviewing_records(): void
    {
        $actor = $this->createUserWithRole('System Administrator', ['self.dashboard', '*']);
        $reporter = User::factory()->create(['status' => 'Active']);

        foreach (['new', 'reviewing', 'resolved'] as $status) {
            FeedbackReport::query()->create([
                'reporter_user_id' => $reporter->id,
                'message' => "Feedback {$status}",
                'status' => $status,
            ]);
            AiHelperResponseReport::query()->create([
                'reporter_user_id' => $reporter->id,
                'reason' => "AI report {$status}",
                'status' => $status,
            ]);
        }

        $response = $this->actingAs($actor)->getJson('/api/dashboard/action-queue');
        $items = collect($response->json('items'));

        $response->assertOk();
        $this->assertSame(2, $items->firstWhere('key', 'admin.feedback.review')['count']);
        $this->assertSame(
            '/admin/feedback-reports?status=actionable',
            $items->firstWhere('key', 'admin.feedback.review')['to'],
        );
        $this->assertSame(2, $items->firstWhere('key', 'admin.ai-reports.review')['count']);
        $this->assertSame(
            '/admin/ai-helper-reports?status=actionable',
            $items->firstWhere('key', 'admin.ai-reports.review')['to'],
        );

        $this->getJson('/api/feedback-reports?status=actionable')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.counts.actionable', 2);
        $this->getJson('/api/ai-helper/reports?status=actionable')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.counts.actionable', 2);
    }

    private function createUserWithRole(string $roleName, array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $role->syncPermissions($permissions);
        $user = User::factory()->create(['status' => 'Active']);
        $user->assignRole($role);

        return $user;
    }

    private function createLeave(User $owner, array $overrides): Leave
    {
        return Leave::query()->create(array_merge([
            'user_id' => $owner->id,
            'display_id' => 'LV-'.uniqid(),
            'leave_type' => 'Annual Leave',
            'status' => 'Pending',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'days' => 1,
            'applied_at' => now(),
            'workflow_stage' => 'review',
            'workflow_snapshot' => [],
            'approval_history' => [],
        ], $overrides));
    }

    private function createReport(User $owner, string $type, string $displayId): Report
    {
        return Report::query()->create([
            'report_uid' => strtolower($displayId),
            'display_id' => $displayId,
            'owner_user_id' => $owner->id,
            'report_type' => $type,
            'status' => 'Submitted',
            'workflow_stage' => 'review',
            'next_action_role' => 'Incident Commander',
            'workflow_snapshot' => [
                'reviewRole' => 'Incident Commander',
                'resolvedReviewRole' => 'Incident Commander',
                'usedFallbackReview' => true,
                'options' => [
                    'preventSelfReview' => true,
                    'preventSelfApprove' => true,
                ],
            ],
            'approval_history' => [],
            'payload' => [],
            'submitted_at' => now(),
        ]);
    }

    private function createPayrollClaim(User $owner, array $overrides): PayrollClaim
    {
        return PayrollClaim::query()->create(array_merge([
            'user_id' => $owner->id,
            'display_id' => 'PC-'.uniqid(),
            'claim_type' => 'salary',
            'status' => 'Approved',
            'workflow_stage' => 'done',
            'workflow_snapshot' => [],
            'approval_history' => [],
            'submitted_at' => now(),
        ], $overrides));
    }
}
