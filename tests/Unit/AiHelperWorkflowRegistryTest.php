<?php

namespace Tests\Unit;

use App\Services\AiHelperWorkflowRegistry;
use App\Services\AiHelperWorkflowRenderer;
use Tests\TestCase;

class AiHelperWorkflowRegistryTest extends TestCase
{
    public function test_workflows_are_unique_navigation_only_and_reference_known_guides(): void
    {
        $registry = app(AiHelperWorkflowRegistry::class);
        $workflows = collect($registry->all());

        $this->assertSame([], $registry->validationErrors());
        $this->assertSame($workflows->count(), $workflows->pluck('key')->unique()->count());
        $this->assertTrue($workflows->every(fn (array $workflow) => $workflow['fact_scope'] === 'navigation'));
    }

    public function test_entity_specific_workflow_wins_without_hiding_the_generic_fallback(): void
    {
        $registry = app(AiHelperWorkflowRegistry::class);

        $fireTruck = $registry->candidatesFor([
            'task_keys' => ['inspection.conduct'],
            'entity_keys' => ['fire_truck'],
        ]);
        $generic = $registry->candidatesFor([
            'task_keys' => ['inspection.conduct'],
            'entity_keys' => [],
        ]);

        $this->assertSame('inspection.conduct.fire_truck', $fireTruck[0]['key']);
        $this->assertSame('inspection.conduct', $generic[0]['key']);
    }

    public function test_a_compound_operational_request_is_not_reduced_to_one_navigation_workflow(): void
    {
        $candidates = app(AiHelperWorkflowRegistry::class)->candidatesFor([
            'task_keys' => ['inspection.conduct', 'inspection.physical.maintain'],
            'entity_keys' => ['extinguisher'],
        ]);

        $this->assertSame([], $candidates);
    }

    public function test_fire_extinguisher_workflow_explains_area_and_serial_number_as_alternatives(): void
    {
        $workflow = app(AiHelperWorkflowRegistry::class)->candidatesFor([
            'task_keys' => ['inspection.conduct'],
            'entity_keys' => ['extinguisher'],
        ])[0];

        $answer = app(AiHelperWorkflowRenderer::class)->render($workflow, false);

        $this->assertStringContainsString('Choose one: **By Area** and **Serial Number**.', $answer);
        $this->assertStringContainsString('For **By Area**, complete **Zone**, **Main Area**, and **Location**.', $answer);
        $this->assertStringContainsString('For **Serial Number**, complete **Search FE by Serial Number**.', $answer);
        $this->assertStringNotContainsString('Area or Serial Number', $answer);
    }

    public function test_submitted_record_state_does_not_suggest_editing_or_submission(): void
    {
        $workflow = app(AiHelperWorkflowRegistry::class)->candidatesFor([
            'task_keys' => ['inspection.conduct'],
            'entity_keys' => ['fire_truck'],
        ])[0];

        $answer = app(AiHelperWorkflowRenderer::class)->render($workflow, false, [
            'record_status' => 'Submitted',
            'missing_fields' => ['Evidence'],
            'available_actions' => ['Save Draft'],
        ]);

        $this->assertStringContainsString('This record is **Submitted**.', $answer);
        $this->assertStringNotContainsString('Next, complete', $answer);
        $this->assertStringNotContainsString('next available action', $answer);
    }
}
