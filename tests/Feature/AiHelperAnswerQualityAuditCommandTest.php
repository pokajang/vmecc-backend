<?php

namespace Tests\Feature;

use App\Services\AiHelperAnswerQualityAuditService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AiHelperAnswerQualityAuditCommandTest extends TestCase
{
    public function test_phase_three_manifest_covers_every_workflow_and_quality_case(): void
    {
        $result = app(AiHelperAnswerQualityAuditService::class)->audit();

        $this->assertTrue($result['ready'], json_encode($result['failures']));
        $this->assertSame($result['workflows']['registry'], $result['workflows']['covered']);
        $this->assertSame([], $result['workflows']['missing']);
        $this->assertSame(0, $result['cases']['failed']);
        $this->assertGreaterThanOrEqual(25, $result['cases']['total']);
    }

    public function test_phase_three_audit_command_emits_machine_readable_success(): void
    {
        $this->artisan('ai-helper:answer-quality:audit --json')
            ->expectsOutputToContain('"ready":true')
            ->assertSuccessful();
    }

    public function test_existing_evaluation_command_exposes_the_answer_quality_suite(): void
    {
        $exitCode = Artisan::call('ai-helper:evaluate-knowledge', [
            '--suite' => 'answer-quality',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"suite":"answer-quality"', $output);
        $this->assertStringContainsString('"ready":true', $output);
    }

    public function test_existing_evaluation_command_filters_answer_quality_case_ids(): void
    {
        $exitCode = Artisan::call('ai-helper:evaluate-knowledge', [
            '--suite' => 'answer-quality',
            '--case' => ['clarify.report-type.en'],
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"scope":"selected"', $output);
        $this->assertStringContainsString('"total":1,"passed":1,"failed":0', $output);
        $this->assertStringContainsString('"id":"clarify.report-type.en"', $output);
        $this->assertStringNotContainsString('"id":"clarify.report-action.ms"', $output);
    }

    public function test_existing_evaluation_command_fails_for_unknown_answer_quality_case(): void
    {
        $exitCode = Artisan::call('ai-helper:evaluate-knowledge', [
            '--suite' => 'answer-quality',
            '--case' => ['unknown.case'],
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown answer-quality case id: unknown.case', $output);
    }

    public function test_phase_three_audit_fails_closed_when_a_workflow_case_is_removed(): void
    {
        $cases = collect(config('ai_helper_answer_quality.cases'))
            ->reject(fn (array $case) => ($case['expected_workflow'] ?? null) === 'reports.erco.manage')
            ->values()
            ->all();
        config()->set('ai_helper_answer_quality.cases', $cases);

        $result = app(AiHelperAnswerQualityAuditService::class)->audit();

        $this->assertFalse($result['ready']);
        $this->assertContains(
            'Workflow has no answer-quality case: reports.erco.manage',
            $result['errors'],
        );
    }
}
