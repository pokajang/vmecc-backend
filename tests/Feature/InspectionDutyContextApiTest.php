<?php

namespace Tests\Feature;

use App\Models\CustomShift;
use App\Models\InspectionDutyConfirmation;
use App\Models\Roster;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\InspectionDutyConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InspectionDutyContextApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_context_uses_unique_published_roster_assignment(): void
    {
        Carbon::setTestNow('2026-07-12 02:00:00 UTC');
        [$user, $team] = $this->assignedUser();

        $this->actingAs($user)->getJson('/api/inspection/duty-context')
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.confidence', 'high')
            ->assertJsonPath('data.teamId', $team->id)
            ->assertJsonPath('data.shiftKey', 'day')
            ->assertJsonPath('data.siteTimezone', 'Asia/Kuala_Lumpur')
            ->assertJsonPath('data.allowedActions.submit', true);
    }

    public function test_unmatched_user_cannot_receive_confirmation(): void
    {
        Carbon::setTestNow('2026-07-12 02:00:00 UTC');
        $user = $this->inspectionUser();
        $contextVersion = $this->actingAs($user)->getJson('/api/inspection/duty-context')
            ->assertOk()
            ->assertJsonPath('data.status', 'unmatched')
            ->json('data.contextVersion');

        $this->actingAs($user)->postJson('/api/inspection/duty-context/confirm', [
            'operation' => 'submit',
            'contextVersion' => $contextVersion,
        ])->assertUnprocessable()->assertJsonPath('code', 'duty_context_unmatched');
    }

    public function test_overnight_context_uses_previous_roster_date_and_exclusive_boundary(): void
    {
        $user = $this->inspectionUser();
        $dayTeam = Team::query()->create(['name' => 'Day Team', 'status' => 'On Duty']);
        $nightTeam = Team::query()->create(['name' => 'Night Team', 'status' => 'On Duty']);
        $this->membership($user, $dayTeam, true);
        $this->membership($user, $nightTeam, false);
        foreach ([[$dayTeam, 'day'], [$nightTeam, 'night']] as [$team, $shift]) {
            Roster::query()->create([
                'date' => '2026-07-12',
                'shift' => $shift,
                'team_id' => $team->id,
                'status' => 'published',
                'created_by' => $user->id,
                'published_by' => $user->id,
                'published_at' => '2026-07-11 12:00:00',
            ]);
        }

        Carbon::setTestNow('2026-07-12 11:00:00 UTC'); // 19:00 site time.
        $this->actingAs($user)->getJson('/api/inspection/duty-context')
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.teamId', $nightTeam->id)
            ->assertJsonPath('data.shiftKey', 'night');

        Carbon::setTestNow('2026-07-12 22:00:00 UTC'); // 06:00 the following site day.
        $this->actingAs($user)->getJson('/api/inspection/duty-context')
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.teamId', $nightTeam->id)
            ->assertJsonPath('data.shiftKey', 'night');
    }

    public function test_historical_membership_does_not_shadow_active_membership_for_same_team(): void
    {
        Carbon::setTestNow('2026-07-12 02:00:00 UTC');
        [$user, $team] = $this->assignedUser();
        TeamMember::query()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'name' => $user->name,
            'role' => 'Former Inspector',
            'is_primary' => false,
            'started_at' => '2025-01-01',
            'ended_at' => '2025-12-31',
        ]);

        $this->actingAs($user)->getJson('/api/inspection/duty-context')
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.teamId', $team->id);
    }

    public function test_confirmation_is_hashed_bound_and_one_time(): void
    {
        Carbon::setTestNow('2026-07-12 02:00:00 UTC');
        [$user] = $this->assignedUser();
        $contextVersion = $this->actingAs($user)->getJson('/api/inspection/duty-context')->json('data.contextVersion');
        $response = $this->actingAs($user)->postJson('/api/inspection/duty-context/confirm', [
            'operation' => 'submit',
            'contextVersion' => $contextVersion,
            'formId' => 'general-inspection',
            'recordId' => 'report-123',
            'idempotencyKey' => 'submission-123',
        ])->assertCreated();
        $token = $response->json('data.dutyConfirmationToken');
        $stored = InspectionDutyConfirmation::query()->firstOrFail();

        $this->assertNotSame($token, $stored->token_hash);
        $this->assertSame(hash('sha256', $token), $stored->token_hash);

        config()->set('inspection_duty.enforcement_enabled', true);
        $service = app(InspectionDutyConfirmationService::class);
        $service->consume(
            $this->mutationRequest($user, $token, ['submission_key' => 'submission-123']),
            'submit',
            'report-123',
            'general-inspection',
        );
        $this->assertNotNull($stored->refresh()->consumed_at);

        try {
            $service->consume(
                $this->mutationRequest($user, $token, ['submission_key' => 'submission-123']),
                'submit',
                'report-123',
                'general-inspection',
            );
            $this->fail('Expected a consumed confirmation to be rejected.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(412, $exception->getResponse()->getStatusCode());
            $this->assertSame('duty_confirmation_invalid', $exception->getResponse()->getData(true)['code']);
        }
    }

    public function test_confirmation_rejects_a_different_idempotency_key(): void
    {
        Carbon::setTestNow('2026-07-12 02:00:00 UTC');
        [$user] = $this->assignedUser();
        $contextVersion = $this->actingAs($user)->getJson('/api/inspection/duty-context')->json('data.contextVersion');
        $token = $this->actingAs($user)->postJson('/api/inspection/duty-context/confirm', [
            'operation' => 'submit',
            'contextVersion' => $contextVersion,
            'formId' => 'general-inspection',
            'recordId' => 'report-123',
            'idempotencyKey' => 'submission-123',
        ])->assertCreated()->json('data.dutyConfirmationToken');
        config()->set('inspection_duty.enforcement_enabled', true);

        try {
            app(InspectionDutyConfirmationService::class)->consume(
                $this->mutationRequest($user, $token, ['submission_key' => 'different-submission']),
                'submit',
                'report-123',
            );
            $this->fail('Expected an idempotency binding mismatch to be rejected.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(412, $exception->getResponse()->getStatusCode());
            $this->assertSame('duty_confirmation_invalid', $exception->getResponse()->getData(true)['code']);
        }

        $this->assertNull(InspectionDutyConfirmation::query()->firstOrFail()->consumed_at);
    }

    public function test_ambiguous_context_requires_explicit_candidate(): void
    {
        Carbon::setTestNow('2026-07-12 02:00:00 UTC');
        $user = $this->inspectionUser();
        foreach (['Alpha overlap', 'Bravo overlap'] as $index => $name) {
            $team = Team::query()->create(['name' => $name, 'status' => 'On Duty']);
            $this->membership($user, $team, $index === 0);
            CustomShift::query()->create(['name' => $name, 'start' => '08:00', 'end' => '12:00', 'sort_order' => $index]);
            Roster::query()->create([
                'date' => '2026-07-12',
                'shift' => $name,
                'team_id' => $team->id,
                'status' => 'published',
                'created_by' => $user->id,
                'published_by' => $user->id,
                'published_at' => now(),
            ]);
        }

        $context = $this->actingAs($user)->getJson('/api/inspection/duty-context')
            ->assertOk()
            ->assertJsonPath('data.status', 'ambiguous')
            ->assertJsonCount(2, 'data.candidates')
            ->json('data');

        $this->actingAs($user)->postJson('/api/inspection/duty-context/confirm', [
            'operation' => 'submit',
            'contextVersion' => $context['contextVersion'],
            'formId' => 'general-inspection',
            'recordId' => 'report-ambiguous',
            'idempotencyKey' => 'submission-ambiguous',
        ])->assertUnprocessable()->assertJsonPath('code', 'duty_context_ambiguous');

        $candidate = $context['candidates'][0];
        $this->actingAs($user)->postJson('/api/inspection/duty-context/confirm', [
            'operation' => 'submit',
            'contextVersion' => $context['contextVersion'],
            'teamId' => $candidate['teamId'],
            'shiftKey' => $candidate['shiftKey'],
            'formId' => 'general-inspection',
            'recordId' => 'report-ambiguous',
            'idempotencyKey' => 'submission-ambiguous',
        ])->assertCreated();
    }

    public function test_enforced_report_submission_requires_and_consumes_bound_confirmation(): void
    {
        Carbon::setTestNow('2026-07-12 02:00:00 UTC');
        [$user] = $this->assignedUser();
        config()->set('inspection_duty.enforcement_enabled', true);
        $payload = $this->reportPayload('report-duty-123', 'submission-duty-123');

        $this->actingAs($user)->postJson('/api/reports', $payload)
            ->assertStatus(428)
            ->assertJsonPath('code', 'duty_confirmation_required');

        $contextVersion = $this->actingAs($user)->getJson('/api/inspection/duty-context')->json('data.contextVersion');
        $token = $this->actingAs($user)->postJson('/api/inspection/duty-context/confirm', [
            'operation' => 'submit',
            'contextVersion' => $contextVersion,
            'formId' => 'general-inspection',
            'recordId' => 'report-duty-123',
            'idempotencyKey' => 'submission-duty-123',
        ])->assertCreated()->json('data.dutyConfirmationToken');

        $response = $this->actingAs($user)
            ->withHeader('X-Duty-Confirmation', $token)
            ->postJson('/api/reports', $payload)
            ->assertCreated()
            ->assertJsonPath('data.dutyContextStatus', 'assigned')
            ->assertJsonMissingPath('data.dutyContextSnapshot');

        $this->assertSame('report-duty-123', $response->json('data.id'));
        $this->assertDatabaseHas('reports', [
            'report_uid' => 'report-duty-123',
            'duty_context_status' => 'assigned',
        ]);
        $this->assertNotNull(InspectionDutyConfirmation::query()->firstOrFail()->consumed_at);
    }

    private function assignedUser(): array
    {
        $user = $this->inspectionUser();
        $team = Team::query()->create(['name' => 'Alpha', 'status' => 'On Duty']);
        $this->membership($user, $team, true);
        Roster::query()->create([
            'date' => '2026-07-12',
            'shift' => 'day',
            'team_id' => $team->id,
            'status' => 'published',
            'created_by' => $user->id,
            'published_by' => $user->id,
            'published_at' => now(),
        ]);

        return [$user, $team];
    }

    private function reportPayload(string $reportUid, string $submissionKey): array
    {
        return [
            'report_uid' => $reportUid,
            'submission_key' => $submissionKey,
            'display_id' => 'INS-DUTY-001',
            'report_type' => 'inspection',
            'status' => 'Submitted',
            'payload' => [
                'incidentType' => 'General Inspection',
                'location' => 'Fire Rescue Tender (FRT)',
                'selectedLocation' => 'Fire Rescue Tender (FRT)',
                'mainLocation' => 'FRT',
                'description' => 'Duty context enforcement test.',
                'photos' => [],
                'checklist' => [[
                    'id' => 'general-inspection:duty-context',
                    'label' => 'Duty context check',
                    'inspectionType' => 'General Inspection',
                    'selected' => true,
                ]],
            ],
        ];
    }

    private function inspectionUser(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $permissions = collect(['reports.inspection.view', 'reports.inspection.conduct'])
            ->map(fn (string $name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        $role = Role::query()->firstOrCreate(['name' => 'Duty Context Tester', 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);

        return $user;
    }

    private function membership(User $user, Team $team, bool $primary): void
    {
        TeamMember::query()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'name' => $user->name,
            'role' => 'Inspector',
            'is_primary' => $primary,
            'started_at' => '2026-01-01',
        ]);
    }

    private function mutationRequest(User $user, string $token, array $input = []): Request
    {
        $request = Request::create('/api/reports/report-123', 'POST', $input);
        $request->headers->set('X-Duty-Confirmation', $token);
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
