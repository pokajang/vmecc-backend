<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\AiHelperDocumentQuotaService;
use App\Services\AiHelperKnowledgeQuotaService;
use App\Services\AiHelperStorageCapacityService;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

class AiHelperStorageCapacityServiceTest extends TestCase
{
    public function test_selected_upload_type_is_checked_without_cross_blocking_on_the_other_quota(): void
    {
        $service = new AiHelperStorageCapacityService;
        $filesystem = ['available' => true, 'free_bytes' => 500, 'total_bytes' => 1000];
        $uploads = [
            AiHelperStorageCapacityService::UPLOAD_DOCUMENTS => ['used_bytes' => 100, 'limit_bytes' => 1000],
            AiHelperStorageCapacityService::UPLOAD_KNOWLEDGE => ['used_bytes' => 900, 'limit_bytes' => 1000],
        ];
        $thresholds = [
            'minimum_free_percent' => 20.0,
            'minimum_free_bytes' => 200,
            'maximum_upload_percent' => 85.0,
        ];

        $documentUpload = $service->assess(
            $filesystem,
            $uploads,
            $thresholds,
            AiHelperStorageCapacityService::UPLOAD_DOCUMENTS,
            100,
        );
        $overallHealth = $service->assess($filesystem, $uploads, $thresholds);

        $this->assertTrue($documentUpload['ready']);
        $this->assertSame(400, $documentUpload['filesystem']['projected_free_bytes']);
        $this->assertSame(20.0, $documentUpload['uploads']['documents']['projected_used_percent']);
        $this->assertFalse($overallHealth['ready']);
        $this->assertSame('AI_HELPER_KNOWLEDGE_GLOBAL_STORAGE_LIMIT', $overallHealth['error']);
    }

    public function test_projected_filesystem_headroom_is_enforced_before_persistent_storage(): void
    {
        $result = (new AiHelperStorageCapacityService)->assess(
            ['available' => true, 'free_bytes' => 250, 'total_bytes' => 1000],
            $this->emptyUploadUsage(),
            [
                'minimum_free_percent' => 20.0,
                'minimum_free_bytes' => 200,
                'maximum_upload_percent' => 85.0,
            ],
            AiHelperStorageCapacityService::UPLOAD_DOCUMENTS,
            100,
        );

        $this->assertFalse($result['ready']);
        $this->assertSame('AI_HELPER_STORAGE_HEADROOM_LIMIT', $result['error']);
        $this->assertSame(150, $result['filesystem']['projected_free_bytes']);
        $this->assertSame(15.0, $result['filesystem']['projected_free_percent']);
    }

    public function test_projected_configured_upload_usage_is_enforced_at_the_safety_boundary(): void
    {
        $uploads = $this->emptyUploadUsage();
        $uploads[AiHelperStorageCapacityService::UPLOAD_DOCUMENTS] = [
            'used_bytes' => 800,
            'limit_bytes' => 1000,
        ];
        $result = (new AiHelperStorageCapacityService)->assess(
            ['available' => true, 'free_bytes' => 900, 'total_bytes' => 1000],
            $uploads,
            [
                'minimum_free_percent' => 0.0,
                'minimum_free_bytes' => 0,
                'maximum_upload_percent' => 85.0,
            ],
            AiHelperStorageCapacityService::UPLOAD_DOCUMENTS,
            50,
        );

        $this->assertFalse($result['ready']);
        $this->assertSame('AI_HELPER_DOCUMENT_GLOBAL_STORAGE_LIMIT', $result['error']);
        $this->assertSame(85.0, $result['uploads']['documents']['projected_used_percent']);
    }

    public function test_capacity_check_fails_closed_when_capacity_is_unknown_or_incoming_bytes_exceed_free_space(): void
    {
        $service = new AiHelperStorageCapacityService;
        $thresholds = [
            'minimum_free_percent' => 0.0,
            'minimum_free_bytes' => 0,
            'maximum_upload_percent' => 100.0,
        ];

        $unknown = $service->assess(
            ['available' => false, 'error' => 'AI_HELPER_STORAGE_PATH_UNAVAILABLE'],
            $this->emptyUploadUsage(),
            $thresholds,
        );
        $oversized = $service->assess(
            ['available' => true, 'free_bytes' => 99, 'total_bytes' => 1000],
            $this->emptyUploadUsage(),
            $thresholds,
            AiHelperStorageCapacityService::UPLOAD_KNOWLEDGE,
            100,
        );

        $this->assertFalse($unknown['ready']);
        $this->assertSame('AI_HELPER_STORAGE_PATH_UNAVAILABLE', $unknown['error']);
        $this->assertFalse($oversized['ready']);
        $this->assertSame('AI_HELPER_STORAGE_HEADROOM_LIMIT', $oversized['error']);
    }

