<?php

namespace Tests\Feature;

use App\Exceptions\ReportImageException;
use App\Models\Report;
use App\Models\ReportMedia;
use App\Models\ReportMediaLink;
use App\Models\User;
use App\Services\ReportMediaLeaseService;
use App\Services\ReportMediaService;
use App\Services\ReportMediaStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
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
        $this->grantPermission($user, 'reports.inspection.view');
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
        $this->assertNotEmpty($response->json('data.lease_id'));
        $this->assertDatabaseHas('report_media_leases', [
            'report_media_id' => $media->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_mobile_camera_upload_between_old_and_new_source_limits_is_accepted(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.inspection.view');

        $this->actingAs($user)->post('/api/report-media', [
            'file' => UploadedFile::fake()->image('large-camera.jpg', 1600, 900)->size(20 * 1024),
            'module' => 'inspection',
            'source' => 'camera',
            'upload_id' => (string) Str::uuid(),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.mime_type', 'image/jpeg');
    }

    public function test_five_image_batch_creates_five_verified_media_records(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.inspection.view');
        $batchId = (string) Str::uuid();

        for ($index = 1; $index <= 5; $index++) {
            $this->actingAs($user)->post('/api/report-media', [
                'file' => UploadedFile::fake()->image("photo-{$index}.jpg", 640, 480),
                'module' => 'inspection',
                'source' => 'upload',
                'batch_id' => $batchId,
                'upload_id' => (string) Str::uuid(),
            ], ['Accept' => 'application/json'])
                ->assertCreated()
                ->assertJsonPath('data.file_name', "photo-{$index}.jpg");
        }

        $this->assertDatabaseCount('report_media', 5);
        ReportMedia::query()->each(function (ReportMedia $media): void {
            Storage::disk($media->disk)->assertExists($media->storage_path);
            Storage::disk($media->disk)->assertExists($media->thumbnail_path);
        });
    }

    public function test_failed_verified_storage_creates_no_media_record(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.inspection.view');
        $batchId = (string) Str::uuid();

        $this->mock(ReportMediaStorageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('storeVerifiedPair')
                ->once()
                ->andThrow(new ReportImageException(
                    'storage_verification_failed',
                    'The saved photo could not be verified. Try again.',
                    507,
                ));
            $mock->shouldReceive('deletePair')->once();
        });

        $this->actingAs($user)->post('/api/report-media', [
            'file' => UploadedFile::fake()->image('camera.jpg', 1600, 900),
            'module' => 'inspection',
            'source' => 'upload',
            'batch_id' => $batchId,
            'upload_id' => (string) Str::uuid(),
        ], ['Accept' => 'application/json'])
            ->assertStatus(507)
            ->assertJsonPath('code', 'storage_verification_failed');

        $this->assertDatabaseCount('report_media', 0);
    }

    public function test_source_upload_above_hard_limit_is_rejected_before_processing(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)->post('/api/report-media', [
            'file' => UploadedFile::fake()->image('oversized-camera.jpg')->size(31 * 1024),
            'module' => 'inspection',
            'source' => 'camera',
            'upload_id' => (string) Str::uuid(),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
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
        $this->grantPermission($user, 'reports.inspection.view');
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
        $this->grantPermission($user, 'reports.inspection.view');
        $media = $this->createMedia($user);
        $media->update(['size_bytes' => 33 * 1024 * 1024]);
        config(['report_media.temporary_user_quota_bytes' => 32 * 1024 * 1024]);

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

    public function test_active_lease_protects_unlinked_media_beyond_prune_threshold(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $media = $this->createMedia($owner);
        $media->forceFill(['created_at' => now()->subHours(48)])->save();
        app(ReportMediaLeaseService::class)->createOrRenewForUpload($media, $owner->id, 'pending-operation');

        $this->assertSame(0, app(ReportMediaService::class)->pruneUnlinked(24));
        $this->assertDatabaseHas('report_media', ['id' => $media->id]);
        Storage::disk('local')->assertExists($media->storage_path);
    }

    public function test_expired_lease_allows_unlinked_media_to_be_pruned(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $media = $this->createMedia($owner);
        $media->forceFill(['created_at' => now()->subHours(48)])->save();
        $lease = app(ReportMediaLeaseService::class)
            ->createOrRenewForUpload($media, $owner->id, 'abandoned-operation');
        $lease->forceFill([
            'expires_at' => now()->subMinute(),
            'absolute_expires_at' => now()->addDay(),
        ])->save();

        $this->assertSame(1, app(ReportMediaService::class)->pruneUnlinked(24));
        $this->assertDatabaseMissing('report_media', ['id' => $media->id]);
        Storage::disk('local')->assertMissing($media->storage_path);
    }

    public function test_durable_link_releases_lease_and_protects_media_from_deletion(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $media = $this->createMedia($owner);
        app(ReportMediaLeaseService::class)->createOrRenewForUpload($media, $owner->id, 'draft-1');
        DB::transaction(function () use ($media, $owner): void {
            app(ReportMediaService::class)->syncPayloadLinks([
                'photos' => [[
                    'mediaId' => $media->public_id,
                    'url' => '/api/report-media/'.$media->public_id,
                ]],
            ], 'report_draft', 'draft-1', $owner->id, 'inspection');
        });

        $this->assertDatabaseMissing('report_media_leases', ['report_media_id' => $media->id]);
        $this->actingAs($owner)
            ->deleteJson('/api/report-media/'.$media->public_id)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'media_protected');
        $this->assertDatabaseHas('report_media', ['id' => $media->id]);
    }

    public function test_only_lease_owner_can_renew_an_unlinked_media_lease(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $intruder = User::factory()->create(['status' => 'active']);
        $media = $this->createMedia($owner);
        $lease = app(ReportMediaLeaseService::class)
            ->createOrRenewForUpload($media, $owner->id, 'pending-operation');

        $this->actingAs($intruder)->postJson(
            '/api/report-media/'.$media->public_id.'/lease/renew',
            ['lease_id' => $lease->lease_uid],
        )->assertUnprocessable();

        $this->actingAs($owner)->postJson(
            '/api/report-media/'.$media->public_id.'/lease/renew',
            ['lease_id' => $lease->lease_uid, 'context_key' => 'operation-2'],
        )->assertOk()->assertJsonPath('data.lease_id', $lease->lease_uid);
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
