<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\ReportDraft;
use App\Models\ReportMedia;
use App\Models\ReportMediaLink;
use App\Models\User;
use App\Services\ReportMediaModulePolicy;
use App\Services\ReportMediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DrillReportMediaLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'cache.default' => 'array',
            'report_media.minimum_disk_free_bytes' => 0,
            'report_media.temporary_user_quota_bytes' => 128 * 1024 * 1024,
            'report_media.modules.drill.upload_enabled' => true,
        ]);
    }

    public function test_drill_media_survives_draft_deletion_until_removed_from_the_final_report(): void
    {
        $owner = $this->userWithPermission('reports.drill.view');
        $mediaId = $this->upload($owner, 'drill');
        $media = ReportMedia::query()->where('public_id', $mediaId)->firstOrFail();

        $draft = $this->actingAs($owner)->postJson('/api/reports/drafts', [
            'report_type' => 'drill',
            'payload' => $this->payloadWithPhoto($mediaId),
        ])->assertCreated();
        $draftId = (string) $draft->json('data.draft_id');

        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report_draft',
            'parent_key' => $draftId,
        ]);
        $this->assertDatabaseMissing('report_media_leases', ['report_media_id' => $media->id]);

        $created = $this->postJson('/api/reports', [
            'display_id' => 'DRL-MEDIA-001',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => $this->payloadWithPhoto($mediaId),
        ])->assertCreated()
            ->assertJsonPath(
                'data.postIncidentAnalysis.photos.0.description',
                'Initial response position'
            );
        $reportUid = (string) $created->json('data.id');

        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report',
            'parent_key' => $reportUid,
        ]);

        $this->deleteJson('/api/reports/drafts/'.$draftId)->assertOk();
        $this->assertDatabaseMissing('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report_draft',
            'parent_key' => $draftId,
        ]);
        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report',
            'parent_key' => $reportUid,
        ]);

        $reviewer = $this->userWithPermission('reports.drill.view');
        $this->actingAs($reviewer)
            ->get('/api/report-media/'.$mediaId.'?variant=thumbnail')
            ->assertOk();
        $ercoOnlyUser = $this->userWithPermission('reports.erco.view');
        $this->actingAs($ercoOnlyUser)->get('/api/report-media/'.$mediaId)->assertNotFound();

        $this->actingAs($owner)->putJson('/api/reports/'.$reportUid, [
            'version' => 1,
            'status' => 'Submitted',
            'payload' => $this->payloadWithPhoto(null),
        ])->assertOk();
        $this->assertDatabaseMissing('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report',
            'parent_key' => $reportUid,
        ]);

        $media->forceFill(['created_at' => now()->subHours(48)])->save();
        $this->assertSame(1, app(ReportMediaService::class)->pruneUnlinked(24));
        $this->assertDatabaseMissing('report_media', ['id' => $media->id]);
        Storage::disk('local')->assertMissing($media->storage_path);
        Storage::disk('local')->assertMissing($media->thumbnail_path);
    }

    public function test_cross_module_upload_id_replay_is_rejected_without_exposing_media(): void
    {
        $user = $this->userWithPermission('reports.inspection.view');
        $this->grantPermission($user, 'reports.drill.view');
        $uploadId = (string) Str::uuid();

        $this->upload($user, 'inspection', $uploadId);

        $this->actingAs($user)->post('/api/report-media', [
            'file' => UploadedFile::fake()->image('drill-camera.jpg', 800, 600),
            'module' => 'drill',
            'source' => 'camera',
            'upload_id' => $uploadId,
        ], ['Accept' => 'application/json'])
            ->assertConflict()
            ->assertJsonPath('code', 'upload_id_module_conflict')
            ->assertJsonMissingPath('data');

        $this->assertDatabaseCount('report_media', 1);
        $this->assertDatabaseHas('report_media', [
            'user_id' => $user->id,
            'module' => 'inspection',
        ]);
    }

    public function test_same_module_upload_id_replay_returns_the_original_drill_media(): void
    {
        $user = $this->userWithPermission('reports.drill.view');
        $uploadId = (string) Str::uuid();
        $mediaId = $this->upload($user, 'drill', $uploadId);

        $this->actingAs($user)->post('/api/report-media', [
            'file' => UploadedFile::fake()->image('replayed-camera.jpg', 320, 240),
            'module' => 'drill',
            'source' => 'camera',
            'upload_id' => $uploadId,
            'context_key' => 'replayed-operation',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.media_id', $mediaId)
            ->assertJsonPath('data.idempotent_replay', true);

        $this->assertDatabaseCount('report_media', 1);
        $this->assertDatabaseHas('report_media_leases', [
            'report_media_id' => ReportMedia::query()->where('public_id', $mediaId)->value('id'),
            'context_key' => 'replayed-operation',
        ]);
    }

    public function test_drill_upload_requires_the_drill_report_permission(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)->post('/api/report-media', [
            'file' => UploadedFile::fake()->image('camera.jpg', 800, 600),
            'module' => 'drill',
            'source' => 'camera',
            'upload_id' => (string) Str::uuid(),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->assertDatabaseCount('report_media', 0);
    }

    public function test_drill_upload_can_be_disabled_without_disabling_existing_module_support(): void
    {
        $user = $this->userWithPermission('reports.drill.view');
        config(['report_media.modules.drill.upload_enabled' => false]);

        $this->actingAs($user)->post('/api/report-media', [
            'file' => UploadedFile::fake()->image('camera.jpg', 800, 600),
            'module' => 'drill',
            'source' => 'camera',
            'upload_id' => (string) Str::uuid(),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['module']);

        $this->assertTrue(app(ReportMediaModulePolicy::class)->isSupported('drill'));
        $this->assertFalse(app(ReportMediaModulePolicy::class)->isUploadEnabled('drill'));
    }

    public function test_wrong_owner_drill_media_rolls_back_report_creation(): void
    {
        $mediaOwner = $this->userWithPermission('reports.drill.view');
        $reportOwner = $this->userWithPermission('reports.drill.view');
        $mediaId = $this->upload($mediaOwner, 'drill');

        $this->actingAs($reportOwner)->postJson('/api/reports', [
            'display_id' => 'DRL-MEDIA-INVALID',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => $this->payloadWithPhoto($mediaId),
        ])->assertUnprocessable()->assertJsonValidationErrors(['photos']);

        $this->assertDatabaseMissing('reports', ['display_id' => 'DRL-MEDIA-INVALID']);
        $this->assertDatabaseCount('report_timeline_entries', 0);
        $this->assertDatabaseMissing('report_media_links', [
            'parent_type' => 'report',
        ]);
    }

    public function test_invalid_media_update_rolls_back_payload_version_timeline_and_links(): void
    {
        $owner = $this->userWithPermission('reports.drill.view');
        $other = $this->userWithPermission('reports.drill.view');
        $originalMediaId = $this->upload($owner, 'drill');
        $otherMediaId = $this->upload($other, 'drill');
        $originalMedia = ReportMedia::query()->where('public_id', $originalMediaId)->firstOrFail();

        $created = $this->actingAs($owner)->postJson('/api/reports', [
            'display_id' => 'DRL-MEDIA-ROLLBACK',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => $this->payloadWithPhoto($originalMediaId),
        ])->assertCreated();
        $reportUid = (string) $created->json('data.id');

        $this->putJson('/api/reports/'.$reportUid, [
            'version' => 1,
            'status' => 'Submitted',
            'payload' => $this->payloadWithPhoto($otherMediaId),
        ])->assertUnprocessable()->assertJsonValidationErrors(['photos']);

        $report = Report::query()->where('report_uid', $reportUid)->firstOrFail();
        $this->assertSame(1, (int) $report->version);
        $this->assertSame(1, (int) $report->revision);
        $this->assertSame($originalMediaId, data_get($report->payload, 'postIncidentAnalysis.photos.0.mediaId'));
        $this->assertSame(1, $report->timelineEntries()->count());
        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $originalMedia->id,
            'parent_type' => 'report',
            'parent_key' => $reportUid,
        ]);
        $this->assertDatabaseMissing('report_media_links', [
            'report_media_id' => ReportMedia::query()->where('public_id', $otherMediaId)->value('id'),
            'parent_type' => 'report',
            'parent_key' => $reportUid,
        ]);
    }

    public function test_stale_drill_draft_update_does_not_reconcile_media_links(): void
    {
        $owner = $this->userWithPermission('reports.drill.view');
        $firstMediaId = $this->upload($owner, 'drill');
        $secondMediaId = $this->upload($owner, 'drill');
        $secondMedia = ReportMedia::query()->where('public_id', $secondMediaId)->firstOrFail();

        $created = $this->actingAs($owner)->postJson('/api/reports/drafts', [
            'report_type' => 'drill',
            'payload' => $this->payloadWithPhoto($firstMediaId),
        ])->assertCreated();
        $draftId = (string) $created->json('data.draft_id');

        $this->putJson('/api/reports/drafts/'.$draftId, [
            'base_version' => 1,
            'payload' => $this->payloadWithPhoto($secondMediaId),
        ])->assertOk()->assertJsonPath('data.version', 2);

        $this->putJson('/api/reports/drafts/'.$draftId, [
            'base_version' => 1,
            'payload' => $this->payloadWithPhoto($firstMediaId),
        ])->assertConflict()->assertJsonPath('code', 'report_draft_version_conflict');

        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $secondMedia->id,
            'parent_type' => 'report_draft',
            'parent_key' => $draftId,
        ]);
        $this->assertDatabaseMissing('report_media_links', [
            'report_media_id' => ReportMedia::query()->where('public_id', $firstMediaId)->value('id'),
            'parent_type' => 'report_draft',
            'parent_key' => $draftId,
        ]);
    }

    public function test_draft_delete_rolls_back_link_removal_when_deletion_fails(): void
    {
        $owner = $this->userWithPermission('reports.drill.view');
        $mediaId = $this->upload($owner, 'drill');
        $media = ReportMedia::query()->where('public_id', $mediaId)->firstOrFail();
        $draft = ReportDraft::query()->create([
            'user_id' => $owner->id,
            'draft_id' => 'drf_delete_rollback',
            'report_type' => 'drill',
            'payload' => $this->payloadWithPhoto($mediaId),
            'saved_at' => now(),
            'version' => 1,
        ]);
        ReportMediaLink::query()->create([
            'report_media_id' => $media->id,
            'parent_type' => 'report_draft',
            'parent_key' => $draft->draft_id,
        ]);

        $this->mock(ReportMediaService::class, function ($mock) use ($draft): void {
            $mock->shouldReceive('removeParentLinks')
                ->once()
                ->andReturnUsing(function () use ($draft): void {
                    ReportMediaLink::query()
                        ->where('parent_type', 'report_draft')
                        ->where('parent_key', $draft->draft_id)
                        ->delete();
                    throw new RuntimeException('Injected draft deletion failure.');
                });
        });

        $this->actingAs($owner)
            ->deleteJson('/api/reports/drafts/'.$draft->draft_id)
            ->assertStatus(500);

        $this->assertDatabaseHas('report_drafts', ['id' => $draft->id]);
        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report_draft',
            'parent_key' => $draft->draft_id,
        ]);
    }

    public function test_erco_final_submission_receives_a_durable_report_link(): void
    {
        $owner = $this->userWithPermission('reports.erco.view');
        $mediaId = $this->upload($owner, 'erco');
        $media = ReportMedia::query()->where('public_id', $mediaId)->firstOrFail();

        $created = $this->actingAs($owner)->postJson('/api/reports', [
            'display_id' => 'ERCO-MEDIA-001',
            'report_type' => 'erco',
            'status' => 'Submitted',
            'payload' => $this->ercoPayloadWithPhoto($mediaId),
        ])->assertCreated();

        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report',
            'parent_key' => (string) $created->json('data.id'),
        ]);
    }

    public function test_wrong_module_and_oversized_drill_media_are_rejected_before_commit(): void
    {
        $owner = $this->userWithPermission('reports.drill.view');
        $this->grantPermission($owner, 'reports.erco.view');
        $ercoMediaId = $this->upload($owner, 'erco');

        $this->actingAs($owner)->postJson('/api/reports', [
            'display_id' => 'DRL-WRONG-MODULE',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => $this->payloadWithPhoto($ercoMediaId),
        ])->assertUnprocessable()->assertJsonValidationErrors(['photos']);

        $first = $this->createSizedMedia($owner, 'rpm_drill_large_1', 7 * 1024 * 1024);
        $second = $this->createSizedMedia($owner, 'rpm_drill_large_2', 7 * 1024 * 1024);
        $payload = $this->payloadWithPhoto($first->public_id);
        $payload['postIncidentAnalysis']['photos'][] = [
            'mediaId' => $second->public_id,
            'url' => '/api/report-media/'.$second->public_id,
        ];

        $this->postJson('/api/reports', [
            'display_id' => 'DRL-OVERSIZED-MEDIA',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertUnprocessable()->assertJsonValidationErrors(['photos']);

        $this->assertDatabaseMissing('reports', ['display_id' => 'DRL-WRONG-MODULE']);
        $this->assertDatabaseMissing('reports', ['display_id' => 'DRL-OVERSIZED-MEDIA']);
    }

    public function test_submission_key_replay_does_not_duplicate_or_drop_drill_links(): void
    {
        $owner = $this->userWithPermission('reports.drill.view');
        $mediaId = $this->upload($owner, 'drill');
        $media = ReportMedia::query()->where('public_id', $mediaId)->firstOrFail();
        $request = [
            'display_id' => 'DRL-IDEMPOTENT-MEDIA',
            'submission_key' => 'drill-media-submit-key',
            'report_type' => 'drill',
            'status' => 'Submitted',
            'payload' => $this->payloadWithPhoto($mediaId),
        ];

        $first = $this->actingAs($owner)->postJson('/api/reports', $request)->assertCreated();
        $reportUid = (string) $first->json('data.id');
        $this->postJson('/api/reports', $request)
            ->assertOk()
            ->assertJsonPath('data.id', $reportUid)
            ->assertJsonPath('data.idempotent_replay', true);

        $this->assertSame(1, ReportMediaLink::query()
            ->where('report_media_id', $media->id)
            ->where('parent_type', 'report')
            ->where('parent_key', $reportUid)
            ->count());
    }

    private function upload(User $user, string $module, ?string $uploadId = null): string
    {
        $response = $this->actingAs($user)->post('/api/report-media', [
            'file' => UploadedFile::fake()->image($module.'-camera.jpg', 1600, 900),
            'module' => $module,
            'source' => 'camera',
            'upload_id' => $uploadId ?? (string) Str::uuid(),
            'context_key' => 'drill-lifecycle-test',
        ], ['Accept' => 'application/json']);
        $response->assertCreated();

        return (string) $response->json('data.media_id');
    }

    private function payloadWithPhoto(?string $mediaId): array
    {
        return [
            'schemaVersion' => 2,
            'reportDate' => '2026-07-11',
            'reportTime' => '09:00',
            'weather' => 'Clear',
            'incidentType' => 'Fire Drill',
            'location' => 'Workshop',
            'details' => 'A controlled fire and rescue exercise.',
            'summary' => 'The exercise was completed safely.',
            'chronology' => [
                ['time' => '09:00', 'action' => 'Exercise started'],
            ],
            'postIncidentAnalysis' => [
                'photos' => $mediaId ? [[
                    'mediaId' => $mediaId,
                    'url' => '/api/report-media/'.$mediaId,
                    'description' => 'Initial response position',
                ]] : [],
            ],
        ];
    }

    private function ercoPayloadWithPhoto(string $mediaId): array
    {
        return [
            'schemaVersion' => 1,
            'incidentDate' => '2026-07-11',
            'incidentTime' => '09:00',
            'weather' => 'Clear',
            'incidentType' => 'Fire',
            'location' => 'Workshop',
            'details' => 'Emergency response media lifecycle test.',
            'summary' => 'Emergency response media persisted successfully.',
            'respondingTeam' => [
                'attendance' => [['memberId' => 'member-1', 'name' => 'Responder One']],
            ],
            'chronology' => [['time' => '09:00', 'action' => 'Response started.']],
            'postIncidentAnalysis' => [
                'strengths' => ['Prompt mobilisation'],
                'photos' => [[
                    'mediaId' => $mediaId,
                    'url' => '/api/report-media/'.$mediaId,
                ]],
            ],
        ];
    }

    private function createSizedMedia(User $owner, string $publicId, int $sizeBytes): ReportMedia
    {
        $path = 'report-media/'.$publicId.'.jpg';
        Storage::disk('local')->put($path, 'image');

        return ReportMedia::query()->create([
            'public_id' => $publicId,
            'user_id' => $owner->id,
            'module' => 'drill',
            'disk' => 'local',
            'storage_path' => $path,
            'original_name' => $publicId.'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => $sizeBytes,
            'width' => 100,
            'height' => 100,
        ]);
    }

    private function userWithPermission(string $permissionName): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, $permissionName);

        return $user;
    }

    private function grantPermission(User $user, string $permissionName): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Media test '.$permissionName,
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);
    }
}
