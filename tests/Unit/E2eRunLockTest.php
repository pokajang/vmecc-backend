<?php

namespace Tests\Unit;

use App\Support\E2eRunLock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class E2eRunLockTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'vmecc-e2e-lock-'.bin2hex(random_bytes(6));
        mkdir($this->root);
        mkdir($this->root.DIRECTORY_SEPARATOR.'locks');
        mkdir($this->root.DIRECTORY_SEPARATOR.'run');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.DIRECTORY_SEPARATOR.'locks'.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->root.DIRECTORY_SEPARATOR.'locks');
        rmdir($this->root.DIRECTORY_SEPARATOR.'run');
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_it_acquires_heartbeats_and_releases_an_owned_lock(): void
    {
        $lock = $this->lock();
        $handle = $lock->acquire();

        $metadata = $lock->assertOwned();
        $this->assertSame('VMECC-QA-20260720-120000-abc123', $metadata['run_id']);
        $this->assertSame('vmecc_test', $metadata['database']);

        $lock->heartbeat($handle);
        $lock->release($handle);

        $this->assertTrue($lock->isReleased());
    }

    public function test_it_rejects_a_second_writer(): void
    {
        $first = $this->lock();
        $handle = $first->acquire();

        try {
            $this->expectException(RuntimeException::class);
            $this->lock('VMECC-QA-20260720-120001-def456')->acquire();
        } finally {
            $first->release($handle);
        }
    }

    public function test_it_rejects_invalid_run_identity(): void
    {
        $this->expectException(RuntimeException::class);
        $this->lock('unsafe')->acquire();
    }

    public function test_it_supports_graceful_stop_requests(): void
    {
        $lock = $this->lock();
        $handle = $lock->acquire();

        $lock->requestStop();
        $this->assertTrue($lock->stopRequested());

        $lock->release($handle);
    }

    private function lock(string $runId = 'VMECC-QA-20260720-120000-abc123'): E2eRunLock
    {
        return new E2eRunLock(
            $this->root.DIRECTORY_SEPARATOR.'locks',
            $runId,
            'vmecc_test',
            $this->root.DIRECTORY_SEPARATOR.'run',
        );
    }
}
