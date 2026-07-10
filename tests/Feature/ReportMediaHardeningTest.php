<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\ReportMedia;
use App\Models\ReportMediaLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportMediaHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['cache.default' => 'array', 'report_media.minimum_disk_free_bytes' => 0, 'report_media.temporary_user_quota_bytes' => 128 * 1024 * 1024]);
    }

    public function test_upload_creates_private_thumbnail_and_integrity_metadata(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);
        $response = $this->post('/api/report-media', [
            'file' => UploadedFile::fake()->image('camera.jpg', 1600, 900),
            'module' => 'inspection',
            'source' => 'camera',
            'upload_id' => (string) Str::uuid(),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('data.mime_type', 'image/jpeg');
        $this->assertNotEmpty($response->json('data.thumbnail_url'));
        $media = ReportMedia::query()->firstOrFail();
        Storage::disk('local')->assertExists($media->storage_path);
        Storage::disk('local')->assertExists($media->thumbnail_path);
        $this->assertLessThanOrEqual(480, max($media->thumbnail_width, $media->thumbnail_height));
        $this->assertSame(64, strlen((string) $media->checksum_sha256));
    }

    public function test_linked_media_requires_parent_report_access(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $intruder = User::factory()->create(['status' => 'active']);
        $reviewer = User::factory()->create(['status' => 'active']);
        $this->grantPermission($reviewer, 'reports.inspection.view');
        $media = $this->createMedia($owner);
        Report::query()->create(['report_uid' => 'inspection-media-auth', 'display_id' => 'INS-MEDIA-AUTH', 'owner_user_id' => $owner->id, 'report_type' => 'inspection', 'status' => 'Submitted', 'version' => 1, 'revision' => 1, 'payload' => []]);
        ReportMediaLink::query()->create(['report_media_id' => $media->id, 'parent_type' => 'report', 'parent_key' => 'inspection-media-auth']);

        $this->actingAs($intruder)->get('/api/report-media/'.$media->public_id)->assertNotFound();
        $this->actingAs($reviewer)->get('/api/report-media/'.$media->public_id.'?variant=thumbnail')->assertOk();
    }

    public function test_processing_lock_rejects_concurrent_upload_for_same_user(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);
        $lock = Cache::lock('photo-upload-processing:user:'.$user->id, 120);
        $this->assertTrue($lock->get());
        try {
            $this->post('/api/report-media', ['file' => UploadedFile::fake()->image('camera.jpg'), 'module' => 'inspection', 'source' => 'camera', 'upload_id' => (string) Str::uuid()], ['Accept' => 'application/json'])
                ->assertStatus(429)
                ->assertJsonPath('code', 'upload_busy');
        } finally {
            $lock->release();
        }
    }

    public function test_temporary_storage_quota_rejects_more_uploads(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $media = $this->createMedia($user);
        $media->update(['size_bytes' => 17 * 1024 * 1024]);
        config(['report_media.temporary_user_quota_bytes' => 16 * 1024 * 1024]);

        $this->actingAs($user)->post('/api/report-media', [
            'file' => UploadedFile::fake()->image('camera.jpg'),
            'module' => 'inspection',
            'source' => 'camera',
            'upload_id' => (string) Str::uuid(),
        ], ['Accept' => 'application/json'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'storage_quota_exceeded');
    }

    public function test_owner_delete_removes_full_image_and_thumbnail(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $media = $this->createMedia($owner);

        $this->actingAs($owner)->deleteJson('/api/report-media/'.$media->public_id)->assertNoContent();

        Storage::disk('local')->assertMissing('report-media/full.jpg');
        Storage::disk('local')->assertMissing('report-media/thumb.jpg');
        $this->assertDatabaseMissing('report_media', ['id' => $media->id]);
    }

    private function createMedia(User $owner): ReportMedia
    {
        Storage::disk('local')->put('report-media/full.jpg', 'full-image');
        Storage::disk('local')->put('report-media/thumb.jpg', 'thumb-image');

        return ReportMedia::query()->create(['public_id' => 'rpm_authorization_test', 'user_id' => $owner->id, 'module' => 'inspection', 'disk' => 'local', 'storage_path' => 'report-media/full.jpg', 'thumbnail_path' => 'report-media/thumb.jpg', 'original_name' => 'camera.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10, 'thumbnail_size_bytes' => 10, 'width' => 100, 'height' => 100, 'thumbnail_width' => 50, 'thumbnail_height' => 50]);
    }

    private function grantPermission(User $user, string $permissionName): void
    {
        $permission = Permission::query()->firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
        $role = Role::query()->firstOrCreate(['name' => 'Report media reviewer', 'guard_name' => 'web']);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);
    }
}
