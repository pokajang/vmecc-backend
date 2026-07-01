<?php

namespace Database\Seeders;

use App\Models\AiHelperResponseReport;
use App\Models\FeedbackReport;
use App\Models\InspectionEquipment;
use App\Models\InspectionFireExtinguisher;
use App\Models\InspectionLocation;
use App\Models\Leave;
use App\Models\OvertimeRecord;
use App\Models\PayrollClaim;
use App\Models\Report;
use App\Models\Roster;
use App\Models\SalaryAssignment;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkflowNotification;
use App\Models\WorkflowNotificationDismissal;
use App\Models\WorkflowNotificationRead;
use Illuminate\Database\Seeder;

class SmokeScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $users = $this->smokeUsers();
        if ($users->isEmpty()) {
            $this->call(SmokeRbacUsersSeeder::class);
            $users = $this->smokeUsers();
        }

        $trt = $users['codex.smoke.tactical-response-team@vmecc.local'] ?? $users->first();
        $hr = $users['codex.smoke.human-resource@vmecc.local'] ?? $users->first();
        $finance = $users['codex.smoke.finance@vmecc.local'] ?? $users->first();
        $sysadmin = $users['codex.smoke.sysadmin@vmecc.local'] ?? $users->first();

        $siteAlpha = Team::where('name', 'Smoke Site Alpha')->first();
        $siteBeta = Team::where('name', 'Smoke Site Beta')->first();

        $this->seedRoster($sysadmin, $siteAlpha, $siteBeta);
        $this->seedLeaveRecords($trt);
        $this->seedOvertimeRecords($trt);
        $this->seedPayrollClaims($trt, $finance);
        $this->seedSalaryAssignments($trt, $finance);
        $this->seedReports($trt, $siteAlpha);
        $this->seedInspectionCatalog($sysadmin);
        $this->seedFeedbackAndAiReports($trt, $sysadmin);
        $this->seedWorkflowNotifications($trt, $hr, $finance);
    }

    private function smokeUsers()
    {
        return User::query()
            ->where('email', 'like', 'codex.smoke.%@vmecc.local')
            ->get()
            ->keyBy('email');
    }

    private function seedRoster(User $actor, ?Team $siteAlpha, ?Team $siteBeta): void
    {
        if (!$siteAlpha || !$siteBeta) {
            return;
        }

        Roster::updateOrCreate(
            ['date' => '2026-07-01', 'shift' => 'day'],
            [
                'team_id' => $siteAlpha->id,
                'status' => 'published',
                'created_by' => $actor->id,
                'published_by' => $actor->id,
                'published_at' => now(),
            ],
        );

        Roster::updateOrCreate(
            ['date' => '2026-07-01', 'shift' => 'night'],
            [
                'team_id' => $siteBeta->id,
                'status' => 'draft',
                'created_by' => $actor->id,
                'published_by' => null,
                'published_at' => null,
            ],
        );
    }

    private function seedLeaveRecords(User $owner): void
    {
        foreach (['Draft', 'Pending', 'Reviewed', 'Approved', 'Rejected', 'Cancelled'] as $index => $status) {
            Leave::updateOrCreate(
                ['user_id' => $owner->id, 'display_id' => "SMK-LV-{$index}"],
                [
                    'leave_type' => 'Annual Leave',
                    'status' => $status,
                    'start_date' => now()->addDays(10 + $index)->toDateString(),
                    'end_date' => now()->addDays(10 + $index)->toDateString(),
                    'days' => 1,
                    'work_shift' => 'day',
                    'reason' => "Smoke {$status} leave record",
                    'applied_at' => now()->subDays(2),
                    'workflow_stage' => $status === 'Pending' ? 'pending_review' : strtolower($status),
                    'workflow_snapshot' => ['smoke' => true],
                    'next_action_role' => $status === 'Pending' ? 'Human Resource' : null,
                    'applicant_roles' => ['Tactical Response Team'],
                    'approval_history' => [],
                    'submitted_by' => $owner->name,
                ],
            );
        }
    }

    private function seedOvertimeRecords(User $owner): void
    {
        foreach (['Draft', 'Pending', 'Reviewed', 'Approved', 'Rejected', 'Cancelled'] as $index => $status) {
            OvertimeRecord::updateOrCreate(
                ['user_id' => $owner->id, 'display_id' => "SMK-OT-{$index}"],
                [
                    'overtime_type' => 'Normal',
                    'claim_date' => now()->addDays(20 + $index)->toDateString(),
                    'start_time' => '18:00',
                    'end_time' => '20:00',
                    'is_overnight' => false,
                    'duration_minutes' => 120,
                    'reason' => "Smoke {$status} overtime record",
                    'status' => $status,
                    'applied_at' => now()->subDays(2),
                    'workflow_stage' => $status === 'Pending' ? 'pending_review' : strtolower($status),
                    'workflow_snapshot' => ['smoke' => true],
                    'next_action_role' => $status === 'Pending' ? 'Finance' : null,
                    'applicant_roles' => ['Tactical Response Team'],
                    'approval_history' => [],
                    'submitted_by' => $owner->name,
                ],
            );
        }
    }

    private function seedPayrollClaims(User $owner, User $finance): void
    {
        $statuses = ['Draft', 'Pending', 'Checked', 'Reviewed', 'Approved', 'Rejected', 'Cancelled', 'Paid'];
        foreach ($statuses as $index => $status) {
            PayrollClaim::updateOrCreate(
                ['user_id' => $owner->id, 'display_id' => "SMK-CLM-{$index}"],
                [
                    'submission_key' => "smoke-claim-{$index}",
                    'claim_type' => $index % 2 === 0 ? 'salary' : 'expense',
                    'category' => $index % 2 === 0 ? 'Monthly Salary' : 'Travel',
                    'period' => 'month',
                    'period_value' => '2026-07',
                    'amount' => 100 + ($index * 10),
                    'status' => $status,
                    'submitted_at' => now()->subDays(2),
                    'submitted_by' => (string) $owner->id,
                    'submitted_by_name' => $owner->name,
                    'workflow_stage' => $status === 'Pending' ? 'pending_check' : strtolower($status),
                    'workflow_snapshot' => ['smoke' => true],
                    'next_action_role' => $status === 'Pending' ? 'Finance' : null,
                    'approval_history' => [],
                    'payroll_snapshot' => ['basic' => 100],
                    'notes' => "Smoke {$status} payroll claim",
                    'paid_at' => $status === 'Paid' ? now()->subDay() : null,
                    'paid_by_user_id' => $status === 'Paid' ? $finance->id : null,
                    'payment_reference' => $status === 'Paid' ? 'SMOKE-PAYMENT' : null,
                ],
            );
        }
    }

    private function seedSalaryAssignments(User $employee, User $finance): void
    {
        SalaryAssignment::updateOrCreate(
            ['reference_id' => 'SMK-SAL-ACTIVE'],
            [
                'employee_user_id' => $employee->id,
                'status' => 'Active',
                'effective_from' => '2026-07-01',
                'basic_salary' => 2500,
                'allowance_total' => 250,
                'allowances' => [['label' => 'Smoke Allowance', 'amount' => 250]],
                'employee_contributions' => [],
                'employer_contributions' => [],
                'notes_history' => [['note' => 'Smoke active salary assignment']],
                'updated_by' => $finance->name,
            ],
        );
    }

    private function seedReports(User $owner, ?Team $team): void
    {
        foreach (['erco', 'drill', 'fitness-test', 'inspection'] as $index => $type) {
            Report::updateOrCreate(
                ['report_uid' => "smoke-{$type}-uid"],
                [
                    'display_id' => 'SMK-' . strtoupper(str_replace('-', '', $type)),
                    'submission_key' => "smoke-{$type}-submission",
                    'owner_user_id' => $owner->id,
                    'report_type' => $type,
                    'status' => $index === 0 ? 'Pending' : 'Approved',
                    'workflow_stage' => $index === 0 ? 'pending_review' : 'approved',
                    'workflow_snapshot' => ['smoke' => true],
                    'next_action_role' => $index === 0 ? 'Incident Commander' : null,
                    'approval_history' => [],
                    'scope_team_id' => $team?->id,
                    'version' => 1,
                    'revision' => 0,
                    'payload' => [
                        'title' => "Smoke {$type} report",
                        'description' => 'Seeded smoke report for route/detail validation.',
                    ],
                    'inspection_has_checklist' => $type === 'inspection',
                    'submitted_at' => now()->subDays(2),
                ],
            );
        }
    }

    private function seedInspectionCatalog(User $actor): void
    {
        $location = InspectionLocation::updateOrCreate(
            ['normalized_name' => 'smoke-main-location'],
            [
                'parent_id' => null,
                'name' => 'Smoke Main Location',
                'description' => 'Smoke location catalog row.',
                'icon_key' => 'map-pin',
                'source' => 'smoke',
                'created_by' => $actor->id,
                'is_active' => true,
                'sort_order' => 9000,
            ],
        );

        InspectionEquipment::updateOrCreate(
            ['normalized_name' => 'smoke-equipment', 'inspection_type_key' => 'smoke'],
            [
                'inspection_type_label' => 'Smoke Inspection',
                'main_location_id' => $location->id,
                'main_location_name' => $location->name,
                'name' => 'Smoke Equipment',
                'description' => 'Smoke equipment catalog row.',
                'source' => 'smoke',
                'created_by' => $actor->id,
                'is_active' => true,
                'sort_order' => 9000,
            ],
        );

        InspectionFireExtinguisher::updateOrCreate(
            ['id_loc_no' => 'SMOKE-FE-001'],
            [
                'zone' => 'Smoke Zone',
                'main_location_name' => $location->name,
                'sub_location_name' => 'Smoke Sub Location',
                'barcode_no' => 'SMOKE-BARCODE-001',
                'fe_type' => 'ABC',
                'certification_validity' => now()->addYear()->toDateString(),
                'certification_validity_raw' => now()->addYear()->format('d/m/Y'),
                'days_left_to_expire' => 365,
                'source' => 'smoke',
                'created_by' => $actor->id,
                'is_active' => true,
                'sort_order' => 9000,
            ],
        );
    }

    private function seedFeedbackAndAiReports(User $reporter, User $reviewer): void
    {
        foreach (FeedbackReport::STATUSES as $status) {
            FeedbackReport::updateOrCreate(
                ['message' => "Smoke feedback {$status}"],
                [
                    'reporter_user_id' => $reporter->id,
                    'status' => $status,
                    'page_context' => ['path' => '/dashboard', 'title' => 'Smoke'],
                    'reporter_ip' => '127.0.0.1',
                    'reporter_user_agent' => 'Smoke Seeder',
                    'admin_note' => $status === FeedbackReport::STATUS_NEW ? null : "Smoke {$status}",
                    'reviewed_by' => $status === FeedbackReport::STATUS_NEW ? null : $reviewer->id,
                    'reviewed_at' => $status === FeedbackReport::STATUS_NEW ? null : now(),
                ],
            );
        }

        foreach (AiHelperResponseReport::STATUSES as $status) {
            AiHelperResponseReport::updateOrCreate(
                ['reason' => "Smoke AI helper report {$status}"],
                [
                    'reporter_user_id' => $reporter->id,
                    'status' => $status,
                    'assistant_content' => 'Smoke assistant response.',
                    'preceding_user_content' => 'Smoke user prompt.',
                    'page_context' => ['path' => '/dashboard', 'title' => 'Smoke'],
                    'chat_snapshot' => [],
                    'reporter_ip' => '127.0.0.1',
                    'reporter_user_agent' => 'Smoke Seeder',
                    'admin_note' => $status === AiHelperResponseReport::STATUS_NEW ? null : "Smoke {$status}",
                    'reviewed_by' => $status === AiHelperResponseReport::STATUS_NEW ? null : $reviewer->id,
                    'reviewed_at' => $status === AiHelperResponseReport::STATUS_NEW ? null : now(),
                ],
            );
        }
    }

    private function seedWorkflowNotifications(User $owner, User $hr, User $finance): void
    {
        $leaveId = Leave::where('display_id', 'SMK-LV-1')->value('id');
        $claimId = PayrollClaim::where('display_id', 'SMK-CLM-4')->value('id');

        $unread = WorkflowNotification::updateOrCreate(
            [
                'module' => 'leave',
                'event_type' => 'submitted',
                'record_type' => 'leave',
                'record_display_id' => 'SMK-LV-1',
            ],
            [
                'record_id' => $leaveId,
                'owner_user_id' => $owner->id,
                'actor_data' => ['userId' => $owner->id, 'name' => $owner->name, 'email' => $owner->email],
                'recipient_user_ids' => [$owner->id, $hr->id],
                'action_required' => true,
                'title' => 'Request submitted',
                'message' => "{$owner->name} submitted Leave SMK-LV-1.",
                'metadata' => [
                    'module' => 'leave',
                    'recordType' => 'leave',
                    'recordDisplayId' => 'SMK-LV-1',
                    'status' => 'pending',
                    'nextActionRole' => 'Human Resource',
                    'detailRouteKey' => $owner->id . '::' . $leaveId,
                ],
                'created_at' => now(),
            ],
        );

        $read = WorkflowNotification::updateOrCreate(
            [
                'module' => 'salary',
                'event_type' => 'approved',
                'record_type' => 'payroll_claim',
                'record_display_id' => 'SMK-CLM-4',
            ],
            [
                'record_id' => $claimId,
                'owner_user_id' => $owner->id,
                'actor_data' => ['userId' => $finance->id, 'name' => $finance->name, 'email' => $finance->email],
                'recipient_user_ids' => [$owner->id, $finance->id],
                'action_required' => false,
                'resolved_at' => now(),
                'title' => 'Request approved',
                'message' => 'Salary claim SMK-CLM-4 has been approved.',
                'metadata' => [
                    'module' => 'salary',
                    'recordType' => 'payroll_claim',
                    'recordDisplayId' => 'SMK-CLM-4',
                    'status' => 'approved',
                    'detailRouteKey' => 'SMK-CLM-4',
                ],
                'created_at' => now()->subHour(),
            ],
        );

        WorkflowNotificationRead::firstOrCreate(
            ['notification_id' => $read->id, 'user_id' => $owner->id],
            ['read_at' => now()],
        );

        WorkflowNotificationDismissal::firstOrCreate(
            ['notification_id' => $read->id, 'user_id' => $finance->id],
            ['dismissed_at' => now()],
        );

        WorkflowNotificationRead::where('notification_id', $unread->id)
            ->where('user_id', $hr->id)
            ->delete();
    }
}
