<?php

namespace Tests\Unit;

use App\Services\AiHelperEmbeddingService;
use Tests\TestCase;

class AiHelperConfigurationTest extends TestCase
{
    public function test_only_the_approved_ai_environment_variables_are_read(): void
    {
        $source = (string) file_get_contents(config_path('ai_helper.php'));
        preg_match_all('/env\([\'\"]([A-Z0-9_]+)[\'\"]/', $source, $matches);
        $actual = array_values(array_unique($matches[1] ?? []));
        sort($actual);
        $expected = [
            'AI_HELPER_EMBEDDING_MODEL',
            'AI_HELPER_ENABLED',
            'OPENAI_API_KEY',
            'OPENAI_HELPER_MODEL',
        ];
        sort($expected);

        $this->assertSame($expected, $actual);
    }

    public function test_environment_templates_expose_only_the_approved_ai_variables(): void
    {
        $expected = [
            'AI_HELPER_EMBEDDING_MODEL',
            'AI_HELPER_ENABLED',
            'OPENAI_API_KEY',
            'OPENAI_HELPER_MODEL',
        ];
        sort($expected);

        foreach (['.env.example', '.env.production.example'] as $filename) {
            $source = (string) file_get_contents(base_path($filename));
            preg_match_all('/^(AI_HELPER_[A-Z0-9_]+|OPENAI_[A-Z0-9_]+)=/m', $source, $matches);
            $actual = array_values(array_unique($matches[1] ?? []));
            sort($actual);

            $this->assertSame($expected, $actual, "Unexpected AI environment boundary in {$filename}.");
        }
    }

    public function test_application_code_does_not_read_ai_environment_variables_directly(): void
    {
        $violations = [];
        foreach (['app', 'bootstrap', 'routes', 'database'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                if (preg_match('/env\([\'\"](?:AI_HELPER_|OPENAI_)/', $source) === 1) {
                    $violations[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_no_other_configuration_file_reads_the_ai_environment_boundary(): void
    {
        $violations = [];
        foreach (glob(config_path('*.php')) ?: [] as $path) {
            if (realpath($path) === realpath(config_path('ai_helper.php'))) {
                continue;
            }
            $source = (string) file_get_contents($path);
            if (preg_match('/env\([\'\"](?:AI_HELPER_|OPENAI_)/', $source) === 1) {
                $violations[] = $path;
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_release_policy_is_code_controlled_and_has_no_stage_model_drift(): void
    {
        $this->assertSame('https://api.openai.com/v1', config('ai_helper.base_url'));
        $this->assertSame(4, config('ai_helper.pipeline_version'));
        $this->assertTrue(config('ai_helper.product_workflows_enabled'));
        $this->assertTrue(config('ai_helper.rerank_enabled'));
        $this->assertSame('enforce', config('ai_helper.grounding_verification_mode'));
        $this->assertFalse(config()->has('ai_helper.rerank_model'));
        $this->assertFalse(config()->has('ai_helper.verifier_model'));
        $this->assertFalse(config()->has('ai_helper.knowledge_global_candidate_limit'));
        $this->assertFalse(config()->has('ai_helper.global_fallback_enabled'));
    }

    public function test_default_semantic_fingerprint_remains_compatible_with_the_current_index(): void
    {
        config([
            'ai_helper.index_profile_version' => 4,
            'ai_helper.embedding_model' => 'text-embedding-3-small',
            'ai_helper.embedding_dimensions' => 512,
            'ai_helper.embedding_routing_profile_version' => 'routing-v1',
            'ai_helper.embedding_chunk_profile_version' => 'contextual-v2',
        ]);

        $this->assertSame(
            'v4:text-embedding-3-small:512:routing-v1:contextual-v2',
            app(AiHelperEmbeddingService::class)->indexFingerprint(),
        );
    }
}
