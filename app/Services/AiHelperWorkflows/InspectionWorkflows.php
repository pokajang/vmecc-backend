<?php

namespace App\Services\AiHelperWorkflows;

final class InspectionWorkflows
{
    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'inspection.conduct.fire_truck',
                'guide_key' => 'inspection-fire-truck-conduct',
                'task_keys' => ['inspection.conduct'],
                'entity_keys' => ['fire_truck'],
                'module' => 'Inspection',
                'action' => 'Conduct Inspection',
                'type' => 'Fire Truck Daily Readiness',
                'source_labels' => ['Inspection', 'Conduct Inspection', 'Fire Truck Daily Readiness', 'Save Draft', 'Continue to Review', 'Submit Report'],
                'steps' => [
                    ['key' => 'open_inspection', 'kind' => 'open_menu', 'target' => 'Inspection'],
                    ['key' => 'start_inspection', 'kind' => 'select', 'target' => 'Conduct Inspection'],
                    ['key' => 'select_type', 'kind' => 'select', 'target' => 'Fire Truck Daily Readiness'],
                    ['key' => 'select_asset', 'kind' => 'complete', 'targets' => ['Fire Truck', 'Compartment']],
                    ['key' => 'complete_checklist', 'kind' => 'complete', 'targets' => ['Daily Readiness', 'Required Readings', 'One-off Checks']],
                    ['key' => 'review', 'kind' => 'review', 'targets' => ['Continue to Review', 'Submit Report']],
                ],
                'ui' => self::inspectionUi(),
            ],
            [
                'key' => 'inspection.conduct.hse',
                'guide_key' => 'inspection-manage',
                'task_keys' => ['inspection.conduct'],
                'entity_keys' => ['hse_inspection'],
                'module' => 'Inspection',
                'action' => 'Conduct Inspection',
                'type' => 'Health Safety Environment',
                'source_labels' => ['Inspection', 'Conduct Inspection', 'Continue to Review', 'Submit Report'],
                'steps' => [
                    ['key' => 'open_inspection', 'kind' => 'open_menu', 'target' => 'Inspection'],
                    ['key' => 'start_inspection', 'kind' => 'select', 'target' => 'Conduct Inspection'],
                    ['key' => 'select_type', 'kind' => 'select', 'target' => 'Health Safety Environment'],
                    ['key' => 'record_observation', 'kind' => 'complete', 'targets' => ['Unsafe Act or Unsafe Condition', 'Observation', 'Evidence']],
                    ['key' => 'review', 'kind' => 'review', 'targets' => ['Continue to Review', 'Submit Report']],
                ],
                'ui' => self::inspectionUi(),
            ],
            [
                'key' => 'inspection.conduct.fire_extinguisher',
                'guide_key' => 'inspection-fire-extinguisher-conduct',
                'task_keys' => ['inspection.conduct'],
                'entity_keys' => ['extinguisher'],
                'module' => 'Inspection',
                'action' => 'Conduct Inspection',
                'type' => 'Fire Extinguisher',
                'source_labels' => ['Inspection', 'Conduct Inspection', 'Fire Extinguisher', 'Choose Inspection Mode', 'By Area', 'Serial Number', 'Continue to Review', 'Submit Report'],
                'steps' => [
                    ['key' => 'open_inspection', 'kind' => 'open_menu', 'target' => 'Inspection'],
                    ['key' => 'start_inspection', 'kind' => 'select', 'target' => 'Conduct Inspection'],
                    ['key' => 'select_type', 'kind' => 'select', 'target' => 'Fire Extinguisher'],
                    ['key' => 'choose_mode', 'kind' => 'select', 'target' => 'Choose Inspection Mode'],
                    ['key' => 'select_mode', 'kind' => 'choose', 'targets' => ['By Area', 'Serial Number']],
                    ['key' => 'area_path', 'kind' => 'branch', 'target' => 'By Area', 'targets' => ['Zone', 'Main Area', 'Location']],
                    ['key' => 'serial_path', 'kind' => 'branch', 'target' => 'Serial Number', 'targets' => ['Search FE by Serial Number']],
                    ['key' => 'complete_checklist', 'kind' => 'complete', 'targets' => ['Checks', 'Remarks', 'Findings', 'Evidence']],
                    ['key' => 'review', 'kind' => 'review', 'targets' => ['Continue to Review', 'Submit Report']],
                ],
                'ui' => self::inspectionUi(),
            ],
            [
                'key' => 'inspection.conduct',
                'guide_key' => 'inspection-manage',
                'task_keys' => ['inspection.conduct'],
                'entity_keys' => [],
                'module' => 'Inspection',
                'action' => 'Conduct Inspection',
                'type' => 'Inspection',
                'source_labels' => ['Inspection', 'Conduct Inspection', 'Continue to Review', 'Submit Report'],
                'steps' => [
                    ['key' => 'open_inspection', 'kind' => 'open_menu', 'target' => 'Inspection'],
                    ['key' => 'start_inspection', 'kind' => 'select', 'target' => 'Conduct Inspection'],
                    ['key' => 'select_type', 'kind' => 'select', 'target' => 'Inspection Type'],
                    ['key' => 'complete_checklist', 'kind' => 'complete', 'targets' => ['Setup', 'Checklist', 'Findings', 'Evidence']],
                    ['key' => 'review', 'kind' => 'review', 'targets' => ['Continue to Review', 'Submit Report']],
                ],
                'ui' => self::inspectionUi(),
            ],
            [
                'key' => 'inspection.workflow.configure',
                'guide_key' => 'inspection-workflow-settings',
                'task_keys' => ['inspection.workflow.configure'],
                'entity_keys' => ['workflow_setting'],
                'module' => 'Settings',
                'action' => 'Configure Inspection Workflow',
                'type' => 'Inspection Workflow Settings',
                'source_labels' => ['Reporting Settings', 'Inspection Workflow Settings', 'Review', 'Fallback Review', 'Approve', 'Save'],
                'steps' => [
                    ['key' => 'open_reporting_settings', 'kind' => 'open_menu', 'target' => 'Reporting Settings'],
                    ['key' => 'open_inspection', 'kind' => 'select', 'target' => 'Inspection'],
                    ['key' => 'open_workflow_settings', 'kind' => 'select', 'target' => 'Inspection Workflow Settings'],
                    ['key' => 'select_roles', 'kind' => 'complete', 'targets' => ['Review', 'Fallback Review', 'Approve']],
                    ['key' => 'configure_safeguards', 'kind' => 'complete', 'targets' => ['Team-scoped AIC', 'Self-review and Self-approve Safeguards']],
                    ['key' => 'save_and_verify', 'kind' => 'review', 'targets' => ['Save', 'Representative Submission']],
                ],
                'ui' => ['actions' => [], 'fields' => [], 'statuses' => []],
            ],
        ];
    }

    /** @return array<string, array<string, string>> */
    private static function inspectionUi(): array
    {
        return [
            'actions' => [
                'save_draft' => 'Save Draft',
                'continue_review' => 'Continue to Review',
                'submit_report' => 'Submit Report',
            ],
            'fields' => [
                'inspection_date' => 'Inspection Date',
                'location' => 'Location',
                'description' => 'Description',
                'inspection_scope' => 'Inspection Scope',
                'fire_truck' => 'Fire Truck',
                'compartment' => 'Compartment',
                'odometer_reading' => 'Odometer Reading',
                'fuel_level' => 'Fuel Level',
                'daily_readiness' => 'Daily Readiness Checks',
                'one_off_checks' => 'One-off Checks',
                'checks' => 'Checklist',
                'remarks' => 'Remarks',
                'unsafe_act_or_condition' => 'Unsafe Act or Unsafe Condition',
                'observation' => 'Observation',
                'findings' => 'Findings',
                'evidence' => 'Evidence',
            ],
            'statuses' => [
                'draft' => 'Draft',
                'review' => 'Review',
                'submitted' => 'Submitted',
            ],
        ];
    }
}
