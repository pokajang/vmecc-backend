<?php

namespace App\Services\AiHelperWorkflows;

final class FinanceWorkflows
{
    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'payroll.payment.manage',
                'guide_key' => 'payment-actions',
                'task_keys' => ['payroll.payment.manage'],
                'entity_keys' => ['payment'],
                'module' => 'Payroll',
                'action' => 'Record or Reverse Payment',
                'type' => 'Claim Payment',
                'source_labels' => ['Payroll Records', 'Salary Records', 'Claim Records', 'Mark paid', 'Unmark paid', 'Approved', 'Paid'],
                'steps' => [
                    ['key' => 'open_payroll_records', 'kind' => 'open_menu', 'target' => 'Payroll Records'],
                    ['key' => 'open_claim_records', 'kind' => 'choose', 'targets' => ['Salary Records', 'Claim Records']],
                    ['key' => 'select_approved_claim', 'kind' => 'complete', 'targets' => ['Approved Claim', 'Current Paid State', 'Latest Details']],
                    ['key' => 'choose_payment_action', 'kind' => 'choose', 'targets' => ['Mark paid', 'Unmark paid']],
                    ['key' => 'complete_payment', 'kind' => 'complete', 'targets' => ['Payment Date', 'Payment Reference or Reversal Reason']],
                    ['key' => 'verify_payment', 'kind' => 'verify', 'targets' => ['Paid State', 'History Entry']],
                ],
                'ui' => [
                    'actions' => [
                        'mark_paid' => 'Mark paid',
                        'unmark_paid' => 'Unmark paid',
                    ],
                    'fields' => [
                        'payment_date' => 'Payment Date',
                        'payment_reference' => 'Payment Reference',
                        'reversal_reason' => 'Reversal Reason',
                    ],
                    'statuses' => [
                        'approved' => 'Approved',
                        'paid' => 'Paid',
                        'unpaid' => 'Unpaid',
                    ],
                ],
            ],
        ];
    }
}
