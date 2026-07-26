<?php

namespace Tests\Feature;

use App\Services\AiHelperCoverageAuditService;
use Tests\TestCase;

class AiHelperCoverageAuditCommandTest extends TestCase
{
    public function test_coverage_manifest_classifies_every_module_and_matches_every_query(): void
    {
        $result = app(AiHelperCoverageAuditService::class)->audit();

        $this->assertTrue($result['phase_1_ready'], implode("\n", $result['errors']));
        $this->assertSame($result['modules']['catalog'], $result['modules']['classified']);
        $this->assertSame([], $result['modules']['missing']);
        $this->assertSame([], $result['modules']['unknown']);
        $this->assertSame([], $result['modules']['duplicates']);
        $this->assertSame(54, $result['guides']);
        $this->assertSame(20, $result['workflows']);
        $this->assertSame(47, $result['topics']['registry']);
        $this->assertSame($result['topics']['registry'], $result['topics']['covered']);
        $this->assertSame([], $result['topics']['missing']);
        $this->assertSame([], $result['topics']['unknown']);
        $this->assertGreaterThan(0, $result['queries']['total']);
        $this->assertSame(
            $result['queries']['total'],
            $result['queries']['matched'] + $result['queries']['gaps'],
        );
        $this->assertTrue($result['phase_2_ready']);
        $this->assertFalse($result['phase_2_required']);
        $this->assertSame(0, $result['queries']['gaps']);
    }

    public function test_coverage_audit_command_succeeds_when_both_phase_contracts_are_complete(): void
    {
        $this->artisan('ai-helper:coverage:audit --json')
            ->expectsOutputToContain('"phase_1_ready":true,"phase_2_ready":true')
            ->assertSuccessful();
    }

    public function test_coverage_audit_command_fails_when_a_representative_query_has_a_gap(): void
    {
        $queries = config('ai_helper_coverage.queries');
        $queries[0]['tasks'] = ['reports.review'];
        config()->set('ai_helper_coverage.queries', $queries);

        $this->artisan('ai-helper:coverage:audit --json')
            ->expectsOutputToContain('"phase_1_ready":true,"phase_2_ready":false,"phase_2_required":true')
            ->assertFailed();
    }

    public function test_coverage_audit_fails_closed_for_module_or_topic_drift(): void
    {
        $modules = config('ai_helper_coverage.modules');
        $modules['product_navigation'] = array_values(array_diff(
            $modules['product_navigation'],
            ['messages'],
        ));
        config()->set('ai_helper_coverage.modules', $modules);

        $queries = config('ai_helper_coverage.queries');
        $queries[0]['topics'][] = 'unregistered_topic';
        config()->set('ai_helper_coverage.queries', $queries);

        $result = app(AiHelperCoverageAuditService::class)->audit();

        $this->assertFalse($result['phase_1_ready']);
        $this->assertContains('Unclassified module: messages', $result['errors']);
        $this->assertContains(
            'Coverage query references an unknown topic: unregistered_topic',
            $result['errors'],
        );
    }
}