    public function test_document_and_knowledge_quota_services_enforce_the_shared_capacity_gate(): void
    {
        config([
            'ai_helper.document_max_active_uploads_per_user' => 0,
            'ai_helper.document_max_upload_bytes_per_user' => 0,
            'ai_helper.knowledge_max_active_uploads_per_user' => 0,
            'ai_helper.knowledge_max_upload_bytes_per_user' => 0,
        ]);
        $user = new User;
        $user->forceFill(['id' => 123]);
        $document = UploadedFile::fake()->create('guide.pdf', 10, 'application/pdf');
        $knowledge = UploadedFile::fake()->create('guide.md', 2, 'text/markdown');
        $capacity = Mockery::mock(AiHelperStorageCapacityService::class);
        $capacity->shouldReceive('checkUpload')
            ->once()
            ->with(AiHelperStorageCapacityService::UPLOAD_DOCUMENTS, (int) $document->getSize())
            ->andReturn([
                'ok' => false,
                'code' => 'AI_HELPER_STORAGE_HEADROOM_LIMIT',
                'status' => [],
            ]);
        $capacity->shouldReceive('checkUpload')
            ->once()
            ->with(AiHelperStorageCapacityService::UPLOAD_KNOWLEDGE, (int) $knowledge->getSize())
            ->andReturn([
                'ok' => false,
                'code' => 'AI_HELPER_KNOWLEDGE_GLOBAL_STORAGE_LIMIT',
                'status' => [],
            ]);

        $documentResult = (new AiHelperDocumentQuotaService($capacity))->checkUpload($user, $document);
        $knowledgeResult = (new AiHelperKnowledgeQuotaService($capacity))->checkUpload($user, $knowledge);

        $this->assertFalse($documentResult['ok']);
        $this->assertSame('AI_HELPER_STORAGE_HEADROOM_LIMIT', $documentResult['code']);
        $this->assertFalse($knowledgeResult['ok']);
        $this->assertSame('AI_HELPER_KNOWLEDGE_GLOBAL_STORAGE_LIMIT', $knowledgeResult['code']);
    }

    public function test_storage_health_command_uses_the_shared_capacity_assessment(): void
    {
        $this->mock(AiHelperStorageCapacityService::class, function ($mock): void {
            $mock->shouldReceive('status')
                ->once()
                ->with([
                    'minimum_free_percent' => 12.0,
                    'minimum_free_bytes' => 256 * 1024 * 1024,
                    'maximum_upload_percent' => 80.0,
                ])
                ->andReturn([
                    'ready' => true,
                    'filesystem' => [
                        'free_bytes' => 1024,
                        'projected_free_bytes' => 1024,
                        'total_bytes' => 2048,
                        'free_percent' => 50.0,
                        'projected_free_percent' => 50.0,
                        'minimum_free_bytes' => 256 * 1024 * 1024,
                        'minimum_free_percent' => 12.0,
                        'ready' => true,
                    ],
                    'uploads' => [
                        'maximum_used_percent' => 80.0,
                        'documents' => ['used_bytes' => 0, 'used_percent' => 0.0],
                        'knowledge' => ['used_bytes' => 0, 'used_percent' => 0.0],
                    ],
                ]);
        });

        $this->artisan('ai-helper:storage-health
                --json
                --minimum-free-percent=12
                --minimum-free-mb=256
                --maximum-upload-percent=80')
            ->expectsOutputToContain('"ready": true')
            ->assertSuccessful();
    }

    /** @return array<string, array{used_bytes: int, limit_bytes: int}> */
    private function emptyUploadUsage(): array
    {
        return [
            AiHelperStorageCapacityService::UPLOAD_DOCUMENTS => ['used_bytes' => 0, 'limit_bytes' => 0],
            AiHelperStorageCapacityService::UPLOAD_KNOWLEDGE => ['used_bytes' => 0, 'limit_bytes' => 0],
        ];
    }
}
