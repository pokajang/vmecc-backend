<?php

namespace App\Services\AiHelperWorkflows;

final class ReportWorkflows
{
    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            self::authoringWorkflow(
                key: 'reports.erco.manage',
                guideKey: 'erco-reports',
                entity: 'erco',
                type: 'ERCO Report',
                menu: 'ERCO',
                newAction: 'New ERCO Report',
                fields: ['ERCO Form', 'Post-Incident Analysis', 'Photos'],
                sourceLabels: ['Reports', 'ERCO', 'New ERCO Report', 'Save Draft', 'Review', 'Approve', 'Reject', 'Download'],
            ),
            self::authoringWorkflow(
                key: 'reports.drill.manage',
                guideKey: 'drill-reports',
                entity: 'drill',
                type: 'Drill Report',
                menu: 'Drill',
                newAction: 'New Drill Report',
                fields: ['Drill Sections', 'Post-Exercise Analysis', 'Photos'],
                sourceLabels: ['Reports', 'Drill', 'New Drill Report', 'Save Draft', 'Review', 'Approved', 'Rejected', 'Download'],
            ),
            self::authoringWorkflow(
                key: 'reports.fitness.manage',
                guideKey: 'fitness-reports',
                entity: 'fitness',
                type: 'Fitness Test Report',
                menu: 'Fitness Test',
                newAction: 'New Fitness Test Report',
                fields: ['Fitness-Test Data', 'Measurements', 'Photos'],
                sourceLabels: ['Reports', 'Fitness Test', 'New Fitness Test Report', 'save a draft', 'reviewer', 'approver'],
            ),
            [
                'key' => 'reports.review',
                'guide_key' => 'report-management',
                'task_keys' => ['reports.review'],
                'entity_keys' => ['report_management'],
                'module' => 'Reports',
                'action' => 'Review a Report',
                'type' => 'Report Review',
                'source_labels' => ['Reports', 'Review', 'Approve', 'Reject', 'Submitted', 'Reviewed', 'Approved'],
                'steps' => [
                    ['key' => 'open_reports', 'kind' => 'open_menu', 'target' => 'Reports'],
                    ['key' => 'select_report', 'kind' => 'complete', 'targets' => ['Report Type', 'Status', 'Assigned Team or Location']],
                    ['key' => 'open_record', 'kind' => 'review', 'targets' => ['Content', 'Timeline', 'Current Status', 'Latest Details']],
                    ['key' => 'choose_action', 'kind' => 'choose', 'targets' => ['Review', 'Approve', 'Reject']],
                    ['key' => 'verify_status', 'kind' => 'verify', 'targets' => ['Status', 'History', 'Actor', 'Timestamp']],
                ],
                'ui' => [
                    'actions' => [
                        'review' => 'Review',
                        'approve' => 'Approve',
                        'reject' => 'Reject',
                    ],
                    'fields' => ['rejection_remarks' => 'Rejection Remarks'],
                    'statuses' => [
                        'submitted' => 'Submitted',
                        'reviewed' => 'Reviewed',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ],
                ],
            ],
        ];
    }

    private static function authoringWorkflow(
        string $key,
        string $guideKey,
        string $entity,
        string $type,
        string $menu,
        string $newAction,
        array $fields,
        array $sourceLabels,
    ): array {
        return [
            'key' => $key,
            'guide_key' => $guideKey,
            'task_keys' => [$key],
            'entity_keys' => [$entity],
            'module' => 'Reports',
            'action' => 'Create or Manage a Report',
            'type' => $type,
            'source_labels' => $sourceLabels,
            'steps' => [
                ['key' => 'open_reports', 'kind' => 'open_menu', 'target' => 'Reports'],
                ['key' => 'open_report_type', 'kind' => 'select', 'target' => $menu],
                ['key' => 'choose_record', 'kind' => 'choose', 'targets' => [$newAction, 'Existing Draft']],
                ['key' => 'complete_report', 'kind' => 'complete', 'targets' => $fields],
                ['key' => 'save_or_submit', 'kind' => 'review', 'targets' => ['Save Draft', 'Submit']],
                ['key' => 'verify_record', 'kind' => 'verify', 'targets' => ['Status', 'Latest Details', 'Timeline', 'Available Actions']],
            ],
            'ui' => [
                'actions' => [
                    'save_draft' => 'Save Draft',
                    'submit' => 'Submit',
                    'review' => 'Review',
                    'approve' => 'Approve',
                    'reject' => 'Reject',
                    'download' => 'Download',
                ],
                'fields' => [],
                'statuses' => [
                    'draft' => 'Draft',
                    'submitted' => 'Submitted',
                    'reviewed' => 'Reviewed',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                ],
            ],
        ];
    }
}
