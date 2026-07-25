<?php

namespace Tests\Feature;

use App\Services\AiHelperInputSafetyAuditService;
use Tests\TestCase;

class AiHelperInputSafetyAuditCommandTest extends TestCase
{
    public function test_phase_four_a_manifest_covers_all_decisions_and_cases(): void
    {
        $result = app(AiHelperInputSafetyAuditService::class)->audit();

        $this->assertTrue($result['ready'], json_encode($result['failures']));
        $this->assertSame(0, $result['cases']['failed']);
        $this->assertSame([], $result['decisions']['missing']);
        $this->assertGreaterThanOrEqual(20, $result['cases']['total']);
    }

    public function test_input_safety_audit_command_emits_machine_readable_success(): void
    {
        $this->artisan('ai-helper:input-safety:audit --json')
            ->expectsOutputToContain('"ready":true')
            ->assertSuccessful();
    }

    public function test_input_safety_audit_fails_closed_for_case_drift(): void
    {
        $cases = config('ai_helper_input_safety.cases');
        $cases[0]['decision'] = 'refuse_sensitive';
        config()->set('ai_helper_input_safety.cases', $cases);

        $this->artisan('ai-helper:input-safety:audit --json')
            ->expectsOutputToContain('"ready":false')
            ->assertFailed();
    }
}
