<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionPayloadGuardrailsTest extends TestCase
{
    use RefreshDatabase;

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

    private function grantInspectionPermission(User $user): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => 'reports.inspection.view',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Inspection Guardrail Tester',
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);
    }
}
