<?php

namespace Tests\Unit;

use App\Models\Leave;
use App\Models\OvertimeRecord;
use App\Services\LeavePolicyService;
use App\Services\LeaveWorkflowService;
use App\Services\OvertimeWorkflowService;
use App\Services\PayrollClaimWorkflowService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkflowTransitionStateMachineTest extends TestCase
{
    public function test_leave_review_routes_to_the_configured_next_stage(): void
    {
        $service = new LeaveWorkflowService($this->createMock(LeavePolicyService::class));

        $withRecommendation = $service->advanceWorkflow(
            $this->leaveRecord(true),
            'review',
            21,
            'Contract Manager',
            'Reviewed.',
        );
        $withoutRecommendation = $service->advanceWorkflow(
            $this->leaveRecord(false),
            'review',
            21,
            'Contract Manager',
            'Reviewed.',
        );

        $this->assertSame('recommend', $withRecommendation['workflow_stage']);
        $this->assertSame('Human Resource', $withRecommendation['next_action_role']);
        $this->assertSame('approve', $withoutRecommendation['workflow_stage']);
        $this->assertSame('Client Contract Manager', $withoutRecommendation['next_action_role']);
    }

    #[DataProvider('terminalActions')]
    public function test_leave_terminal_actions_clear_stage_ownership(
        string $action,
        string $expectedStatus,
    ): void {
        $service = new LeaveWorkflowService($this->createMock(LeavePolicyService::class));
        $result = $service->advanceWorkflow(
            $this->leaveRecord(),
            $action,
            44,
            'Workflow Actor',
            'Recorded by test.',
        );

        $this->assertSame($expectedStatus, $result['status']);
        $this->assertSame('done', $result['workflow_stage']);
        $this->assertNull($result['next_action_role']);
        $this->assertSame((string) 44, $result['approval_history'][0]['byUserId']);
        $this->assertSame('Workflow Actor', $result['approval_history'][0]['by']);
    }

    public function test_leave_correction_routes_ownership_back_to_the_applicant(): void
    {
        $service = new LeaveWorkflowService($this->createMock(LeavePolicyService::class));
        $result = $service->advanceWorkflow(
            $this->leaveRecord(),
            'request_correction',
            8,
            'Reviewer',
            'Correct the dates.',
        );

        $this->assertSame('Needs Correction', $result['status']);
        $this->assertSame('correction', $result['workflow_stage']);
        $this->assertNull($result['next_action_role']);
        $this->assertSame('Correct the dates.', $result['approval_history'][0]['remarks']);
    }

    public function test_overtime_follows_review_recommend_approve_and_caps_history(): void
    {
        $service = new OvertimeWorkflowService;
        $history = collect(range(1, 20))->map(fn (int $id) => [
            'id' => "history-{$id}",
            'action' => 'Updated',
        ])->all();
        $record = $this->overtimeRecord();
        $record->approval_history = $history;

        $reviewed = $service->advanceWorkflow($record, 'review', 31, 'Reviewer', 'Ready.');
        $this->assertSame('recommend', $reviewed['workflow_stage']);
        $this->assertSame('Human Resource', $reviewed['next_action_role']);
        $this->assertCount(20, $reviewed['approval_history']);
        $this->assertSame('history-2', $reviewed['approval_history'][0]['id']);

        $record->workflow_stage = 'recommend';
        $record->approval_history = $reviewed['approval_history'];
        $recommended = $service->advanceWorkflow($record, 'recommend', 32, 'Recommender');
        $this->assertSame('approve', $recommended['workflow_stage']);
        $this->assertSame('Client Contract Manager', $recommended['next_action_role']);

        $record->workflow_stage = 'approve';
        $record->approval_history = $recommended['approval_history'];
        $approved = $service->advanceWorkflow($record, 'approve', 33, 'Approver');
        $this->assertSame('Approved', $approved['status']);
        $this->assertSame('done', $approved['workflow_stage']);
        $this->assertNull($approved['next_action_role']);
        $this->assertSame('Approver', $approved['approval_history'][19]['by']);
    }

    public function test_overtime_skips_recommendation_when_snapshot_disables_it(): void
    {
        $service = new OvertimeWorkflowService;
        $record = $this->overtimeRecord(false);
        $result = $service->advanceWorkflow($record, 'review', 31, 'Reviewer');

        $this->assertSame('approve', $result['workflow_stage']);
        $this->assertSame('Client Contract Manager', $result['next_action_role']);
    }

    public function test_payroll_claim_follows_check_review_approve_with_attribution(): void
    {
        $service = new PayrollClaimWorkflowService($this->createMock(OvertimeWorkflowService::class));
        $snapshot = [
            'checkRole' => 'Admin',
            'reviewRole' => 'Finance',
            'approveRole' => 'Contract Manager',
        ];

        $checked = $service->advanceWorkflow($snapshot, [], 'check', 51, 'Checker', 'Checked.');
        $this->assertSame('review', $checked['workflowStage']);
        $this->assertSame('Finance', $checked['nextActionRole']);

        $reviewed = $service->advanceWorkflow(
            $snapshot,
            $checked['approvalHistory'],
            'review',
            52,
            'Reviewer',
        );
        $this->assertSame('approve', $reviewed['workflowStage']);
        $this->assertSame('Contract Manager', $reviewed['nextActionRole']);

        $approved = $service->advanceWorkflow(
            $snapshot,
            $reviewed['approvalHistory'],
            'approve',
            53,
            'Approver',
        );
        $this->assertSame('Approved', $approved['status']);
        $this->assertSame('done', $approved['workflowStage']);
        $this->assertNull($approved['nextActionRole']);
        $this->assertSame(['Checker', 'Reviewer', 'Approver'], array_column($approved['approvalHistory'], 'by'));
        $this->assertSame(['51', '52', '53'], array_column($approved['approvalHistory'], 'byUserId'));
    }

    public static function terminalActions(): array
    {
        return [
            'reject' => ['reject', 'Rejected'],
            'cancel' => ['cancel', 'Cancelled'],
            'approve' => ['approve', 'Approved'],
        ];
    }

    private function leaveRecord(bool $requireRecommendation = true): Leave
    {
        return new Leave([
            'status' => 'Pending',
            'workflow_stage' => 'review',
            'next_action_role' => 'Contract Manager',
            'workflow_snapshot' => [
                'reviewRole' => 'Contract Manager',
                'recommendRole' => 'Human Resource',
                'approveRole' => 'Client Contract Manager',
                'requireRecommendation' => $requireRecommendation,
            ],
            'approval_history' => [],
        ]);
    }

    private function overtimeRecord(bool $requireRecommendation = true): OvertimeRecord
    {
        return new OvertimeRecord([
            'status' => 'Pending',
            'workflow_stage' => 'review',
            'next_action_role' => 'Contract Manager',
            'workflow_snapshot' => [
                'reviewRole' => 'Contract Manager',
                'recommendRole' => 'Human Resource',
                'approveRole' => 'Client Contract Manager',
                'requireRecommendation' => $requireRecommendation,
            ],
            'approval_history' => [],
        ]);
    }
}
