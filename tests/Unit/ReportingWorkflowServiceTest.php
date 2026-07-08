<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\ReportingWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_include_all_managed_report_modules(): void
    {
        $rules = app(ReportingWorkflowService::class)->loadWorkflowRules();

        $this->assertSame('Assistant Incident Commander', data_get($rules, 'modules.inspection.fallback.reviewRole'));
        $this->assertSame('Incident Commander', data_get($rules, 'modules.erco.fallback.reviewRole'));
        $this->assertSame('Incident Commander', data_get($rules, 'modules.drill.fallback.reviewRole'));
        $this->assertSame('Incident Commander', data_get($rules, 'modules.fitness-test.fallback.reviewRole'));
    }

    public function test_save_module_workflow_rules_preserves_other_modules(): void
    {
        $service = app(ReportingWorkflowService::class);

        $saved = $service->saveModuleWorkflowRules('erco', [
            'fallback' => [
                'reviewRole' => 'Assistant Incident Commander',
                'fallbackReviewRole' => 'Incident Commander',
                'approveRole' => 'Incident Commander',
            ],
            'options' => [
                'preventSelfApprove' => false,
            ],
        ]);

        $rules = $service->loadWorkflowRules();

        $this->assertSame('Assistant Incident Commander', $saved['fallback']['reviewRole']);
        $this->assertFalse($saved['options']['preventSelfApprove']);
        $this->assertSame('Assistant Incident Commander', data_get($rules, 'modules.erco.fallback.reviewRole'));
        $this->assertSame('Incident Commander', data_get($rules, 'modules.drill.fallback.reviewRole'));
        $this->assertSame('Assistant Incident Commander', data_get($rules, 'modules.inspection.fallback.reviewRole'));
    }

    public function test_legacy_inspection_setting_is_used_when_reporting_setting_is_missing(): void
    {
        Setting::query()->create([
            'key' => 'inspection_workflow_rules',
            'value' => [
                'fallback' => [
                    'reviewRole' => 'Incident Commander',
                    'fallbackReviewRole' => 'Incident Commander',
                    'approveRole' => 'Incident Commander',
                ],
                'options' => [
                    'preventSelfReview' => false,
                ],
            ],
        ]);

        $rules = app(ReportingWorkflowService::class)->loadWorkflowRules();

        $this->assertSame('Incident Commander', data_get($rules, 'modules.inspection.fallback.reviewRole'));
        $this->assertFalse(data_get($rules, 'modules.inspection.options.preventSelfReview'));
        $this->assertSame('Incident Commander', data_get($rules, 'modules.erco.fallback.reviewRole'));
    }
}
