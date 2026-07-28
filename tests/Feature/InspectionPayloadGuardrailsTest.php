<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Models\ReportDraft;
use App\Models\ReportMedia;
use App\Models\ReportMediaLink;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRoleAssignment;
use App\Services\RoleCatalog;
use App\Support\Inspection\FrtDailyReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionPayloadGuardrailsTest extends TestCase
{
    use RefreshDatabase;

    private ?Team $workflowTeam = null;

    public function test_managed_inspection_photo_survives_draft_retry_and_submit(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);
        $media = $this->createManagedInspectionPhoto($user, 'rpm_draft_retry_photo');
        $photo = [
            'id' => 'camera-photo-1',
            'mediaId' => $media->public_id,
            'fileName' => 'camera.jpg',
            'description' => 'Defect evidence.',
            'url' => '/api/report-media/'.$media->public_id,
            'thumbnailUrl' => '/api/report-media/'.$media->public_id.'?variant=thumbnail',
            'mimeType' => 'image/jpeg',
            'sizeBytes' => $media->size_bytes,
            'width' => 1200,
            'height' => 900,
        ];
        $payload = [
            'incidentType' => 'General Inspection',
            'location' => 'Zone 1 > Workshop',
            'description' => 'Managed photo sync regression.',
            'photos' => [$photo],
        ];

        $createdDraft = $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => $payload,
        ])->assertCreated();
        $draftId = (string) $createdDraft->json('data.draft_id');

        $this->assertSame($media->public_id, $createdDraft->json('data.payload.photos.0.mediaId'));
        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report_draft',
            'parent_key' => $draftId,
        ]);

        $payload['photos'][0]['description'] = 'Updated after Retry Sync.';
        $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => $payload,
        ])->assertOk()->assertJsonPath('data.payload.photos.0.mediaId', $media->public_id);

        $createdReport = $this->postJson('/api/reports', [
            'display_id' => 'INS-MANAGED-PHOTO-RETRY',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertCreated();
        $reportUid = (string) $createdReport->json('data.id');

        $this->assertSame($media->public_id, $createdReport->json('data.photos.0.mediaId'));
        $this->assertDatabaseHas('report_media_links', [
            'report_media_id' => $media->id,
            'parent_type' => 'report',
            'parent_key' => $reportUid,
        ]);
    }

    public function test_invalid_managed_photo_does_not_leave_a_half_saved_draft(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $media = $this->createManagedInspectionPhoto($owner, 'rpm_other_user_photo');
        $this->actingAs($user);

        $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => [
                'incidentType' => 'General Inspection',
                'location' => 'Zone 1 > Workshop',
                'description' => 'Unauthorized photo regression.',
                'photos' => [[
                    'id' => 'camera-photo-unauthorized',
                    'mediaId' => $media->public_id,
                    'fileName' => 'camera.jpg',
                    'url' => '/api/report-media/'.$media->public_id,
                ]],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['photos']);

        $this->assertDatabaseMissing('report_drafts', [
            'user_id' => $user->id,
            'report_type' => 'inspection',
        ]);
        $this->assertSame(0, ReportMediaLink::query()->count());
    }

    public function test_multi_type_draft_counts_unique_managed_photos_per_report(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);
        $photosByType = [];

        foreach (['general', 'hse'] as $type) {
            for ($index = 1; $index <= 6; $index++) {
                $media = $this->createManagedInspectionPhoto(
                    $user,
                    "rpm_{$type}_workspace_{$index}",
                );
                $photosByType[$type][] = [
                    'id' => "{$type}-photo-{$index}",
                    'mediaId' => $media->public_id,
                    'fileName' => "{$type}-{$index}.jpg",
                    'url' => '/api/report-media/'.$media->public_id,
                ];
            }
        }

        $generalDraft = [
            'incidentType' => 'General Inspection',
            'location' => 'Zone 1 > Workshop',
            'description' => 'General inspection workspace draft.',
            'photos' => $photosByType['general'],
        ];
        $hseDraft = [
            'incidentType' => 'Health Safety Environment Inspection',
            'location' => 'Zone 1 > Workshop',
            'description' => 'HSE workspace draft.',
            'photos' => $photosByType['hse'],
        ];

        $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => array_merge($generalDraft, [
                'inspectionTypeDrafts' => [
                    'general inspection' => $generalDraft,
                    'health safety environment inspection' => $hseDraft,
                ],
            ]),
        ])->assertCreated();

        $this->assertSame(
            12,
            ReportMediaLink::query()->where('parent_type', 'report_draft')->count(),
        );
    }

    public function test_inspection_endpoints_require_inspection_permission(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        $create = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-000',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Z',
                'description' => 'Permission guardrail',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
            ],
        ]);
        $create->assertStatus(403);

        $pdf = $this->postJson('/api/reports/inspection/pdf', [
            'report_uid' => 'non-existent',
        ]);
        $pdf->assertStatus(403);

        $summary = $this->getJson('/api/reports/inspection/checklist-summary');
        $summary->assertStatus(403);
    }

    public function test_inspection_report_rejects_more_than_max_photo_count(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $photos = [];
        for ($i = 0; $i < 11; $i++) {
            $photos[] = [
                'id' => "photo-{$i}",
                'description' => "photo {$i}",
                'url' => $this->makeImageDataUrl(32),
            ];
        }

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone A',
                'description' => 'Payload count guardrail',
                'photos' => $photos,
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.photos']);
    }

    public function test_inspection_report_counts_nested_hydraulic_defect_photos_against_photo_limit(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $defectPhotos = [];
        for ($i = 0; $i < 11; $i++) {
            $defectPhotos[] = [
                'id' => "defect-photo-{$i}",
                'description' => "defect photo {$i}",
                'url' => $this->makeImageDataUrl(32),
            ];
        }

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-NESTED-PHOTOS',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Hydraulic Rescue Tools Inspection',
                'location' => 'FRT',
                'mainLocation' => 'FRT',
                'description' => 'Nested payload count guardrail',
                'photos' => [],
                'hydraulicChecks' => [
                    [
                        'id' => 'frt:hydraulic-pump-motor-1',
                        'location' => 'FRT',
                        'equipment' => 'Hydraulic Pump Motor 1',
                        'physicalCondition' => 'OK',
                        'mechanicalCondition' => 'OK',
                        'noLeakage' => 'OK',
                        'functionTest' => 'Defect',
                        'functionTestRemarks' => 'Slow response.',
                        'functionTestPhotos' => $defectPhotos,
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.photos']);
    }

    public function test_inspection_report_counts_nested_frt_issue_photos_against_photo_limit(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        $payload['frtDailyChecks'][89]['photos'] = [];
        for ($i = 0; $i < 11; $i++) {
            $payload['frtDailyChecks'][89]['photos'][] = [
                'id' => "frt-issue-photo-{$i}",
                'description' => "frt issue photo {$i}",
                'url' => $this->makeImageDataUrl(32),
            ];
        }

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-NESTED-PHOTOS',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.photos']);
    }

    public function test_inspection_report_counts_nested_high_angle_additional_photos_against_photo_limit(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $additionalPhotos = [];
        for ($i = 0; $i < 11; $i++) {
            $additionalPhotos[] = [
                'id' => "high-angle-additional-photo-{$i}",
                'description' => "high angle additional photo {$i}",
                'url' => $this->makeImageDataUrl(32),
            ];
        }

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-HA-ADDITIONAL-PHOTOS',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'High Angle Rescue Equipment Inspection',
                'location' => 'Response Kit #1',
                'mainLocation' => 'Response Kit #1',
                'highAngleInspectedBy' => 'Inspector Rope',
                'highAngleInspectionDate' => '2026-06-28',
                'photos' => [],
                'highAngleChecks' => [
                    [
                        'id' => 'response-kit-1:1',
                        'rowNumber' => '1',
                        'mainLocation' => 'Response Kit #1',
                        'location' => 'N/A',
                        'subLocation' => 'N/A',
                        'equipment' => 'Heavy Duty Organizer Bag',
                        'quantity' => '1',
                        'condition' => 'Good',
                        'additionalPhotos' => $additionalPhotos,
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.photos']);
    }

    public function test_inspection_report_counts_nested_frt_additional_photos_against_photo_limit(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        foreach ($payload['frtDailyChecks'] as &$row) {
            if (($row['id'] ?? '') === 'daily:fire-truck:91') {
                $row['additionalPhotos'] = [];
                for ($i = 0; $i < 11; $i++) {
                    $row['additionalPhotos'][] = [
                        'id' => "frt-additional-photo-{$i}",
                        'description' => "frt additional photo {$i}",
                        'url' => $this->makeImageDataUrl(32),
                    ];
                }
                break;
            }
        }
        unset($row);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-ADDITIONAL-PHOTOS',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.photos']);
    }

    public function test_inspection_report_and_draft_normalize_repeatable_finding_cards(): void
    {
        $user = User::factory()->create(['status' => 'active', 'name' => 'Finding Inspector']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $create = $this->postJson('/api/reports', [
            'display_id' => 'INS-GEN-FINDING-GUARD',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'General Inspection',
                'location' => 'Zone 1 > Workshop',
                'description' => 'General inspection with separate findings.',
                'reportRemarks' => 'Whole workshop access was limited after 1600.',
                'photos' => [],
                'inspectionIssues' => [
                    [
                        'id' => 'issue-a',
                        'description' => 'Blocked emergency exit.',
                        'action_required' => 'Remove stored items.',
                        'issue_photos' => [
                            [
                                'id' => 'issue-photo-1',
                                'description' => 'Blocked exit evidence.',
                                'url' => $this->makeImageDataUrl(32),
                            ],
                        ],
                    ],
                    [
                        'description' => '',
                        'actionRequired' => '',
                        'photos' => [],
                    ],
                ],
            ],
        ]);

        $create->assertCreated();
        $report = Report::query()->where('report_uid', $create->json('data.id'))->firstOrFail();
        $this->assertSame('Whole workshop access was limited after 1600.', $report->payload['reportRemarks'] ?? null);
        $this->assertArrayNotHasKey('report_remarks', $report->payload);
        $this->assertCount(1, $report->payload['inspectionIssues'] ?? []);
        $this->assertSame('Blocked emergency exit.', $report->payload['inspectionIssues'][0]['description'] ?? null);
        $this->assertSame('Remove stored items.', $report->payload['inspectionIssues'][0]['actionRequired'] ?? null);
        $this->assertSame(
            'Blocked exit evidence.',
            $report->payload['inspectionIssues'][0]['photos'][0]['description'] ?? null,
        );
        $this->assertSame($report->payload['inspectionIssues'], $report->payload['issues'] ?? null);

        $draft = $this->postJson('/api/reports/draft', [
            'mode' => 'new',
            'report_type' => 'inspection',
            'payload' => [
                'incidentType' => 'General Inspection',
                'location' => 'Zone 1 > Dock',
                'description' => 'HSE inspection with separate findings.',
                'report_remarks' => 'Dock inspection paused during vessel movement.',
                'photos' => [],
                'issues' => [
                    [
                        'details' => 'Oil spill near walkway.',
                        'action_required' => 'Barricade and clean area.',
                    ],
                ],
            ],
        ]);

        $draft->assertCreated();
        $storedDraft = ReportDraft::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame('Dock inspection paused during vessel movement.', $storedDraft->payload['reportRemarks'] ?? null);
        $this->assertArrayNotHasKey('report_remarks', $storedDraft->payload);
        $this->assertCount(1, $storedDraft->payload['inspectionIssues'] ?? []);
        $this->assertSame('Oil spill near walkway.', $storedDraft->payload['inspectionIssues'][0]['description'] ?? null);
        $this->assertSame('Barricade and clean area.', $storedDraft->payload['inspectionIssues'][0]['actionRequired'] ?? null);
        $this->assertSame($storedDraft->payload['inspectionIssues'], $storedDraft->payload['issues'] ?? null);
    }

    public function test_inspection_report_and_draft_reject_oversized_report_remarks(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);
        $remarks = str_repeat('A', 2001);

        $create = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-REPORT-REMARKS-LONG',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Remarks',
                'description' => 'Report remarks guardrail.',
                'reportRemarks' => $remarks,
                'photos' => [],
            ],
        ]);

        $create->assertStatus(422);
        $create->assertJsonValidationErrors(['payload.reportRemarks']);

        $draft = $this->postJson('/api/reports/draft', [
            'mode' => 'new',
            'report_type' => 'inspection',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Remarks',
                'description' => 'Draft remarks guardrail.',
                'report_remarks' => $remarks,
                'photos' => [],
            ],
        ]);

        $draft->assertStatus(422);
        $draft->assertJsonValidationErrors(['payload.reportRemarks']);
    }

    public function test_inspection_report_and_draft_reject_non_text_report_remarks(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $create = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-REPORT-REMARKS-NON-TEXT',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Remarks',
                'description' => 'Report remarks guardrail.',
                'reportRemarks' => ['not' => 'text'],
                'photos' => [],
            ],
        ]);

        $create->assertStatus(422);
        $create->assertJsonValidationErrors(['payload.reportRemarks']);

        $draft = $this->postJson('/api/reports/draft', [
            'mode' => 'new',
            'report_type' => 'inspection',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Remarks',
                'description' => 'Draft remarks guardrail.',
                'report_remarks' => ['not' => 'text'],
                'photos' => [],
            ],
        ]);

        $draft->assertStatus(422);
        $draft->assertJsonValidationErrors(['payload.reportRemarks']);
    }

    public function test_inspection_report_accepts_structured_checklist_payload(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-CHECKLIST',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Checklist',
                'description' => 'Checklist payload guardrail',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
                'checklistVersion' => 'inspection-checklist-v1',
                'checklist' => [
                    [
                        'id' => 'routine-inspection:area-checked',
                        'label' => 'Area checked',
                        'inspectionType' => 'Routine Inspection',
                        'selected' => true,
                        'selectedAt' => '2026-06-26T00:00:00.000Z',
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.checklist.0.label', 'Area checked');
        $response->assertJsonPath('data.checklistVersion', 'inspection-checklist-v1');

        $report = Report::query()->where('display_id', 'INS-GUARD-CHECKLIST')->firstOrFail();
        $this->assertTrue((bool) $report->inspection_has_checklist);
        $this->assertContains('routine-inspection:area-checked', $report->inspection_checklist_item_ids);
        $this->assertContains('Area checked', $report->inspection_checklist_item_labels);

        $filtered = $this->getJson('/api/reports?reportType=inspection&has_checklist=true&checklist_item=routine-inspection:area-checked');
        $filtered->assertOk();
        $filtered->assertJsonCount(1, 'data');
        $filtered->assertJsonPath('data.0.displayId', 'INS-GUARD-CHECKLIST');
    }

    public function test_inspection_report_overwrites_spoofed_actor_role_with_session_role(): void
    {
        $user = User::factory()->create(['status' => 'active', 'name' => 'Session Inspector']);
        $this->grantInspectionPermission($user, 'Tactical Response Team');
        $this->grantInspectionPermission($user, 'Incident Commander');
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-ROLE-SNAPSHOT',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Role',
                'description' => 'Role snapshot guardrail.',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
                'inspectionActor' => [
                    'userId' => 999,
                    'name' => 'Spoofed User',
                    'email' => 'spoofed@example.test',
                    'role' => 'Spoofed Role',
                    'roleCode' => 'BAD',
                ],
                'submittedByRole' => 'Spoofed Role',
                'submittedByRoleCode' => 'BAD',
                'submittedBy' => 'Spoofed User',
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.inspectionActor.userId', $user->id);
        $response->assertJsonPath('data.inspectionActor.name', 'Session Inspector');
        $response->assertJsonPath('data.inspectionActor.role', 'Incident Commander');
        $response->assertJsonPath('data.inspectionActor.roleCode', 'IC');
        $response->assertJsonPath('data.submittedByRole', 'Incident Commander');
        $response->assertJsonPath('data.submittedByRoleCode', 'IC');
        $response->assertJsonPath('data.submittedBy', 'Session Inspector');
        $response->assertJsonPath('data.approvalHistory.0.actorRole', 'Incident Commander');
        $response->assertJsonPath('data.approvalHistory.0.actorRoleCode', 'IC');
        $response->assertJsonPath('data.timeline.0.meta.actorRole', 'Incident Commander');
        $response->assertJsonPath('data.timeline.0.meta.actorRoleCode', 'IC');

        $report = Report::query()->where('display_id', 'INS-ROLE-SNAPSHOT')->firstOrFail();
        $this->assertSame('Incident Commander', $report->payload['inspectionActor']['role'] ?? null);
        $this->assertSame('IC', $report->payload['inspectionActor']['roleCode'] ?? null);
        $this->assertSame('Incident Commander', $report->payload['submittedByRole'] ?? null);
        $this->assertSame('IC', $report->payload['submittedByRoleCode'] ?? null);
        $this->assertSame('Session Inspector', $report->payload['submittedBy'] ?? null);
    }

    public function test_inspection_draft_overwrites_spoofed_actor_role_with_session_role(): void
    {
        $user = User::factory()->create(['status' => 'active', 'name' => 'Draft Inspector']);
        $this->grantInspectionPermission($user, 'Tactical Response Team');
        $this->actingAs($user);

        $response = $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Draft Role',
                'description' => 'Draft role snapshot guardrail.',
                'inspection_actor' => [
                    'user_id' => 999,
                    'name' => 'Spoofed Draft User',
                    'email' => 'spoofed@example.test',
                    'role' => 'Spoofed Role',
                    'role_code' => 'BAD',
                ],
                'submitted_by_role' => 'Spoofed Role',
                'submitted_by_role_code' => 'BAD',
                'submitted_by' => 'Spoofed Draft User',
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.payload.inspectionActor.userId', $user->id);
        $response->assertJsonPath('data.payload.inspectionActor.name', 'Draft Inspector');
        $response->assertJsonPath('data.payload.inspectionActor.role', 'Tactical Response Team');
        $response->assertJsonPath('data.payload.inspectionActor.roleCode', 'TRT');
        $response->assertJsonPath('data.payload.submittedByRole', 'Tactical Response Team');
        $response->assertJsonPath('data.payload.submittedByRoleCode', 'TRT');
        $response->assertJsonPath('data.payload.submittedBy', 'Draft Inspector');

        $draft = ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('report_type', 'inspection')
            ->firstOrFail();

        $this->assertSame('Tactical Response Team', $draft->payload['inspectionActor']['role'] ?? null);
        $this->assertSame('TRT', $draft->payload['inspectionActor']['roleCode'] ?? null);
        $this->assertSame('Tactical Response Team', $draft->payload['submittedByRole'] ?? null);
        $this->assertSame('TRT', $draft->payload['submittedByRoleCode'] ?? null);
        $this->assertSame('Draft Inspector', $draft->payload['submittedBy'] ?? null);
    }

    public function test_inspection_workflow_review_snapshots_actor_role(): void
    {
        $submitter = User::factory()->create(['status' => 'active', 'name' => 'Submitter']);
        $reviewer = User::factory()->create(['status' => 'active', 'name' => 'Reviewer']);
        $this->grantInspectionPermission($submitter, 'Tactical Response Team');
        $this->grantInspectionPermission($reviewer, 'Incident Commander');

        $this->actingAs($submitter);
        $create = $this->postJson('/api/reports', [
            'display_id' => 'INS-ROLE-REVIEW',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Review Role',
                'description' => 'Workflow role snapshot guardrail.',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
            ],
        ]);
        $create->assertCreated();

        $this->actingAs($reviewer);
        $review = $this->postJson('/api/reports/'.$create->json('data.id').'/review', [
            'version' => 1,
            'remarks' => 'Checked by IC.',
        ]);

        $review->assertOk();
        $review->assertJsonPath('data.approvalHistory.1.action', 'Reviewed');
        $review->assertJsonPath('data.approvalHistory.1.actorRole', 'Incident Commander');
        $review->assertJsonPath('data.approvalHistory.1.actorRoleCode', 'IC');
        $review->assertJsonPath('data.timeline.1.meta.actorRole', 'Incident Commander');
        $review->assertJsonPath('data.timeline.1.meta.actorRoleCode', 'IC');
    }

    public function test_hydraulic_inspection_report_persists_structured_checks_to_database_and_response(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-HYD-DB',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Hydraulic Rescue Tools Inspection',
                'location' => 'FRT',
                'mainLocation' => 'FRT',
                'description' => 'Hydraulic rescue tools checked at FRT.',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'hydraulic evidence',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
                'hydraulicChecks' => [
                    [
                        'id' => 'frt:hydraulic-pump-motor-1',
                        'location' => 'FRT',
                        'equipment' => 'Hydraulic Pump Motor 1',
                        'equipmentDescription' => 'FRT primary rescue pump.',
                        'physicalCondition' => 'ok',
                        'mechanicalCondition' => 'OK',
                        'noLeakage' => 'N/A',
                        'noLeakageRemarks' => 'Leak test skipped because tool was isolated.',
                        'functionTest' => 'Defect',
                        'remarks' => 'General equipment note.',
                        'functionTestRemarks' => 'Slow response.',
                        'functionTestPhotos' => [
                            [
                                'id' => 'function-test-photo-1',
                                'description' => 'Function test defect photo',
                                'url' => $this->makeImageDataUrl(16),
                            ],
                        ],
                    ],
                ],
                'checklist' => [
                    [
                        'id' => 'hydraulic-rescue-tools-inspection:hydraulic-pump-motor-1:function-test:defect',
                        'label' => 'Hydraulic Pump Motor 1 - Function Test: Defect',
                        'inspectionType' => 'Hydraulic Rescue Tools Inspection',
                        'selected' => true,
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.hydraulicChecks.0.physicalCondition', 'OK');
        $response->assertJsonPath('data.hydraulicChecks.0.equipmentDescription', 'FRT primary rescue pump.');
        $response->assertJsonPath('data.hydraulicChecks.0.noLeakage', 'N/A');
        $response->assertJsonPath('data.hydraulicChecks.0.noLeakageRemarks', 'Leak test skipped because tool was isolated.');
        $response->assertJsonPath('data.hydraulicChecks.0.functionTest', 'Defect');
        $response->assertJsonPath('data.hydraulicChecks.0.functionTestRemarks', 'Slow response.');
        $response->assertJsonPath('data.hydraulicChecks.0.functionTestPhotos.0.description', 'Function test defect photo');
        $response->assertJsonPath('data.checklistVersion', 'inspection-checklist-v1');

        $report = Report::query()->where('display_id', 'INS-HYD-DB')->firstOrFail();
        $this->assertSame(
            'Hydraulic Pump Motor 1',
            $report->payload['hydraulicChecks'][0]['equipment'] ?? null,
        );
        $this->assertSame(
            'FRT primary rescue pump.',
            $report->payload['hydraulicChecks'][0]['equipmentDescription'] ?? null,
        );
        $this->assertSame('OK', $report->payload['hydraulicChecks'][0]['physicalCondition'] ?? null);
        $this->assertSame('N/A', $report->payload['hydraulicChecks'][0]['noLeakage'] ?? null);
        $this->assertSame(
            'Leak test skipped because tool was isolated.',
            $report->payload['hydraulicChecks'][0]['noLeakageRemarks'] ?? null,
        );
        $this->assertSame('Defect', $report->payload['hydraulicChecks'][0]['functionTest'] ?? null);
        $this->assertSame('Slow response.', $report->payload['hydraulicChecks'][0]['functionTestRemarks'] ?? null);
        $this->assertSame(
            'Function test defect photo',
            $report->payload['hydraulicChecks'][0]['functionTestPhotos'][0]['description'] ?? null,
        );
        $this->assertTrue((bool) $report->inspection_has_checklist);
        $this->assertContains(
            'Hydraulic Pump Motor 1 - Function Test: Defect',
            $report->inspection_checklist_item_labels,
        );

        $fetched = $this->getJson('/api/reports?reportType=inspection&checklist_item=Hydraulic%20Pump%20Motor%201%20-%20Function%20Test:%20Defect');
        $fetched->assertOk();
        $fetched->assertJsonPath('data.0.hydraulicChecks.0.functionTestRemarks', 'Slow response.');
    }

    public function test_hydraulic_inspection_draft_persists_structured_checks_to_database(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => [
                'incidentType' => 'Hydraulic Rescue Tools Inspection',
                'location' => 'Store',
                'mainLocation' => 'Store',
                'photos' => [],
                'hydraulic_checks' => [
                    [
                        'location' => 'Store',
                        'equipment' => 'Hydraulic Cutter 2',
                        'physical_condition' => 'N/A',
                        'function_test' => 'OK',
                        'function_test_remarks' => 'Works during draft check.',
                        'function_test_photos' => [
                            [
                                'id' => 'draft-function-photo-1',
                                'description' => 'Draft function evidence',
                                'url' => $this->makeImageDataUrl(16),
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.payload.hydraulicChecks.0.physicalCondition', 'N/A');
        $response->assertJsonPath('data.payload.hydraulicChecks.0.functionTest', 'OK');
        $response->assertJsonPath('data.payload.hydraulicChecks.0.functionTestRemarks', 'Works during draft check.');
        $response->assertJsonPath('data.payload.hydraulicChecks.0.functionTestPhotos.0.description', 'Draft function evidence');
        $response->assertJsonPath('data.payload.checklist', []);

        $draft = ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('report_type', 'inspection')
            ->firstOrFail();

        $this->assertSame('Hydraulic Cutter 2', $draft->payload['hydraulicChecks'][0]['equipment'] ?? null);
        $this->assertSame('N/A', $draft->payload['hydraulicChecks'][0]['physicalCondition'] ?? null);
        $this->assertSame('Works during draft check.', $draft->payload['hydraulicChecks'][0]['functionTestRemarks'] ?? null);
        $this->assertArrayNotHasKey('hydraulic_checks', $draft->payload);
    }

    public function test_er_aux_inspection_report_persists_structured_checks_to_database_and_response(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-ERAUX-DB',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'ER Aux Equipment Inspection',
                'location' => 'Store',
                'mainLocation' => 'Store',
                'erAuxInspectedBy' => 'Inspector One',
                'erAuxInspectionDate' => '2026-06-28',
                'photos' => [],
                'erAuxChecks' => [
                    [
                        'id' => 'store:fire-jacket',
                        'location' => 'Store',
                        'equipment' => 'Fire Jacket',
                        'quantity' => '15',
                        'condition' => 'OK',
                    ],
                    [
                        'id' => 'store:chainsaw',
                        'location' => 'Store',
                        'equipment' => 'Chainsaw',
                        'quantity' => '0',
                        'condition' => 'Missing',
                        'remarks' => 'Sent for replacement.',
                    ],
                ],
                'checklist' => [
                    [
                        'id' => 'er-aux-equipment-inspection:chainsaw:missing',
                        'label' => 'Chainsaw - Qty 0: Missing',
                        'inspectionType' => 'ER Aux Equipment Inspection',
                        'selected' => true,
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.erAuxInspectedBy', $user->name);
        $response->assertJsonPath('data.erAuxInspectionDate', '2026-06-28');
        $response->assertJsonPath('data.erAuxChecks.1.condition', 'Missing');
        $response->assertJsonPath('data.erAuxChecks.1.remarks', 'Sent for replacement.');

        $report = Report::query()->where('display_id', 'INS-ERAUX-DB')->firstOrFail();
        $this->assertSame($user->name, $report->payload['erAuxInspectedBy'] ?? null);
        $this->assertSame('2026-06-28', $report->payload['erAuxInspectionDate'] ?? null);
        $this->assertSame('Fire Jacket', $report->payload['erAuxChecks'][0]['equipment'] ?? null);
        $this->assertSame('15', $report->payload['erAuxChecks'][0]['quantity'] ?? null);
        $this->assertSame('Missing', $report->payload['erAuxChecks'][1]['condition'] ?? null);
        $this->assertSame('Sent for replacement.', $report->payload['erAuxChecks'][1]['remarks'] ?? null);
    }

    public function test_scba_inspection_report_persists_structured_checks_to_database_and_response(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-SCBA-DB',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'SCBA Inspection',
                'location' => 'FRT',
                'mainLocation' => 'FRT',
                'scbaInspectedBy' => 'Inspector SCBA',
                'scbaInspectionDate' => '2026-06-28',
                'photos' => [],
                'scbaBackPlateChecks' => [
                    [
                        'id' => 'backPlate:frt:msa:06',
                        'location' => 'FRT',
                        'brand' => 'MSA',
                        'serialNo' => '06',
                        'backPlateHarnessCondition' => 'good',
                        'highPressureHose' => 'Not Good',
                        'pressureGauge' => 'Good',
                        'alarmDevice' => 'Good',
                        'demandValve' => 'Good',
                        'sealing' => 'Good',
                        'cleanliness' => 'Good',
                        'remarks' => 'Hose coupling worn.',
                        'photos' => [
                            [
                                'id' => 'scba-additional-photo',
                                'description' => 'General SCBA additional photo.',
                                'url' => $this->makeImageDataUrl(16),
                            ],
                        ],
                        'highPressureHoseRemarks' => 'Hose coupling worn.',
                        'highPressureHosePhotos' => [
                            [
                                'id' => 'scba-hose-photo',
                                'description' => 'Hose coupling photo.',
                                'url' => $this->makeImageDataUrl(16),
                            ],
                        ],
                    ],
                ],
                'scbaCylinderChecks' => [
                    [
                        'id' => 'cylinder:frt:msa:6.8l-08',
                        'location' => 'FRT',
                        'brand' => 'MSA',
                        'serialNo' => '6.8L/08',
                        'size' => '6.8',
                        'type' => 'Composite',
                        'servicePressure' => '300',
                        'containedPressure' => '280',
                        'physicalCondition' => 'Good',
                        'handwheelCondition' => 'Good',
                        'valveBodyCondition' => 'Good',
                        'screwPlugCondition' => 'Good',
                        'cleanliness' => 'Good',
                    ],
                ],
                'scbaFaceMaskChecks' => [
                    [
                        'id' => 'faceMask:frt:drager:02',
                        'location' => 'FRT',
                        'brand' => 'Drager',
                        'serialNo' => '02',
                        'visorCondition' => 'Good',
                        'ldvPort' => 'Good',
                        'ldvReleaseButton' => 'Good',
                        'leakTest' => 'Not Good',
                        'speechDiaphragm' => 'Good',
                        'harness' => 'Good',
                        'neckStrap' => 'Good',
                        'remarks' => 'Leak test failed on seal.',
                        'leakTestRemarks' => 'Leak test failed on seal.',
                        'leakTestPhotos' => [
                            [
                                'id' => 'scba-mask-photo',
                                'description' => 'Face mask seal photo.',
                                'url' => $this->makeImageDataUrl(16),
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.scbaInspectedBy', $user->name);
        $response->assertJsonPath('data.scbaInspectionDate', '2026-06-28');
        $response->assertJsonPath('data.scbaBackPlateChecks.0.backPlateHarnessCondition', 'Good');
        $response->assertJsonPath('data.scbaBackPlateChecks.0.highPressureHose', 'Not Good');
        $response->assertJsonPath('data.scbaBackPlateChecks.0.highPressureHoseRemarks', 'Hose coupling worn.');
        $response->assertJsonPath('data.scbaBackPlateChecks.0.photos.0.description', 'General SCBA additional photo.');
        $response->assertJsonPath('data.scbaBackPlateChecks.0.sealing', 'Good');
        $response->assertJsonPath('data.scbaBackPlateChecks.0.cleanliness', 'Good');
        $response->assertJsonPath('data.scbaCylinderChecks.0.cylinderType', 'Composite');
        $response->assertJsonPath('data.scbaCylinderChecks.0.servicePressure', '300');
        $response->assertJsonPath('data.scbaCylinderChecks.0.containedPressure', '280');
        $response->assertJsonPath('data.scbaCylinderChecks.0.cleanliness', 'Good');
        $response->assertJsonPath('data.scbaFaceMaskChecks.0.leakTest', 'Not Good');
        $response->assertJsonPath('data.scbaFaceMaskChecks.0.leakTestRemarks', 'Leak test failed on seal.');
        $response->assertJsonPath('data.scbaFaceMaskChecks.0.harness', 'Good');

        $report = Report::query()->where('display_id', 'INS-SCBA-DB')->firstOrFail();
        $this->assertSame($user->name, $report->payload['scbaInspectedBy'] ?? null);
        $this->assertSame('2026-06-28', $report->payload['scbaInspectionDate'] ?? null);
        $this->assertSame('Good', $report->payload['scbaBackPlateChecks'][0]['backPlateHarnessCondition'] ?? null);
        $this->assertSame('Not Good', $report->payload['scbaBackPlateChecks'][0]['highPressureHose'] ?? null);
        $this->assertSame('Hose coupling worn.', $report->payload['scbaBackPlateChecks'][0]['highPressureHoseRemarks'] ?? null);
        $this->assertSame('General SCBA additional photo.', $report->payload['scbaBackPlateChecks'][0]['photos'][0]['description'] ?? null);
        $this->assertCount(1, $report->payload['scbaBackPlateChecks'][0]['highPressureHosePhotos'] ?? []);
        $this->assertSame('Good', $report->payload['scbaBackPlateChecks'][0]['sealing'] ?? null);
        $this->assertSame('Good', $report->payload['scbaBackPlateChecks'][0]['cleanliness'] ?? null);
        $this->assertSame('Composite', $report->payload['scbaCylinderChecks'][0]['cylinderType'] ?? null);
        $this->assertSame('300', $report->payload['scbaCylinderChecks'][0]['servicePressure'] ?? null);
        $this->assertSame('280', $report->payload['scbaCylinderChecks'][0]['containedPressure'] ?? null);
        $this->assertSame('Good', $report->payload['scbaCylinderChecks'][0]['cleanliness'] ?? null);
        $this->assertSame('Not Good', $report->payload['scbaFaceMaskChecks'][0]['leakTest'] ?? null);
        $this->assertSame('Leak test failed on seal.', $report->payload['scbaFaceMaskChecks'][0]['leakTestRemarks'] ?? null);
        $this->assertCount(1, $report->payload['scbaFaceMaskChecks'][0]['leakTestPhotos'] ?? []);
        $this->assertSame('Good', $report->payload['scbaFaceMaskChecks'][0]['harness'] ?? null);
    }

    public function test_scba_inspection_report_persists_custom_sections_and_field_evidence(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = [
            'incidentType' => 'SCBA Inspection',
            'location' => 'Custom Bay',
            'mainLocation' => 'Custom Bay',
            'photos' => [],
            'scbaCustomSections' => [
                [
                    'title' => 'Regulator',
                    'shortLabel' => 'Regulator',
                    'fields' => [
                        ['key' => 'purgeValve', 'label' => 'Purge Valve', 'kind' => 'status'],
                    ],
                    'rows' => [
                        [
                            'id' => 'customScba-regulator:custom-bay:msa:r-01',
                            'location' => 'Custom Bay',
                            'brand' => 'MSA',
                            'serialNo' => 'R-01',
                            'purgeValve' => 'Not Good',
                            'purgeValveRemarks' => 'Purge valve sticks.',
                            'purgeValvePhotos' => [
                                [
                                    'id' => 'purge-photo',
                                    'description' => 'Purge valve issue.',
                                    'url' => $this->makeImageDataUrl(16),
                                ],
                            ],
                            'photos' => [
                                [
                                    'id' => 'regulator-photo',
                                    'description' => 'General regulator photo.',
                                    'url' => $this->makeImageDataUrl(16),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-SCBA-CUSTOM-DB',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.scbaCustomSections.0.title', 'Regulator');
        $response->assertJsonPath('data.scbaCustomSections.0.rows.0.purgeValve', 'Not Good');
        $response->assertJsonPath('data.scbaCustomSections.0.rows.0.purgeValvePhotos.0.description', 'Purge valve issue.');

        $report = Report::query()->where('display_id', 'INS-SCBA-CUSTOM-DB')->firstOrFail();
        $this->assertSame('Regulator', $report->payload['scbaCustomSections'][0]['title'] ?? null);
        $this->assertSame('Not Good', $report->payload['scbaCustomSections'][0]['rows'][0]['purgeValve'] ?? null);
        $this->assertSame('Purge valve sticks.', $report->payload['scbaCustomSections'][0]['rows'][0]['purgeValveRemarks'] ?? null);
        $this->assertSame('General regulator photo.', $report->payload['scbaCustomSections'][0]['rows'][0]['photos'][0]['description'] ?? null);
    }

    public function test_scba_custom_section_accepts_not_good_without_optional_issue_photo(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-SCBA-CUSTOM-BAD',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'SCBA Inspection',
                'location' => 'Custom Bay',
                'mainLocation' => 'Custom Bay',
                'photos' => [],
                'scbaCustomSections' => [
                    [
                        'title' => 'Regulator',
                        'fields' => [
                            ['key' => 'purgeValve', 'label' => 'Purge Valve', 'kind' => 'status'],
                        ],
                        'rows' => [
                            [
                                'location' => 'Custom Bay',
                                'brand' => 'MSA',
                                'serialNo' => 'R-02',
                                'purgeValve' => 'Not Good',
                                'purgeValveRemarks' => 'Purge valve sticks.',
                                'purgeValvePhotos' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.scbaCustomSections.0.rows.0.purgeValvePhotos', []);
    }

    public function test_scba_inspection_draft_persists_structured_checks_to_database(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => [
                'incidentType' => 'SCBA Inspection',
                'location' => 'Store',
                'mainLocation' => 'Store',
                'scba_inspected_by' => 'Draft Inspector',
                'scba_inspection_date' => '2026-06-28',
                'scba_back_plate_checks' => [
                    [
                        'location' => 'Store',
                        'brand' => 'MSA',
                        'serial_no' => '01',
                        'back_plate_harness_condition' => 'Good',
                        'high_pressure_hose' => 'Good',
                        'pressure_gauge' => 'Good',
                        'alarm_device' => 'Good',
                        'demand_valve' => 'Good',
                        'sealing' => 'Good',
                        'cleanliness' => 'Good',
                    ],
                ],
                'scba_cylinder_checks' => [
                    [
                        'location' => 'Store',
                        'brand' => 'Drager',
                        'serial_no' => '6L/01',
                        'size' => '6',
                        'cylinder_type' => 'Steel',
                        'service_pressure' => '200',
                        'contained_pressure' => '180',
                        'physical_condition' => 'Good',
                        'handwheel_condition' => 'Good',
                        'valve_body_condition' => 'Good',
                        'screw_plug_condition' => 'Good',
                        'cleanliness' => 'Good',
                    ],
                ],
                'scba_face_mask_checks' => [
                    [
                        'location' => 'Store',
                        'brand' => 'Drager',
                        'serial_no' => '07',
                        'visor_condition' => 'Good',
                        'ldv_port' => 'Good',
                        'ldv_release_button' => 'Good',
                        'leak_test' => 'Good',
                        'speech_diaphragm' => 'Good',
                        'harness' => 'Good',
                        'neck_strap' => 'Good',
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.payload.scbaInspectedBy', $user->name);
        $response->assertJsonPath('data.payload.scbaInspectionDate', '2026-06-28');
        $response->assertJsonPath('data.payload.scbaBackPlateChecks.0.serialNo', '01');
        $response->assertJsonPath('data.payload.scbaBackPlateChecks.0.cleanliness', 'Good');
        $response->assertJsonPath('data.payload.scbaCylinderChecks.0.cylinderType', 'Steel');
        $response->assertJsonPath('data.payload.scbaCylinderChecks.0.servicePressure', '200');
        $response->assertJsonPath('data.payload.scbaCylinderChecks.0.containedPressure', '180');
        $response->assertJsonPath('data.payload.scbaCylinderChecks.0.cleanliness', 'Good');
        $response->assertJsonPath('data.payload.scbaFaceMaskChecks.0.leakTest', 'Good');
        $response->assertJsonPath('data.payload.scbaFaceMaskChecks.0.harness', 'Good');

        $draft = ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('report_type', 'inspection')
            ->firstOrFail();

        $this->assertSame($user->name, $draft->payload['scbaInspectedBy'] ?? null);
        $this->assertSame('Good', $draft->payload['scbaBackPlateChecks'][0]['cleanliness'] ?? null);
        $this->assertSame('Steel', $draft->payload['scbaCylinderChecks'][0]['cylinderType'] ?? null);
        $this->assertSame('200', $draft->payload['scbaCylinderChecks'][0]['servicePressure'] ?? null);
        $this->assertSame('180', $draft->payload['scbaCylinderChecks'][0]['containedPressure'] ?? null);
        $this->assertSame('Good', $draft->payload['scbaCylinderChecks'][0]['cleanliness'] ?? null);
        $this->assertSame('Good', $draft->payload['scbaFaceMaskChecks'][0]['leakTest'] ?? null);
        $this->assertSame('Good', $draft->payload['scbaFaceMaskChecks'][0]['harness'] ?? null);
        $this->assertArrayNotHasKey('scba_back_plate_checks', $draft->payload);
        $this->assertArrayNotHasKey('scba_cylinder_checks', $draft->payload);
        $this->assertArrayNotHasKey('scba_face_mask_checks', $draft->payload);
    }

    public function test_high_angle_inspection_report_persists_structured_checks_to_database_and_response(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-HA-DB',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'High Angle Rescue Equipment Inspection',
                'location' => 'Response Kit #1',
                'mainLocation' => 'Response Kit #1',
                'highAngleInspectedBy' => 'Inspector Rope',
                'highAngleInspectionDate' => '2026-06-28',
                'photos' => [],
                'highAngleChecks' => [
                    [
                        'id' => 'response-kit-1:1',
                        'rowNumber' => '1',
                        'mainLocation' => 'Response Kit #1',
                        'location' => 'N/A',
                        'subLocation' => 'N/A',
                        'equipment' => 'Heavy Duty Organizer Bag',
                        'quantity' => '1',
                        'condition' => 'good',
                        'remarks' => '',
                        'additionalNotes' => 'Stored in upper pouch.',
                        'additionalPhotos' => [
                            [
                                'id' => 'high-angle-additional-photo',
                                'description' => 'Organizer bag storage photo.',
                                'url' => $this->makeImageDataUrl(16),
                            ],
                        ],
                    ],
                    [
                        'id' => 'response-kit-1:3',
                        'rowNumber' => '3',
                        'mainLocation' => 'Response Kit #1',
                        'location' => 'Heavy Duty Organizer Bag',
                        'subLocation' => 'Main Compartment',
                        'equipment' => 'Locking Carabiner - CT - Steel - S',
                        'quantity' => '10',
                        'condition' => 'Not Good',
                        'remarks' => 'Gate spring is sticking.',
                        'conditionRemarks' => 'Gate spring is sticking.',
                        'conditionPhotos' => [
                            [
                                'id' => 'high-angle-gate-photo',
                                'description' => 'Gate spring evidence.',
                                'url' => $this->makeImageDataUrl(16),
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.highAngleInspectedBy', $user->name);
        $response->assertJsonPath('data.highAngleInspectionDate', '2026-06-28');
        $response->assertJsonPath('data.highAngleChecks.0.condition', 'Good');
        $response->assertJsonPath('data.highAngleChecks.0.additionalNotes', 'Stored in upper pouch.');
        $response->assertJsonPath('data.highAngleChecks.0.additionalPhotos.0.id', 'high-angle-additional-photo');
        $response->assertJsonPath('data.highAngleChecks.1.subLocation', 'Main Compartment');
        $response->assertJsonPath('data.highAngleChecks.1.quantity', '10');
        $response->assertJsonPath('data.highAngleChecks.1.remarks', 'Gate spring is sticking.');
        $response->assertJsonPath('data.highAngleChecks.1.conditionRemarks', 'Gate spring is sticking.');

        $report = Report::query()->where('display_id', 'INS-HA-DB')->firstOrFail();
        $this->assertSame($user->name, $report->payload['highAngleInspectedBy'] ?? null);
        $this->assertSame('2026-06-28', $report->payload['highAngleInspectionDate'] ?? null);
        $this->assertSame('Good', $report->payload['highAngleChecks'][0]['condition'] ?? null);
        $this->assertSame('Stored in upper pouch.', $report->payload['highAngleChecks'][0]['additionalNotes'] ?? null);
        $this->assertSame('high-angle-additional-photo', $report->payload['highAngleChecks'][0]['additionalPhotos'][0]['id'] ?? null);
        $this->assertSame('Main Compartment', $report->payload['highAngleChecks'][1]['subLocation'] ?? null);
        $this->assertSame('10', $report->payload['highAngleChecks'][1]['quantity'] ?? null);
        $this->assertSame('Gate spring is sticking.', $report->payload['highAngleChecks'][1]['remarks'] ?? null);
        $this->assertSame('Gate spring is sticking.', $report->payload['highAngleChecks'][1]['conditionRemarks'] ?? null);
        $this->assertCount(1, $report->payload['highAngleChecks'][1]['conditionPhotos'] ?? []);
    }

    public function test_high_angle_inspection_draft_persists_structured_checks_to_database(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => [
                'incidentType' => 'High Angle Rescue Equipment Inspection',
                'location' => 'Rescue Rope',
                'mainLocation' => 'Rescue Rope',
                'high_angle_inspected_by' => 'Draft Rope Inspector',
                'high_angle_inspection_date' => '2026-06-28',
                'high_angle_checks' => [
                    [
                        'id' => 'rescue-rope:101',
                        'row_number' => '101',
                        'main_location' => 'Rescue Rope',
                        'location' => 'N/A',
                        'sub_location' => 'N/A',
                        'equipment' => 'R – 13.0mm - 200m – 001/6-2021',
                        'quantity' => '1',
                        'condition' => 'Not Good',
                        'remarks' => 'Outer sheath frayed.',
                        'condition_remarks' => 'Outer sheath frayed.',
                        'additional_notes' => 'Stored on top shelf.',
                        'additional_photos' => [
                            [
                                'id' => 'high-angle-draft-additional-photo',
                                'description' => 'Top shelf storage photo.',
                                'url' => $this->makeImageDataUrl(16),
                            ],
                        ],
                        'condition_photos' => [
                            [
                                'id' => 'high-angle-draft-photo',
                                'description' => 'Rope sheath evidence.',
                                'url' => $this->makeImageDataUrl(16),
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.payload.highAngleInspectedBy', $user->name);
        $response->assertJsonPath('data.payload.highAngleInspectionDate', '2026-06-28');
        $response->assertJsonPath('data.payload.highAngleChecks.0.rowNumber', '101');
        $response->assertJsonPath('data.payload.highAngleChecks.0.mainLocation', 'Rescue Rope');
        $response->assertJsonPath('data.payload.highAngleChecks.0.remarks', 'Outer sheath frayed.');
        $response->assertJsonPath('data.payload.highAngleChecks.0.conditionRemarks', 'Outer sheath frayed.');
        $response->assertJsonPath('data.payload.highAngleChecks.0.additionalNotes', 'Stored on top shelf.');
        $response->assertJsonPath('data.payload.highAngleChecks.0.additionalPhotos.0.id', 'high-angle-draft-additional-photo');

        $draft = ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('report_type', 'inspection')
            ->firstOrFail();

        $this->assertSame($user->name, $draft->payload['highAngleInspectedBy'] ?? null);
        $this->assertSame('2026-06-28', $draft->payload['highAngleInspectionDate'] ?? null);
        $this->assertSame('101', $draft->payload['highAngleChecks'][0]['rowNumber'] ?? null);
        $this->assertSame('Rescue Rope', $draft->payload['highAngleChecks'][0]['mainLocation'] ?? null);
        $this->assertSame('Outer sheath frayed.', $draft->payload['highAngleChecks'][0]['remarks'] ?? null);
        $this->assertSame('Outer sheath frayed.', $draft->payload['highAngleChecks'][0]['conditionRemarks'] ?? null);
        $this->assertSame('Stored on top shelf.', $draft->payload['highAngleChecks'][0]['additionalNotes'] ?? null);
        $this->assertSame('high-angle-draft-additional-photo', $draft->payload['highAngleChecks'][0]['additionalPhotos'][0]['id'] ?? null);
        $this->assertCount(1, $draft->payload['highAngleChecks'][0]['conditionPhotos'] ?? []);
        $this->assertArrayNotHasKey('high_angle_checks', $draft->payload);
    }

    public function test_frt_inspection_report_persists_structured_checks_to_database_and_response(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-FRT-DB',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $this->frtPayload(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.frtInspectedBy', $user->name);
        $response->assertJsonPath('data.frtInspectionDate', '2026-06-28');
        $response->assertJsonPath('data.frtShift', 'Day');
        $response->assertJsonPath('data.frtTruckReference.plateNo', 'AJG9555');
        $dailyRows = collect($response->json('data.frtDailyChecks') ?? [])->keyBy('id');
        $oneOffRows = collect($response->json('data.frtOneOffChecks') ?? [])->keyBy('id');
        $this->assertSame('Checked', $dailyRows->get('daily:fire-truck:56')['status'] ?? null);
        $this->assertSame('123456', $dailyRows->get('daily:fire-truck:91')['readingValue'] ?? null);
        $this->assertSame('Reading verified with driver.', $dailyRows->get('daily:fire-truck:91')['additionalNotes'] ?? null);
        $this->assertSame('frt-reading-additional-photo-1', $dailyRows->get('daily:fire-truck:91')['additionalPhotos'][0]['id'] ?? null);
        $this->assertSame('frt-daily-photo-1', $dailyRows->get('daily:fire-truck:90')['photos'][0]['id'] ?? null);
        $this->assertSame('Not Good', $oneOffRows->get('one-off:fire-truck:16')['condition'] ?? null);
        $this->assertSame('Siren mute switch sticking.', $oneOffRows->get('one-off:fire-truck:16')['remarks'] ?? null);
        $this->assertSame('Retest scheduled after repair.', $oneOffRows->get('one-off:fire-truck:16')['additionalNotes'] ?? null);
        $this->assertSame('frt-one-off-additional-photo-1', $oneOffRows->get('one-off:fire-truck:16')['additionalPhotos'][0]['id'] ?? null);
        $this->assertSame('frt-one-off-photo-1', $oneOffRows->get('one-off:fire-truck:16')['photos'][0]['id'] ?? null);

        $report = Report::query()->where('display_id', 'INS-FRT-DB')->firstOrFail();
        $this->assertSame($user->name, $report->payload['frtInspectedBy'] ?? null);
        $this->assertSame('2026-06-28', $report->payload['frtInspectionDate'] ?? null);
        $this->assertSame('Day', $report->payload['frtShift'] ?? null);
        $this->assertSame('AJG9555', $report->payload['frtTruckReference']['plateNo'] ?? null);
        $reportDailyRows = collect($report->payload['frtDailyChecks'] ?? [])->keyBy('id');
        $reportOneOffRows = collect($report->payload['frtOneOffChecks'] ?? [])->keyBy('id');
        $this->assertSame('Checked', $reportDailyRows->get('daily:fire-truck:56')['status'] ?? null);
        $this->assertSame('123456', $reportDailyRows->get('daily:fire-truck:91')['readingValue'] ?? null);
        $this->assertSame('Reading verified with driver.', $reportDailyRows->get('daily:fire-truck:91')['additionalNotes'] ?? null);
        $this->assertSame('frt-reading-additional-photo-1', $reportDailyRows->get('daily:fire-truck:91')['additionalPhotos'][0]['id'] ?? null);
        $this->assertSame('frt-daily-photo-1', $reportDailyRows->get('daily:fire-truck:90')['photos'][0]['id'] ?? null);
        $this->assertSame('Not Good', $reportOneOffRows->get('one-off:fire-truck:16')['condition'] ?? null);
        $this->assertSame('Siren mute switch sticking.', $reportOneOffRows->get('one-off:fire-truck:16')['remarks'] ?? null);
        $this->assertSame('Retest scheduled after repair.', $reportOneOffRows->get('one-off:fire-truck:16')['additionalNotes'] ?? null);
        $this->assertSame('frt-one-off-additional-photo-1', $reportOneOffRows->get('one-off:fire-truck:16')['additionalPhotos'][0]['id'] ?? null);
        $this->assertSame('frt-one-off-photo-1', $reportOneOffRows->get('one-off:fire-truck:16')['photos'][0]['id'] ?? null);
    }

    public function test_frt_inspection_draft_persists_structured_checks_to_database(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => $this->frtPayload(useSnakeCase: true),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.payload.frtInspectedBy', $user->name);
        $response->assertJsonPath('data.payload.frtInspectionDate', '2026-06-28');
        $response->assertJsonPath('data.payload.frtShift', 'Day');
        $response->assertJsonPath('data.payload.frtTruckReference.plateNo', 'AJG9555');
        $draftResponseDailyRows = collect($response->json('data.payload.frtDailyChecks') ?? [])->keyBy('id');
        $draftResponseOneOffRows = collect($response->json('data.payload.frtOneOffChecks') ?? [])->keyBy('id');
        $this->assertSame('123456', $draftResponseDailyRows->get('daily:fire-truck:91')['readingValue'] ?? null);
        $this->assertSame('Reading verified with driver.', $draftResponseDailyRows->get('daily:fire-truck:91')['additionalNotes'] ?? null);
        $this->assertSame('frt-reading-additional-photo-1', $draftResponseDailyRows->get('daily:fire-truck:91')['additionalPhotos'][0]['id'] ?? null);
        $this->assertSame('frt-daily-photo-1', $draftResponseDailyRows->get('daily:fire-truck:90')['photos'][0]['id'] ?? null);
        $this->assertSame('Not Good', $draftResponseOneOffRows->get('one-off:fire-truck:16')['condition'] ?? null);
        $this->assertSame('Retest scheduled after repair.', $draftResponseOneOffRows->get('one-off:fire-truck:16')['additionalNotes'] ?? null);
        $this->assertSame('frt-one-off-additional-photo-1', $draftResponseOneOffRows->get('one-off:fire-truck:16')['additionalPhotos'][0]['id'] ?? null);
        $this->assertSame('frt-one-off-photo-1', $draftResponseOneOffRows->get('one-off:fire-truck:16')['photos'][0]['id'] ?? null);

        $draft = ReportDraft::query()
            ->where('user_id', $user->id)
            ->where('report_type', 'inspection')
            ->firstOrFail();

        $this->assertSame($user->name, $draft->payload['frtInspectedBy'] ?? null);
        $this->assertSame('2026-06-28', $draft->payload['frtInspectionDate'] ?? null);
        $this->assertSame('Day', $draft->payload['frtShift'] ?? null);
        $this->assertSame('AJG9555', $draft->payload['frtTruckReference']['plateNo'] ?? null);
        $draftDailyRows = collect($draft->payload['frtDailyChecks'] ?? [])->keyBy('id');
        $draftOneOffRows = collect($draft->payload['frtOneOffChecks'] ?? [])->keyBy('id');
        $this->assertSame('123456', $draftDailyRows->get('daily:fire-truck:91')['readingValue'] ?? null);
        $this->assertSame('Reading verified with driver.', $draftDailyRows->get('daily:fire-truck:91')['additionalNotes'] ?? null);
        $this->assertSame('frt-reading-additional-photo-1', $draftDailyRows->get('daily:fire-truck:91')['additionalPhotos'][0]['id'] ?? null);
        $this->assertSame('frt-daily-photo-1', $draftDailyRows->get('daily:fire-truck:90')['photos'][0]['id'] ?? null);
        $this->assertSame('Not Good', $draftOneOffRows->get('one-off:fire-truck:16')['condition'] ?? null);
        $this->assertSame('Retest scheduled after repair.', $draftOneOffRows->get('one-off:fire-truck:16')['additionalNotes'] ?? null);
        $this->assertSame('frt-one-off-additional-photo-1', $draftOneOffRows->get('one-off:fire-truck:16')['additionalPhotos'][0]['id'] ?? null);
        $this->assertSame('frt-one-off-photo-1', $draftOneOffRows->get('one-off:fire-truck:16')['photos'][0]['id'] ?? null);
        $this->assertArrayNotHasKey('frt_daily_checks', $draft->payload);
        $this->assertArrayNotHasKey('frt_one_off_checks', $draft->payload);
    }

    public function test_inspection_report_rejects_invalid_frt_daily_status(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        $payload['frtDailyChecks'][55]['status'] = 'Broken';

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-BAD-FRT-DAILY',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.frtDailyChecks.55.status']);
    }

    public function test_inspection_report_rejects_invalid_frt_one_off_status(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        $payload['frtOneOffChecks'][15]['condition'] = 'Broken';

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-BAD-FRT-ONEOFF',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.frtOneOffChecks.15.condition']);
    }

    public function test_inspection_report_rejects_frt_missing_daily_reading_value(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        $payload['frtDailyChecks'][90]['readingValue'] = '';

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-READING',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.frtDailyChecks.90.readingValue']);
    }

    public function test_inspection_report_rejects_frt_issue_rows_without_remarks(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        $payload['frtDailyChecks'][89]['remarks'] = '';

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-ISSUE-REMARKS',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.frtDailyChecks.89.remarks']);
    }

    public function test_inspection_report_rejects_frt_not_good_rows_without_remarks(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        $payload['frtOneOffChecks'][15]['remarks'] = '';

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-NOT-GOOD-REMARKS',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.frtOneOffChecks.15.remarks']);
    }

    public function test_inspection_report_accepts_frt_issue_rows_without_photos(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        $payload['frtDailyChecks'][89]['photos'] = [];

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-ISSUE-PHOTOS',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.frtDailyChecks.89.photos', []);
    }

    public function test_inspection_report_accepts_frt_not_good_rows_without_photos(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        $payload['frtOneOffChecks'][15]['photos'] = [];

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-NOT-GOOD-PHOTOS',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.frtOneOffChecks.15.photos', []);
    }

    public function test_inspection_report_rejects_invalid_frt_issue_photo_url(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        $payload['frtDailyChecks'][89]['photos'][0]['url'] = 'https://example.test/frt-photo.jpg';

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-BAD-PHOTO',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.frtDailyChecks.89.photos.0.url']);
    }

    public function test_inspection_report_accepts_completed_subset_of_frt_seeded_roster(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        $payload['frtDailyChecks'] = [$payload['frtDailyChecks'][0]];
        $payload['frtOneOffChecks'] = [];

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-ROSTER',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertCreated();
        $this->assertCount(1, $response->json('data.frtDailyChecks') ?? []);
        $this->assertCount(0, $response->json('data.frtOneOffChecks') ?? []);
    }

    public function test_inspection_report_rejects_empty_frt_submission(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        $payload['frtDailyChecks'] = [];
        $payload['frtOneOffChecks'] = [];

        $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-EMPTY',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertUnprocessable()->assertJsonValidationErrors(['payload.frtDailyChecks']);
    }

    public function test_inspection_report_rejects_duplicate_unsupported_and_modified_frt_subset_rows(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        $row = $payload['frtDailyChecks'][0];
        $payload['frtDailyChecks'] = [$row, $row];
        $payload['frtOneOffChecks'] = [];

        $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-DUPLICATE',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertUnprocessable()->assertJsonValidationErrors(['payload.frtDailyChecks.1.id']);

        $payload['frtDailyChecks'] = [array_merge($row, ['id' => 'daily:unsupported:999'])];
        $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-UNSUPPORTED',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertUnprocessable()->assertJsonValidationErrors(['payload.frtDailyChecks.0.id']);

        $payload['frtDailyChecks'] = [array_merge($row, ['equipment' => 'Modified equipment'])];
        $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-MODIFIED',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ])->assertUnprocessable()->assertJsonValidationErrors(['payload.frtDailyChecks.0.equipment']);
    }

    public function test_inspection_report_rejects_frt_reports_without_required_session_meta(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $payload = $this->frtPayload();
        unset($payload['frtInspectedBy']);

        $missingInspector = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-META-1',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);
        $missingInspector->assertCreated();
        $missingInspector->assertJsonPath('data.frtInspectedBy', $user->name);

        $payload = $this->frtPayload();
        $payload['frtShift'] = '';

        $missingShift = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-META-2',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);
        $missingShift->assertCreated();

        $payload = $this->frtPayload();
        unset($payload['frtTruckReference'], $payload['frtTruckPlateNo'], $payload['frtTruckId']);
        $payload['mainLocation'] = '';
        $payload['selectedLocation'] = '';
        $payload['location'] = '';

        $missingTruck = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FRT-META-3',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);
        $missingTruck->assertStatus(422);
        $missingTruck->assertJsonValidationErrors(['payload.frtTruckPlateNo']);
    }

    public function test_inspection_report_rejects_invalid_scba_check_payload(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-BAD-SCBA',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'SCBA Inspection',
                'location' => 'FRT',
                'scbaBackPlateChecks' => [
                    [
                        'location' => 'FRT',
                        'brand' => 'MSA',
                        'serialNo' => '06',
                        'backPlateHarnessCondition' => 'Broken',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.scbaBackPlateChecks.0.backPlateHarnessCondition']);
    }

    public function test_inspection_report_rejects_scba_not_good_rows_without_remarks(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-SCBA-REMARKS',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'SCBA Inspection',
                'location' => 'FRT',
                'mainLocation' => 'FRT',
                'scbaFaceMaskChecks' => [
                    [
                        'location' => 'FRT',
                        'brand' => 'Drager',
                        'serialNo' => '02',
                        'visorCondition' => 'Good',
                        'ldvPort' => 'Good',
                        'ldvReleaseButton' => 'Good',
                        'leakTest' => 'Not Good',
                        'speechDiaphragm' => 'Good',
                        'harness' => 'Good',
                        'neckStrap' => 'Good',
                        'remarks' => '',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.scbaFaceMaskChecks.0.leakTestRemarks']);
    }

    public function test_inspection_report_accepts_scba_not_good_row_without_optional_photo(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-SCBA-OPTIONAL-PHOTO',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'SCBA Inspection',
                'location' => 'FRT',
                'scbaFaceMaskChecks' => [[
                    'location' => 'FRT',
                    'brand' => 'Drager',
                    'serialNo' => '02',
                    'visorCondition' => 'Good',
                    'ldvPort' => 'Good',
                    'ldvReleaseButton' => 'Good',
                    'leakTest' => 'Not Good',
                    'leakTestRemarks' => 'Seal leaks under pressure.',
                    'leakTestPhotos' => [],
                    'speechDiaphragm' => 'Good',
                    'harness' => 'Good',
                    'neckStrap' => 'Good',
                ]],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.scbaFaceMaskChecks.0.leakTestPhotos', []);
    }

    public function test_inspection_report_rejects_invalid_high_angle_check_payload(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-BAD-HA',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'High Angle Rescue Equipment Inspection',
                'location' => 'Response Kit #1',
                'highAngleChecks' => [
                    [
                        'mainLocation' => 'Response Kit #1',
                        'equipment' => 'Heavy Duty Organizer Bag',
                        'condition' => 'Broken',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.highAngleChecks.0.condition']);
    }

    public function test_inspection_report_rejects_high_angle_not_good_rows_without_remarks(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-HA-REMARKS',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'High Angle Rescue Equipment Inspection',
                'location' => 'Response Kit #1',
                'mainLocation' => 'Response Kit #1',
                'highAngleInspectedBy' => 'Inspector Rope',
                'highAngleInspectionDate' => '2026-06-28',
                'highAngleChecks' => [
                    [
                        'rowNumber' => '3',
                        'mainLocation' => 'Response Kit #1',
                        'location' => 'Heavy Duty Organizer Bag',
                        'subLocation' => 'Main Compartment',
                        'equipment' => 'Locking Carabiner - CT - Steel - S',
                        'quantity' => '10',
                        'condition' => 'Not Good',
                        'remarks' => '',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.highAngleChecks.0.conditionRemarks']);
    }

    public function test_inspection_report_accepts_high_angle_not_good_row_without_optional_photo(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-HA-OPTIONAL-PHOTO',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'High Angle Rescue Equipment Inspection',
                'location' => 'Response Kit #1',
                'mainLocation' => 'Response Kit #1',
                'highAngleInspectedBy' => 'Inspector Rope',
                'highAngleInspectionDate' => '2026-06-28',
                'highAngleChecks' => [[
                    'id' => 'response-kit-1:3',
                    'rowNumber' => '3',
                    'mainLocation' => 'Response Kit #1',
                    'equipment' => 'Locking Carabiner',
                    'quantity' => '10',
                    'condition' => 'Not Good',
                    'conditionRemarks' => 'Gate spring is sticking.',
                    'conditionPhotos' => [],
                ]],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.highAngleChecks.0.conditionPhotos', []);
    }

    public function test_inspection_report_rejects_high_angle_reports_without_session_meta(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $basePayload = [
            'incidentType' => 'High Angle Rescue Equipment Inspection',
            'location' => 'Response Kit #1',
            'mainLocation' => 'Response Kit #1',
            'highAngleChecks' => [
                [
                    'id' => 'response-kit-1:1',
                    'rowNumber' => '1',
                    'mainLocation' => 'Response Kit #1',
                    'location' => 'N/A',
                    'subLocation' => 'N/A',
                    'equipment' => 'Heavy Duty Organizer Bag',
                    'quantity' => '1',
                    'condition' => 'Good',
                    'remarks' => '',
                ],
            ],
        ];

        $missingInspector = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-HA-META',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $basePayload,
        ]);

        $missingInspector->assertStatus(422);
        $missingInspector->assertJsonMissingValidationErrors([
            'payload.highAngleInspectedBy',
        ]);
        $missingInspector->assertJsonValidationErrors([
            'payload.highAngleInspectionDate',
        ]);

        $missingDate = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-HA-META-DATE',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => array_merge($basePayload, [
                'highAngleInspectedBy' => 'Inspector Rope',
            ]),
        ]);

        $missingDate->assertStatus(422);
        $missingDate->assertJsonValidationErrors([
            'payload.highAngleInspectionDate',
        ]);
    }

    public function test_inspection_report_rejects_empty_high_angle_submission(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-HA-EMPTY',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'High Angle Rescue Equipment Inspection',
                'location' => 'Response Kit #1',
                'mainLocation' => 'Response Kit #1',
                'highAngleInspectedBy' => 'Inspector Rope',
                'highAngleInspectionDate' => '2026-06-28',
                'highAngleChecks' => [],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['payload.highAngleChecks']);
    }

    public function test_inspection_report_rejects_invalid_checklist_payload(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-BAD-CHECKLIST',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Checklist',
                'description' => 'Checklist payload guardrail',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
                'checklist' => [
                    ['id' => 'missing-label'],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.checklist.0.label']);
    }

    public function test_inspection_report_rejects_invalid_hydraulic_check_payload(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-BAD-HYD',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Hydraulic Rescue Tools Inspection',
                'location' => 'FRT',
                'description' => 'Hydraulic payload guardrail',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
                'hydraulicChecks' => [
                    [
                        'location' => 'FRT',
                        'equipment' => 'Hydraulic Pump Motor 1',
                        'physicalCondition' => 'Broken',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.hydraulicChecks.0.physicalCondition']);
    }

    public function test_inspection_report_accepts_hydraulic_defect_without_photo_evidence(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-HYD-EVIDENCE',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Hydraulic Rescue Tools Inspection',
                'location' => 'FRT',
                'description' => 'Hydraulic payload guardrail',
                'hydraulicChecks' => [
                    [
                        'location' => 'FRT',
                        'equipment' => 'Hydraulic Pump Motor 1',
                        'physicalCondition' => 'OK',
                        'mechanicalCondition' => 'OK',
                        'noLeakage' => 'OK',
                        'functionTest' => 'Defect',
                        'functionTestRemarks' => 'Slow response during test.',
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.hydraulicChecks.0.functionTestPhotos', []);
    }

    public function test_inspection_report_rejects_invalid_er_aux_check_payload(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-BAD-ERAUX',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'ER Aux Equipment Inspection',
                'location' => 'Store',
                'erAuxChecks' => [
                    [
                        'location' => 'Store',
                        'equipment' => 'Chainsaw',
                        'condition' => 'Broken',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.erAuxChecks.0.condition']);
    }

    public function test_inspection_report_accepts_er_aux_defect_without_photo_evidence(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-ERAUX-EVIDENCE',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'ER Aux Equipment Inspection',
                'location' => 'Store',
                'erAuxChecks' => [
                    [
                        'location' => 'Store',
                        'equipment' => 'Chainsaw',
                        'quantity' => '1',
                        'condition' => 'Defect',
                        'defectRemarks' => 'Pull cord jammed.',
                    ],
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.erAuxChecks.0.defectPhotos', []);
    }

    public function test_incomplete_inspection_type_rows_are_accepted_as_drafts(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $frtRow = FrtDailyReference::dailyRows()[0];
        $payloads = [
            'FRT daily' => [
                'incidentType' => 'FRT Daily Inspection',
                'frtDailyChecks' => [[
                    ...$frtRow,
                    'status' => 'Issue',
                    'remarks' => '',
                    'photos' => [],
                ]],
                'frtOneOffChecks' => [],
            ],
            'High Angle' => [
                'incidentType' => 'High Angle Rescue Equipment Inspection',
                'highAngleChecks' => [[
                    'id' => 'draft-high-angle-1',
                    'mainLocation' => 'Response Kit #1',
                    'equipment' => 'Carabiner',
                    'condition' => 'Not Good',
                    'conditionRemarks' => '',
                    'conditionPhotos' => [],
                ]],
            ],
            'SCBA' => [
                'incidentType' => 'SCBA Inspection',
                'scbaFaceMaskChecks' => [[
                    'location' => 'FRT',
                    'brand' => 'Drager',
                    'serialNo' => 'DRAFT-01',
                    'leakTest' => 'Not Good',
                    'leakTestRemarks' => '',
                    'leakTestPhotos' => [],
                ]],
            ],
            'custom SCBA' => [
                'incidentType' => 'SCBA Inspection',
                'scbaCustomSections' => [[
                    'title' => 'Regulator',
                    'fields' => [
                        ['key' => 'purgeValve', 'label' => 'Purge Valve'],
                    ],
                    'rows' => [[
                        'location' => 'FRT',
                        'serialNo' => 'DRAFT-CUSTOM-01',
                        'purgeValve' => 'Not Good',
                        'purgeValveRemarks' => '',
                        'purgeValvePhotos' => [],
                    ]],
                ]],
            ],
            'Hydraulic' => [
                'incidentType' => 'Hydraulic Rescue Tools Inspection',
                'hydraulicChecks' => [[
                    'id' => 'draft-hydraulic-1',
                    'equipment' => 'Hydraulic Pump',
                    'physicalCondition' => 'Defect',
                    'physicalConditionRemarks' => '',
                    'physicalConditionPhotos' => [],
                ]],
            ],
            'ER Aux' => [
                'incidentType' => 'ER Aux Equipment Inspection',
                'erAuxChecks' => [[
                    'id' => 'draft-er-aux-1',
                    'equipment' => 'Chainsaw',
                    'quantity' => '',
                    'condition' => 'Defect',
                    'defectRemarks' => '',
                    'defectPhotos' => [],
                ]],
            ],
        ];

        foreach ($payloads as $name => $payload) {
            $response = $this->postJson('/api/reports/draft', [
                'draft_id' => 'partial-'.Str::slug($name),
                'report_type' => 'inspection',
                'payload' => $payload,
            ]);

            $this->assertContains(
                $response->status(),
                [200, 201],
                "{$name} partial draft was rejected: {$response->getContent()}",
            );
        }
    }

    public function test_inspection_checklist_summary_counts_and_filters_reports(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $this->postJson('/api/reports', [
            'display_id' => 'INS-SUMMARY-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Pump House',
                'location' => 'Zone Summary',
                'description' => 'Checklist summary report',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
                'checklist' => [
                    [
                        'id' => 'pump-house:pressure-checked',
                        'label' => 'Pressure checked',
                        'inspectionType' => 'Pump House',
                        'selected' => true,
                    ],
                    [
                        'id' => 'pump-house:access-clear',
                        'label' => 'Access clear',
                        'inspectionType' => 'Pump House',
                        'selected' => true,
                    ],
                ],
            ],
        ])->assertCreated();

        $this->postJson('/api/reports', [
            'display_id' => 'INS-SUMMARY-002',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Housekeeping',
                'location' => 'Zone Summary',
                'description' => 'Legacy no checklist report',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
            ],
        ])->assertCreated();

        $summary = $this->getJson('/api/reports/inspection/checklist-summary');
        $summary->assertOk();
        $summary->assertJsonPath('data.totalReports', 2);
        $summary->assertJsonPath('data.withChecklist', 1);
        $summary->assertJsonPath('data.withoutChecklist', 1);
        $summary->assertJsonPath('data.items.0.id', 'pump-house:access-clear');

        $filtered = $this->getJson('/api/reports/inspection/checklist-summary?has_checklist=true&checklist_item=pump-house:pressure-checked');
        $filtered->assertOk();
        $filtered->assertJsonPath('data.totalReports', 1);
        $filtered->assertJsonPath('data.items.0.label', 'Pressure checked');

        $typeFiltered = $this->getJson('/api/reports/inspection/checklist-summary?inspection_type=Housekeeping&has_checklist=false');
        $typeFiltered->assertOk();
        $typeFiltered->assertJsonPath('data.totalReports', 1);
        $typeFiltered->assertJsonPath('data.withoutChecklist', 1);
    }

    public function test_inspection_update_conflict_returns_current_report_snapshot(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $create = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-CONFLICT',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Conflict',
                'description' => 'Original',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
            ],
        ]);
        $create->assertCreated();
        $reportUid = (string) $create->json('data.id');

        $firstUpdate = $this->putJson("/api/reports/{$reportUid}", [
            'version' => 1,
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Conflict',
                'description' => 'Server changed',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
            ],
        ]);
        $firstUpdate->assertOk();

        $conflict = $this->putJson("/api/reports/{$reportUid}", [
            'version' => 1,
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Conflict',
                'description' => 'Offline local edit',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
            ],
        ]);

        $conflict->assertStatus(409);
        $conflict->assertJsonPath('code', 'REPORT_VERSION_CONFLICT');
        $conflict->assertJsonPath('currentReport.description', 'Server changed');
    }

    public function test_inspection_report_create_and_update_preserve_client_submitted_timestamp(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $createdAt = Carbon::parse('2026-07-08T21:07:00+08:00');
        $updatedAt = Carbon::parse('2026-07-08T21:15:00+08:00');

        $create = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-TIMESTAMP',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Timestamp',
                'description' => 'Client timestamp should be preserved.',
                'submittedAt' => $createdAt->toIso8601String(),
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
            ],
        ]);
        $create->assertCreated();
        $reportUid = (string) $create->json('data.id');
        $createdReport = Report::query()->where('report_uid', $reportUid)->firstOrFail();
        $this->assertTrue($createdReport->submitted_at->equalTo($createdAt));
        $this->assertTrue(Carbon::parse((string) $create->json('data.submittedAt'))->equalTo($createdAt));

        $update = $this->putJson("/api/reports/{$reportUid}", [
            'version' => 1,
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone Timestamp',
                'description' => 'Client timestamp should still be preserved.',
                'submittedAt' => $updatedAt->toIso8601String(),
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'ok',
                        'url' => $this->makeImageDataUrl(16),
                    ],
                ],
            ],
        ]);
        $update->assertOk();
        $updatedReport = $createdReport->refresh();
        $this->assertTrue($updatedReport->submitted_at->equalTo($updatedAt));
        $this->assertTrue(Carbon::parse((string) $update->json('data.submittedAt'))->equalTo($updatedAt));
    }

    public function test_inspection_report_rejects_non_data_url_photo(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-002',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone B',
                'description' => 'Payload URL guardrail',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'invalid remote url',
                        'url' => 'https://example.test/photo.jpg',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.photos.0.url']);
    }

    public function test_inspection_report_rejects_non_data_url_finding_photo_from_legacy_issues_alias(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-FINDING-PHOTO',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'General Inspection',
                'location' => 'Zone B',
                'description' => 'Legacy finding photo guardrail.',
                'issues' => [[
                    'description' => 'Blocked access.',
                    'photos' => [[
                        'id' => 'finding-photo-1',
                        'description' => 'Untrusted finding photo.',
                        'url' => 'https://example.test/finding-photo.jpg',
                    ]],
                ]],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'payload.inspectionIssues.0.photos.0.url',
        ]);
    }

    public function test_inspection_report_rejects_non_data_url_standard_scba_row_photo(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-GUARD-SCBA-PHOTO',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'SCBA Inspection',
                'location' => 'FRT',
                'scbaInspectionDate' => '2026-07-27',
                'scbaBackPlateChecks' => [[
                    'id' => 'back-plate-guardrail',
                    'location' => 'FRT',
                    'brand' => 'MSA',
                    'serialNo' => 'BP-GUARD-01',
                    'photos' => [[
                        'id' => 'scba-photo-1',
                        'description' => 'Untrusted SCBA overview photo.',
                        'url' => 'https://example.test/scba-photo.jpg',
                    ]],
                ]],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'payload.scbaBackPlateChecks.0.photos.0.url',
        ]);
    }

    public function test_inspection_draft_rejects_non_data_url_photo(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantInspectionPermission($user);
        $this->actingAs($user);

        $response = $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => [
                'incidentType' => 'Routine Inspection',
                'location' => 'Zone C',
                'description' => 'Draft URL guardrail',
                'photos' => [
                    [
                        'id' => 'photo-1',
                        'description' => 'invalid remote url',
                        'url' => 'https://example.test/photo.jpg',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.photos.0.url']);
    }

    private function makeImageDataUrl(int $bytes): string
    {
        $binary = str_repeat('A', max(1, $bytes));

        return 'data:image/png;base64,'.base64_encode($binary);
    }

    private function frtPayload(bool $useSnakeCase = false): array
    {
        $dailyChecks = array_map(function (array $row): array {
            $isReading = ($row['rowKind'] ?? 'status') === 'reading';
            $status = '';
            $readingValue = '';
            $remarks = '';

            if ($row['id'] === 'daily:fire-truck:90') {
                $status = 'Issue';
                $remarks = 'Fuel gauge indicator lagging.';
            } elseif ($row['id'] === 'daily:fire-truck:91') {
                $readingValue = '123456';
            } elseif ($row['id'] === 'daily:fire-truck:92') {
                $readingValue = '85';
            } elseif (! $isReading) {
                $status = 'Checked';
            }

            return [
                'id' => $row['id'],
                'rowNumber' => $row['rowNumber'],
                'mainLocation' => $row['mainLocation'],
                'location' => $row['location'],
                'equipment' => $row['equipment'],
                'quantity' => $row['quantity'],
                'rowKind' => $row['rowKind'],
                'status' => $status,
                'readingValue' => $readingValue,
                'remarks' => $remarks,
                'photos' => $row['id'] === 'daily:fire-truck:90'
                    ? [[
                        'id' => 'frt-daily-photo-1',
                        'fileName' => 'frt-daily-photo.png',
                        'description' => 'Fuel gauge issue evidence.',
                        'url' => $this->makeImageDataUrl(128),
                    ]]
                    : [],
                'additionalNotes' => $row['id'] === 'daily:fire-truck:91'
                    ? 'Reading verified with driver.'
                    : '',
                'additionalPhotos' => $row['id'] === 'daily:fire-truck:91'
                    ? [[
                        'id' => 'frt-reading-additional-photo-1',
                        'fileName' => 'frt-reading-additional-photo.png',
                        'description' => 'Reading confirmation photo.',
                        'url' => $this->makeImageDataUrl(128),
                    ]]
                    : [],
            ];
        }, FrtDailyReference::dailyRows());

        $oneOffChecks = array_map(function (array $row): array {
            $isIssue = $row['id'] === 'one-off:fire-truck:16';

            return [
                'id' => $row['id'],
                'rowNumber' => $row['rowNumber'],
                'mainLocation' => $row['mainLocation'],
                'location' => $row['location'],
                'equipment' => $row['equipment'],
                'condition' => $isIssue ? 'Not Good' : 'Good',
                'remarks' => $isIssue ? 'Siren mute switch sticking.' : '',
                'additionalNotes' => $isIssue ? 'Retest scheduled after repair.' : '',
                'additionalPhotos' => $isIssue
                    ? [[
                        'id' => 'frt-one-off-additional-photo-1',
                        'fileName' => 'frt-one-off-additional-photo.png',
                        'description' => 'Siren panel additional photo.',
                        'url' => $this->makeImageDataUrl(128),
                    ]]
                    : [],
                'photos' => $isIssue
                    ? [[
                        'id' => 'frt-one-off-photo-1',
                        'fileName' => 'frt-one-off-photo.png',
                        'description' => 'Siren switch issue evidence.',
                        'url' => $this->makeImageDataUrl(128),
                    ]]
                    : [],
            ];
        }, FrtDailyReference::oneOffRows());

        $payload = [
            'incidentType' => 'FRT Daily Inspection',
            'location' => 'FIRE TRUCK',
            'selectedLocation' => 'FIRE TRUCK',
            'mainLocation' => 'FIRE TRUCK',
            'description' => 'FRT daily inspection checked for FIRE TRUCK.',
            'photos' => [],
            'frtInspectedBy' => 'Inspector Truck',
            'frtInspectionDate' => '2026-06-28',
            'frtShift' => 'Day',
            'frtTruckReference' => [
                'plateNo' => 'AJG9555',
                'roadTaxExpiry' => '13/02/2026',
                'insuranceExpiry' => '13/02/2026',
                'puspakomExpiry' => '19/02/2026',
            ],
            'frtDailyRemarks' => 'Truck ready for service.',
            'frtOneOffRemarks' => 'One-off defects logged.',
            'frtDailyChecks' => $dailyChecks,
            'frtOneOffChecks' => $oneOffChecks,
        ];

        if (! $useSnakeCase) {
            return $payload;
        }

        return [
            'incidentType' => $payload['incidentType'],
            'location' => $payload['location'],
            'selectedLocation' => $payload['selectedLocation'],
            'mainLocation' => $payload['mainLocation'],
            'description' => $payload['description'],
            'photos' => $payload['photos'],
            'frt_inspected_by' => $payload['frtInspectedBy'],
            'frt_inspection_date' => $payload['frtInspectionDate'],
            'frt_shift' => $payload['frtShift'],
            'frt_truck_reference' => [
                'plate_no' => 'AJG9555',
                'road_tax_expiry' => '13/02/2026',
                'insurance_expiry' => '13/02/2026',
                'puspakom_expiry' => '19/02/2026',
            ],
            'frt_daily_remarks' => $payload['frtDailyRemarks'],
            'frt_one_off_remarks' => $payload['frtOneOffRemarks'],
            'frt_daily_checks' => array_map(
                fn (array $row): array => [
                    'id' => $row['id'],
                    'row_number' => $row['rowNumber'],
                    'main_location' => $row['mainLocation'],
                    'location' => $row['location'],
                    'equipment' => $row['equipment'],
                    'quantity' => $row['quantity'],
                    'row_kind' => $row['rowKind'],
                    'status' => $row['status'],
                    'reading_value' => $row['readingValue'],
                    'remarks' => $row['remarks'],
                    'photos' => $row['photos'],
                    'additional_notes' => $row['additionalNotes'],
                    'additional_photos' => $row['additionalPhotos'],
                ],
                $dailyChecks
            ),
            'frt_one_off_checks' => array_map(
                fn (array $row): array => [
                    'id' => $row['id'],
                    'row_number' => $row['rowNumber'],
                    'main_location' => $row['mainLocation'],
                    'location' => $row['location'],
                    'equipment' => $row['equipment'],
                    'condition' => $row['condition'],
                    'remarks' => $row['remarks'],
                    'photos' => $row['photos'],
                    'additional_notes' => $row['additionalNotes'],
                    'additional_photos' => $row['additionalPhotos'],
                ],
                $oneOffChecks
            ),
        ];
    }

    private function createManagedInspectionPhoto(User $user, string $publicId): ReportMedia
    {
        return ReportMedia::query()->create([
            'public_id' => $publicId,
            'client_upload_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'module' => 'inspection',
            'disk' => 'local',
            'storage_path' => 'report-media/'.$publicId.'.jpg',
            'thumbnail_path' => 'report-media/'.$publicId.'-thumb.jpg',
            'original_name' => 'camera.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 128 * 1024,
            'thumbnail_size_bytes' => 16 * 1024,
            'width' => 1200,
            'height' => 900,
            'thumbnail_width' => 320,
            'thumbnail_height' => 240,
            'checksum_sha256' => hash('sha256', $publicId),
            'thumbnail_checksum_sha256' => hash('sha256', $publicId.'-thumb'),
        ]);
    }

    private function grantInspectionPermission(User $user, string $roleName = 'Inspection Guardrail Tester'): void
    {
        $role = Role::query()->firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);
        foreach (['reports.inspection.view', 'reports.inspection.conduct'] as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        $user->assignRole($role);
        $this->workflowTeam ??= Team::factory()->create([
            'name' => 'Inspection Guardrail Workflow Team',
        ]);
        UserRoleAssignment::query()->firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => RoleCatalog::SITE,
            'team_id' => $this->workflowTeam->id,
        ], [
            'is_primary' => true,
        ]);
    }
}
