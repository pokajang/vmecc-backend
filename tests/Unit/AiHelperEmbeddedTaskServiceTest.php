<?php

namespace Tests\Unit;

use App\Services\AiHelperEmbeddedTaskService;
use App\Services\AiHelperOpenAiService;
use App\Services\AiHelperRequestDeadline;
use RuntimeException;
use Tests\TestCase;

class AiHelperEmbeddedTaskServiceTest extends TestCase
{
    public function test_it_uses_a_strict_task_schema_and_normalizes_an_inspection_translation(): void
    {
        $deadline = AiHelperRequestDeadline::fromSeconds(20);
        $this->mock(AiHelperOpenAiService::class, function ($mock) use ($deadline) {
            $mock->shouldReceive('structuredResponse')
                ->once()
                ->withArgs(function (
                    $model,
                    $instructions,
                    $input,
                    $schemaName,
                    $schema,
                    $timeout,
                    $actualDeadline,
                    $safetyIdentifier,
                ) use ($deadline) {
                    $this->assertStringContainsString('bounded VMECC in-form assistant', $instructions);
                    $this->assertSame('vmecc_inspection_translate_finding', $schemaName);
                    $this->assertFalse($schema['additionalProperties']);
                    $this->assertSame(['text'], $schema['required']);
                    $this->assertSame(1, $schema['properties']['text']['minLength']);
                    $this->assertSame(10000, $schema['properties']['text']['maxLength']);
                    $this->assertSame($deadline, $actualDeadline);
                    $this->assertSame('vmecc-user-7', $safetyIdentifier);

                    return true;
                })
                ->andReturn([
                    'data' => ['text' => '  Emergency   exit was obstructed.  '],
                    'response_id' => 'response-1',
                    'provider_request_id' => 'request-1',
                    'usage' => ['input_tokens' => 20, 'output_tokens' => 8],
                ]);
        });

        $result = app(AiHelperEmbeddedTaskService::class)->execute(
            AiHelperEmbeddedTaskService::INSPECTION_TRANSLATE_FINDING,
            '{"sourceText":"laluan kecemasan terhalang"}',
            'en',
            $deadline,
            'vmecc-user-7',
        );

        $this->assertSame(['text' => 'Emergency exit was obstructed.'], $result['embedded_result']);
        $this->assertSame('{"text":"Emergency exit was obstructed."}', $result['content']);
        $this->assertSame(['request-1'], $result['provider_request_ids']);
        $this->assertSame('structured', $result['verification']['status']);
        $this->assertSame('deterministic_record_tokens', $result['verification']['critical_fact_validation']['status']);
        $this->assertNull($result['verification']['grounding_verification']['valid']);
    }

    public function test_it_normalizes_summary_and_review_task_payloads(): void
    {
        $responses = [
            ['data' => ['summary' => "  Pump isolated.\nCrew returned safely. "]],
            ['data' => ['items' => [
                ['status' => 'missing_information', 'message' => '  Add the RTB time if available. '],
                ['status' => 'looks_ok', 'message' => ' Chronology is ordered. '],
            ]]],
        ];
        $this->mock(AiHelperOpenAiService::class, function ($mock) use (&$responses) {
            $mock->shouldReceive('structuredResponse')->twice()->andReturnUsing(
                function () use (&$responses) {
                    return array_shift($responses);
                },
            );
        });
        $service = app(AiHelperEmbeddedTaskService::class);
        $deadline = AiHelperRequestDeadline::fromSeconds(20);

        $summary = $service->execute(
            AiHelperEmbeddedTaskService::ERCO_GENERATE_SUMMARY,
            '{}',
            'en',
            $deadline,
            'vmecc-user-8',
        );
        $review = $service->execute(
            AiHelperEmbeddedTaskService::ERCO_REVIEW_REPORT,
            '{}',
            'en',
            $deadline,
            'vmecc-user-8',
        );

        $this->assertSame('Pump isolated. Crew returned safely.', $summary['content']);
        $this->assertSame('missing_information', $review['embedded_result']['items'][0]['status']);
        $this->assertSame('Add the RTB time if available.', $review['embedded_result']['items'][0]['message']);
        $this->assertJson($review['content']);
    }

    public function test_it_rejects_an_invalid_normalized_review_instead_of_guessing(): void
    {
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('structuredResponse')->once()->andReturn([
                'data' => ['items' => [['status' => 'maybe', 'message' => 'Check it.']]],
            ]);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('review response was invalid');

        app(AiHelperEmbeddedTaskService::class)->execute(
            AiHelperEmbeddedTaskService::ERCO_REVIEW_REPORT,
            '{}',
            'en',
            AiHelperRequestDeadline::fromSeconds(20),
            'vmecc-user-9',
        );
    }

    public function test_it_rejects_an_empty_translation(): void
    {
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('structuredResponse')->once()->andReturn([
                'data' => ['text' => '   '],
            ]);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('translation response was empty');

        app(AiHelperEmbeddedTaskService::class)->execute(
            AiHelperEmbeddedTaskService::INSPECTION_TRANSLATE_FINDING,
            '{"sourceText":"text"}',
            'en',
            AiHelperRequestDeadline::fromSeconds(20),
            'vmecc-user-10',
        );
    }

    public function test_it_preserves_source_tokens_without_requiring_numeric_legacy_context(): void
    {
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('structuredResponse')->once()->andReturn([
                'data' => ['text' => 'The emergency exit was obstructed.'],
            ]);
        });

        $legacyRequest = <<<'TEXT'
Translate and polish this General/HSE inspection finding description into concise professional English.

Field payload:
{
  "inspectionType": "General Inspection",
  "zone": "1",
  "mainLocation": "Manjung Hub",
  "subLocation": "Reception",
  "field": "description",
  "sourceText": "laluan emergency exit terhalang"
}
TEXT;

        $result = app(AiHelperEmbeddedTaskService::class)->execute(
            AiHelperEmbeddedTaskService::INSPECTION_TRANSLATE_FINDING,
            $legacyRequest,
            'en',
            AiHelperRequestDeadline::fromSeconds(20),
            'vmecc-user-legacy',
        );

        $this->assertSame(
            ['text' => 'The emergency exit was obstructed.'],
            $result['embedded_result'],
        );
    }

    public function test_it_rejects_a_critical_number_invented_by_the_provider(): void
    {
        $this->mock(AiHelperOpenAiService::class, function ($mock) {
            $mock->shouldReceive('structuredResponse')->once()->andReturn([
                'data' => ['summary' => 'Three responders arrived at 14:30.'],
            ]);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('introduced a number');

        app(AiHelperEmbeddedTaskService::class)->execute(
            AiHelperEmbeddedTaskService::ERCO_GENERATE_SUMMARY,
            '{"responders":[],"time":""}',
            'en',
            AiHelperRequestDeadline::fromSeconds(20),
            'vmecc-user-11',
        );
    }
}
