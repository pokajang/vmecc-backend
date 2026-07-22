<?php

namespace Tests\Unit;

use App\Services\AiHelperUiStateNormalizer;
use App\Services\AiHelperWorkflowRegistry;
use Tests\TestCase;

class AiHelperUiStateNormalizerTest extends TestCase
{
    public function test_ui_state_is_tokenized_and_intersected_with_the_authorized_workflow_schema(): void
    {
        $normalizer = app(AiHelperUiStateNormalizer::class);
        $workflow = collect(app(AiHelperWorkflowRegistry::class)->all())
            ->firstWhere('key', 'inspection.conduct.fire_truck');
        $state = $normalizer->normalize([
            'record_status' => 'draft',
            'current_step' => 'complete_checklist',
            'missing_fields' => ['odometer_reading', 'password', '<script>'],
            'available_actions' => ['continue_review', 'delete_everything'],
        ]);
        $context = $normalizer->forWorkflow($state, $workflow);

        $this->assertSame('Draft', $context['record_status']);
        $this->assertSame('complete_checklist', $context['current_step']);
        $this->assertSame(['Odometer Reading'], $context['missing_fields']);
        $this->assertSame(['Continue to Review'], $context['available_actions']);
        $this->assertStringNotContainsString('password', json_encode($context));
        $this->assertStringNotContainsString('script', json_encode($context));
    }
}
