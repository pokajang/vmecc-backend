<?php

namespace Tests\Unit;

use App\Services\AiHelperSystemGuideApprovalManifest;
use RuntimeException;
use Tests\TestCase;

class AiHelperSystemGuideApprovalManifestTest extends TestCase
{
    public function test_approval_is_bound_to_key_version_owner_and_normalized_content_hash(): void
    {
        $content = "# Guide\r\n\r\nFinal content.   \r\n";
        $manifest = $this->manifestWith([[
            'key' => 'example-guide',
            'version' => 3,
            'content_sha256' => hash('sha256', "# Guide\n\nFinal content."),
            'owner' => 'Operations',
            'approval_reference' => 'CHANGE-123',
            'approved_by' => 'Operations Owner',
            'approved_on' => now()->toDateString(),
        ]]);

        $record = $manifest->validateApprovedGuide([
            'key' => 'example-guide',
            'version' => 3,
            'owner' => 'Operations',
        ], $content, 'example.md');

        $this->assertSame('CHANGE-123', $record['approval_reference']);
    }

    public function test_changed_content_invalidates_approval(): void
    {
        $manifest = $this->manifestWith([[
            'key' => 'example-guide',
            'version' => 3,
            'content_sha256' => hash('sha256', 'Approved content.'),
            'owner' => 'Operations',
            'approval_reference' => 'CHANGE-123',
            'approved_by' => 'Operations Owner',
            'approved_on' => now()->toDateString(),
        ]]);

        $this->expectException(RuntimeException::class);
        $manifest->validateApprovedGuide([
            'key' => 'example-guide',
            'version' => 3,
            'owner' => 'Operations',
        ], 'Changed content.', 'example.md');
    }

    private function manifestWith(array $records): AiHelperSystemGuideApprovalManifest
    {
        $path = tempnam(sys_get_temp_dir(), 'guide-approvals-');
        file_put_contents($path, json_encode($records, JSON_THROW_ON_ERROR));
        $this->beforeApplicationDestroyed(static fn () => @unlink($path));

        return new class($path) extends AiHelperSystemGuideApprovalManifest
        {
            public function __construct(private readonly string $manifestPath) {}

            public function path(): string
            {
                return $this->manifestPath;
            }
        };
    }
}
