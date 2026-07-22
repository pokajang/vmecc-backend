<?php

namespace App\Services\AiHelperWorkflows;

final class SelfServiceWorkflows
{
    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            self::workflow('leave.self_service', 'leave-self-service', 'Leave', 'Apply for Leave', [
                ['key' => 'open_leave', 'kind' => 'open_menu', 'target' => 'Leave'],
                ['key' => 'complete_request', 'kind' => 'complete', 'targets' => ['Leave Type', 'Start Date', 'End Date', 'Time Slot', 'Reason', 'Cover By']],
                ['key' => 'save_or_submit', 'kind' => 'review', 'targets' => ['Save Draft', 'Submit']],
                ['key' => 'verify_status', 'kind' => 'verify', 'targets' => ['Status', 'Current Action Owner']],
            ], [
                'save_draft' => 'Save Draft', 'submit' => 'Submit', 'edit' => 'Edit', 'cancel' => 'Cancel',
            ], [
                'leave_type' => 'Leave Type', 'start_date' => 'Start Date', 'end_date' => 'End Date', 'reason' => 'Reason',
            ]),
            self::workflow('overtime.self_service', 'overtime-self-service', 'Overtime', 'Submit Overtime', [
                ['key' => 'open_overtime', 'kind' => 'open_menu', 'target' => 'Overtime'],
                ['key' => 'select_type', 'kind' => 'complete', 'targets' => ['Application Type', 'Continue']],
                ['key' => 'complete_request', 'kind' => 'complete', 'targets' => ['Date', 'Start time', 'End time', 'Reason / work done']],
                ['key' => 'save_or_submit', 'kind' => 'review', 'targets' => ['Save draft', 'Submit request']],
                ['key' => 'verify_status', 'kind' => 'verify', 'targets' => ['Status']],
            ], [
                'save_draft' => 'Save draft', 'submit_request' => 'Submit request', 'edit' => 'Edit', 'cancel' => 'Cancel',
            ], [
                'application_type' => 'Application Type', 'date' => 'Date', 'start_time' => 'Start time', 'end_time' => 'End time', 'reason' => 'Reason / work done',
            ]),
            self::workflow('payroll.payslip.view', 'payroll-self-service', 'Payroll', 'View Payslips', [
                ['key' => 'open_payroll', 'kind' => 'open_menu', 'target' => 'Payroll'],
                ['key' => 'open_payslips', 'kind' => 'select', 'target' => 'Payslips'],
                ['key' => 'select_period', 'kind' => 'complete', 'targets' => ['Pay Period', 'Payment Date']],
                ['key' => 'download', 'kind' => 'select', 'target' => 'Download payslip'],
            ], ['download_payslip' => 'Download payslip']),
            self::workflow('payroll.claim.submit', 'payroll-claims', 'Payroll', 'Create a Claim', [
                ['key' => 'open_payroll', 'kind' => 'open_menu', 'target' => 'Payroll'],
                ['key' => 'open_claims', 'kind' => 'select', 'target' => 'Claims'],
                ['key' => 'complete_claim', 'kind' => 'complete', 'targets' => ['Claim Type', 'Date or Period', 'Amount', 'Description', 'Evidence']],
                ['key' => 'save_or_submit', 'kind' => 'review', 'targets' => ['Save draft', 'Submit claim']],
                ['key' => 'verify_status', 'kind' => 'verify', 'targets' => ['Status', 'History']],
            ], [
                'save_draft' => 'Save draft', 'submit_claim' => 'Submit claim', 'edit' => 'Edit', 'cancel_claim' => 'Cancel claim',
            ], [
                'claim_type' => 'Claim Type', 'amount' => 'Amount', 'description' => 'Description', 'evidence' => 'Evidence',
            ]),
        ];
    }

    private static function workflow(
        string $key,
        string $guideKey,
        string $module,
        string $action,
        array $steps,
        array $actions,
        array $fields = [],
    ): array {
        return [
            'key' => $key,
            'guide_key' => $guideKey,
            'task_keys' => [$key],
            'entity_keys' => [],
            'module' => $module,
            'action' => $action,
            'type' => $action,
            'source_labels' => match ($key) {
                'leave.self_service' => ['Leave', 'Save Draft', 'Submit', 'Current Action Owner'],
                'overtime.self_service' => ['Overtime', 'Application Type', 'Continue', 'Save draft', 'Submit request'],
                'payroll.payslip.view' => ['Payroll', 'Payslips', 'Download payslip'],
                'payroll.claim.submit' => ['Payroll', 'Claims', 'Save draft', 'Current Action Owner'],
            },
            'steps' => $steps,
            'ui' => [
                'actions' => $actions,
                'fields' => $fields,
                'statuses' => [
                    'draft' => 'Draft', 'pending_review' => 'Pending Review', 'approved' => 'Approved',
                    'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'needs_correction' => 'Needs Correction',
                ],
            ],
        ];
    }
}
