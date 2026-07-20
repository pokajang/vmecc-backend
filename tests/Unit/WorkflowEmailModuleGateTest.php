<?php

namespace Tests\Unit;

use App\Services\WorkflowNotifications\WorkflowEmailModuleGate;
use Tests\TestCase;

class WorkflowEmailModuleGateTest extends TestCase
{
    public function test_inspection_gate_takes_precedence_over_report_record_type(): void
    {
        config(['mail.workflow_notifications.modules' => [
            'report' => true,
            'inspection' => false,
        ]]);

        $this->assertTrue(WorkflowEmailModuleGate::enabledFor('report', 'report'));
        $this->assertFalse(WorkflowEmailModuleGate::enabledFor('inspection', 'report'));
    }

    public function test_known_record_type_gate_takes_precedence_over_module_gate(): void
    {
        config(['mail.workflow_notifications.modules' => [
            'salary' => true,
            'salary_assignment' => false,
        ]]);

        $this->assertFalse(WorkflowEmailModuleGate::enabledFor('salary', 'salary_assignment'));
    }
}
