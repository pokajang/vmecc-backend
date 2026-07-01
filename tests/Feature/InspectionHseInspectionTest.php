<?php

namespace Tests\Feature;

use App\Models\InspectionCheckRow;
use App\Models\Report;
use App\Models\ReportDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionHseInspectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_hse_area_satisfactory_payload_is_accepted_without_photos(): void
    {
        $this->actingAsInspectionUser();

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-AREA-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $this->areaSatisfactoryPayload(),
        ]);

        $response->assertCreated();

        $report = Report::query()->where('display_id', 'INS-HSE-AREA-001')->firstOrFail();
        $this->assertSame(['areaSatisfactory'], $report->payload['hseSelections'] ?? null);
        $this->assertSame('Area housekeeping is satisfactory.', $report->payload['hseAreaConditionRemarks'] ?? null);
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'inspection_type_key' => 'health-safety-environment-inspection',
            'check_key' => 'area-satisfactory',
            'check_value' => 'Area Satisfactory',
            'has_defect' => false,
            'source_payload_key' => 'hseSelections',
        ]);
    }

    public function test_hse_finding_payload_requires_selected_details_and_severity(): void
    {
        $this->actingAsInspectionUser();
        $payload = $this->findingPayload();
        unset($payload['hseSeverity'], $payload['hseUnsafeConditionDetails']);

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-FINDING-INVALID',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.hseSeverity']);

        $payload['hseSeverity'] = 'Critical';
        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-FINDING-INVALID-DETAIL',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $payload,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payload.hseUnsafeConditionDetails']);
    }

    public function test_hse_finding_payload_creates_analytics_rows_for_selected_findings(): void
    {
        $this->actingAsInspectionUser();

        $response = $this->postJson('/api/reports', [
            'display_id' => 'INS-HSE-FINDING-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => $this->findingPayload(),
        ]);

        $response->assertCreated();
        $report = Report::query()->where('display_id', 'INS-HSE-FINDING-001')->firstOrFail();
        $this->assertSame(2, InspectionCheckRow::query()->where('report_id', $report->id)->count());
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'check_key' => 'unsafe-act',
            'check_value' => 'Critical',
            'has_defect' => true,
            'equipment_source' => 'report',
        ]);
        $this->assertDatabaseHas('inspection_check_rows', [
            'report_id' => $report->id,
            'check_key' => 'unsafe-condition',
            'check_value' => 'Critical',
            'has_defect' => true,
            'equipment_source' => 'report',
        ]);
    }

    public function test_hse_draft_persists_incomplete_payload_safely(): void
    {
        $this->actingAsInspectionUser();

        $response = $this->postJson('/api/reports/draft', [
            'report_type' => 'inspection',
            'payload' => [
                'incidentType' => 'Health Safety Environment Inspection',
                'location' => 'Zone A',
                'mainLocation' => 'Zone A',
                'hse_selections' => ['Unsafe Act'],
                'hse_unsafe_act_details' => 'Draft unsafe act note.',
                'hse_severity' => '',
                'photos' => [],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.payload.hseSelections.0', 'unsafeAct');
        $response->assertJsonPath('data.payload.hseUnsafeActDetails', 'Draft unsafe act note.');
        $response->assertJsonPath('data.payload.hseSeverity', '');
        $this->assertSame(0, InspectionCheckRow::query()->count());

        $draft = ReportDraft::query()->where('report_type', 'inspection')->firstOrFail();
        $this->assertSame(['unsafeAct'], $draft->payload['hseSelections'] ?? null);
        $this->assertArrayNotHasKey('hse_selections', $draft->payload);
    }

    private function areaSatisfactoryPayload(): array
    {
        return [
            'incidentType' => 'Health Safety Environment Inspection',
            'location' => 'Zone A',
            'selectedLocation' => 'Zone A',
            'mainLocation' => 'Zone A',
            'description' => 'HSE inspection for Zone A: Area Satisfactory.',
            'photos' => [],
            'hseInspectedBy' => 'Inspector HSE',
            'hseInspectionDate' => '2026-06-29',
            'hseSelections' => ['areaSatisfactory'],
            'hseAreaConditionRemarks' => 'Area housekeeping is satisfactory.',
        ];
    }

    private function findingPayload(): array
    {
        return [
            'incidentType' => 'Health Safety Environment Inspection',
            'location' => 'Zone A > Dock',
            'selectedLocation' => 'Zone A > Dock',
            'mainLocation' => 'Zone A',
            'subLocation' => 'Dock',
            'description' => 'HSE inspection found unsafe act and unsafe condition.',
            'photos' => [],
            'hseInspectedBy' => 'Inspector HSE',
            'hseInspectionDate' => '2026-06-29',
            'hseSelections' => ['unsafeAct', 'unsafeCondition'],
            'hseUnsafeActDetails' => 'Worker crossed active barricade.',
            'hseUnsafeConditionDetails' => 'Open trench missing edge protection.',
            'hseSeverity' => 'Critical',
            'hseImmediateAction' => 'Stopped work and reinstated barricade.',
            'hseCorrectiveAction' => 'Brief contractor team before restart.',
            'hseResponsiblePerson' => 'Area Supervisor',
            'hseTargetDate' => '2026-06-30',
        ];
    }

    private function actingAsInspectionUser(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->grantPermission($user, 'reports.inspection.view');
        $this->actingAs($user);

        return $user;
    }

    private function grantPermission(User $user, string $permissionName): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'HSE Inspection Tester',
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $user->assignRole($role);
    }
}
