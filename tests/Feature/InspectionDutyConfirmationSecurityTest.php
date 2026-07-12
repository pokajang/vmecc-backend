<?php

namespace Tests\Feature;

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

class InspectionDutyConfirmationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_submit_confirmation_requires_complete_binding(): void
    {
        [$user] = $this->assignedUser();
        $contextVersion = $this->contextVersion($user);

        foreach ([
            ['recordId' => null, 'formId' => 'general-inspection', 'idempotencyKey' => 'submit-1'],
            ['recordId' => 'report-1', 'formId' => null, 'idempotencyKey' => 'submit-1'],
            ['recordId' => 'report-1', 'formId' => 'general-inspection', 'idempotencyKey' => null],
        ] as $binding) {
            $this->actingAs($user)->postJson('/api/inspection/duty-context/confirm', array_filter([
                'operation' => 'submit',
                'contextVersion' => $contextVersion,
                ...$binding,
            ], fn ($value) => $value !== null))
                ->assertUnprocessable()
                ->assertJsonPath('code', 'duty_confirmation_binding_required');
        }

        $this->assertDatabaseCount('inspection_duty_confirmations', 0);
    }

    public function test_token_in_request_body_is_not_accepted(): void
    {
        [$user] = $this->assignedUser();
        $token = $this->issue($user);
        config()->set('inspection_duty.enforcement_enabled', true);
        $request = $this->mutationRequest($user, '', [
            'dutyConfirmationToken' => $token,
            'submission_key' => 'submit-1',
        ]);

        $this->assertHttpFailure(
            fn () => app(InspectionDutyConfirmationService::class)->consume(
                $request,
                'submit',
                'report-1',
                'general-inspection',
            ),
            428,
            'duty_confirmation_required',
        );
    }

    public function test_expired_token_is_rejected_without_consumption(): void
    {
        [$user] = $this->assignedUser();
        $token = $this->issue($user);
        InspectionDutyConfirmation::query()->firstOrFail()->update(['expires_at' => now()->subSecond()]);
        config()->set('inspection_duty.enforcement_enabled', true);

        $this->assertHttpFailure(
            fn () => $this->consume($user, $token),
            412,
            'duty_confirmation_expired',
        );
        $this->assertNull(InspectionDutyConfirmation::query()->firstOrFail()->consumed_at);
    }

    public function test_context_change_revokes_token_before_returning_mismatch(): void
    {
        [$user, , $roster] = $this->assignedUser();
        $token = $this->issue($user);
        $roster->update(['status' => 'draft']);
        config()->set('inspection_duty.enforcement_enabled', true);

        $this->assertHttpFailure(
            fn () => $this->consume($user, $token),
            412,
            'duty_context_changed',
        );
        $this->assertNotNull(InspectionDutyConfirmation::query()->firstOrFail()->revoked_at);
    }

    public function test_wrong_actor_operation_record_and_form_are_rejected(): void
    {
        [$user] = $this->assignedUser();
        $other = $this->inspectionUser('Other Inspector');
        $token = $this->issue($user);
        config()->set('inspection_duty.enforcement_enabled', true);
        $service = app(InspectionDutyConfirmationService::class);

        $cases = [
            [$this->mutationRequest($other, $token, ['submission_key' => 'submit-1']), 'submit', 'report-1', 'general-inspection'],
            [$this->mutationRequest($user, $token, ['submission_key' => 'submit-1']), 'approve', 'report-1', 'general-inspection'],
            [$this->mutationRequest($user, $token, ['submission_key' => 'submit-1']), 'submit', 'report-2', 'general-inspection'],
            [$this->mutationRequest($user, $token, ['submission_key' => 'submit-1']), 'submit', 'report-1', 'other-inspection'],
        ];
        foreach ($cases as [$request, $operation, $recordId, $formId]) {
            $this->assertHttpFailure(
                fn () => $service->consume($request, $operation, $recordId, $formId),
                412,
                'duty_confirmation_invalid',
            );
        }

        $this->assertNull(InspectionDutyConfirmation::query()->firstOrFail()->consumed_at);
    }

    public function test_tampered_binding_hash_is_rejected(): void
    {
        [$user] = $this->assignedUser();
        $token = $this->issue($user);
        InspectionDutyConfirmation::query()->firstOrFail()->update([
            'context_hash' => str_repeat('0', 64),
        ]);
        config()->set('inspection_duty.enforcement_enabled', true);

        $this->assertHttpFailure(
            fn () => $this->consume($user, $token),
            412,
            'duty_confirmation_invalid',
        );
    }

    public function test_prune_command_removes_old_secrets_but_preserves_audit_events(): void
    {
        [$user] = $this->assignedUser();
        $this->issue($user);
        InspectionDutyConfirmation::query()->firstOrFail()->update([
            'expires_at' => now()->subDays(8),
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inspection_duty_confirmation_issued']);

        $this->artisan('inspection:prune-duty-confirmations --days=7')
            ->expectsOutput('Pruned 1 inspection duty confirmations.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('inspection_duty_confirmations', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inspection_duty_confirmation_issued']);
    }

    private function assignedUser(): array
    {
        Carbon::setTestNow('2026-07-12 02:00:00 UTC');
        $user = $this->inspectionUser('Assigned Inspector');
        $team = Team::query()->create(['name' => 'Security Team', 'status' => 'On Duty']);
        TeamMember::query()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'name' => $user->name,
            'role' => 'Inspector',
            'is_primary' => true,
            'started_at' => '2026-01-01',
        ]);
        $roster = Roster::query()->create([
            'date' => '2026-07-12',
            'shift' => 'day',
            'team_id' => $team->id,
            'status' => 'published',
            'created_by' => $user->id,
            'published_by' => $user->id,
            'published_at' => now(),
        ]);

        return [$user, $team, $roster];
    }

    private function inspectionUser(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'status' => 'active']);
        $permission = Permission::query()->firstOrCreate(['name' => 'reports.inspection.view', 'guard_name' => 'web']);
        $role = Role::query()->firstOrCreate(['name' => 'Duty Security Tester', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);

        return $user;
    }

    private function contextVersion(User $user): string
    {
        return (string) $this->actingAs($user)
            ->getJson('/api/inspection/duty-context')
            ->assertOk()
            ->json('data.contextVersion');
    }

    private function issue(User $user): string
    {
        return (string) $this->actingAs($user)->postJson('/api/inspection/duty-context/confirm', [
            'operation' => 'submit',
            'contextVersion' => $this->contextVersion($user),
            'formId' => 'general-inspection',
            'recordId' => 'report-1',
            'idempotencyKey' => 'submit-1',
        ])->assertCreated()->json('data.dutyConfirmationToken');
    }

    private function consume(User $user, string $token): array
    {
        return app(InspectionDutyConfirmationService::class)->consume(
            $this->mutationRequest($user, $token, ['submission_key' => 'submit-1']),
            'submit',
            'report-1',
            'general-inspection',
        );
    }

    private function mutationRequest(User $user, string $token, array $input = []): Request
    {
        $request = Request::create('/api/reports/report-1', 'POST', $input);
        if ($token !== '') {
            $request->headers->set('X-Duty-Confirmation', $token);
        }
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function assertHttpFailure(callable $callback, int $status, string $code): void
    {
        try {
            $callback();
            $this->fail("Expected HTTP {$status} {$code}.");
        } catch (HttpResponseException $exception) {
            $this->assertSame($status, $exception->getResponse()->getStatusCode());
            $this->assertSame($code, $exception->getResponse()->getData(true)['code']);
        }
    }
}
