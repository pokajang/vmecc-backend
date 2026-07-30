<?php

namespace Tests\Feature;

use App\Models\OvertimeRecord;
use App\Models\PayrollClaim;
use App\Models\User;
use App\Models\WorkflowNotification;
use App\Services\WorkflowNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PayrollClaimPaymentWorkflowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_paid_requires_staff_salary_pay_permission(): void
    {
        $manager = User::factory()->create(['status' => 'Active']);
        $owner = User::factory()->create(['status' => 'Active']);
        $claim = $this->createSalaryClaim($owner, [
            'status' => 'Approved',
            'payment_date' => null,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/staff/salary-claims/records/{$owner->id}/{$claim->id}/mark-paid", [
                'payment_date' => '2026-04-23',
            ])
            ->assertStatus(403);
    }

    public function test_mark_paid_sets_paid_fields_and_creates_event(): void
    {
        $manager = User::factory()->create(['status' => 'Active']);
        $this->grantPermission($manager, 'staff.salary.pay');
        $owner = User::factory()->create(['status' => 'Active']);
        $claim = $this->createSalaryClaim($owner, [
            'status' => 'Approved',
            'payment_date' => null,
            'paid_at' => null,
            'paid_by_user_id' => null,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/staff/salary-claims/records/{$owner->id}/{$claim->id}/mark-paid", [
                'payment_date' => '2026-04-23',
                'payment_reference' => 'BANK-TRX-001',
                'payment_note' => 'Salary credited.',
                'expected_version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Paid')
            ->assertJsonPath('data.payment_date', '2026-04-23')
            ->assertJsonPath('data.payment_reference', 'BANK-TRX-001')
            ->assertJsonPath('data.paid_by_user_id', $manager->id)
            ->assertJsonPath('data.version', 2);

        $claim->refresh();
        $this->assertSame('Paid', $claim->status);
        $this->assertSame('2026-04-23', optional($claim->payment_date)->toDateString());
        $this->assertNotNull($claim->paid_at);
        $this->assertSame($manager->id, (int) $claim->paid_by_user_id);
        $this->assertSame(2, $claim->version);

        $this->assertDatabaseHas('payroll_claim_payment_events', [
            'claim_id' => $claim->id,
            'action' => 'mark_paid',
            'payment_date' => '2026-04-23',
            'payment_reference' => 'BANK-TRX-001',
            'acted_by_user_id' => $manager->id,
        ]);
        $notification = WorkflowNotification::query()
            ->where('module', 'salary')
            ->where('record_id', $claim->id)
            ->where('event_type', 'paid')
            ->firstOrFail();
        $this->assertSame([$owner->id], $notification->recipient_user_ids);
        $this->assertDatabaseHas('workflow_notification_recipient_states', [
            'notification_id' => $notification->id,
            'user_id' => $owner->id,
        ]);
    }

    public function test_unmark_paid_requires_reason_and_restores_approved_status(): void
    {
        $manager = User::factory()->create(['status' => 'Active']);
        $this->grantPermission($manager, 'staff.salary.pay');
        $owner = User::factory()->create(['status' => 'Active']);
        $claim = $this->createSalaryClaim($owner, [
            'status' => 'Paid',
            'payment_date' => '2026-04-23',
            'paid_at' => now()->subDay(),
            'paid_by_user_id' => $manager->id,
            'payment_reference' => 'BANK-TRX-001',
            'payment_note' => 'Salary credited.',
        ]);

        $this->actingAs($manager)
            ->postJson("/api/staff/salary-claims/records/{$owner->id}/{$claim->id}/unmark-paid", [])
            ->assertStatus(422);

        $this->actingAs($manager)
            ->postJson("/api/staff/salary-claims/records/{$owner->id}/{$claim->id}/unmark-paid", [
                'reason' => 'Payment reversal for correction.',
                'expected_version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Approved')
            ->assertJsonPath('data.payment_date', null);

        $claim->refresh();
        $this->assertSame('Approved', $claim->status);
        $this->assertNull($claim->payment_date);
        $this->assertNull($claim->paid_at);
        $this->assertNull($claim->paid_by_user_id);
        $this->assertNull($claim->payment_reference);
        $this->assertNull($claim->payment_note);

        $this->assertDatabaseHas('payroll_claim_payment_events', [
            'claim_id' => $claim->id,
            'action' => 'unmark_paid',
            'reason' => 'Payment reversal for correction.',
            'acted_by_user_id' => $manager->id,
        ]);
        $this->assertDatabaseHas('workflow_notifications', [
            'module' => 'salary',
            'record_id' => $claim->id,
            'event_type' => 'payment_reopened',
            'owner_user_id' => $owner->id,
        ]);
    }

    public function test_bulk_mark_paid_returns_updated_and_skipped_entries(): void
    {
        $manager = User::factory()->create(['status' => 'Active']);
        $this->grantPermission($manager, 'staff.salary.pay');
        $owner = User::factory()->create(['status' => 'Active']);
        $approvedClaim = $this->createSalaryClaim($owner, [
            'status' => 'Approved',
            'payment_date' => null,
        ]);
        $pendingClaim = $this->createSalaryClaim($owner, [
            'status' => 'Pending',
            'payment_date' => null,
        ]);

        $response = $this->actingAs($manager)
            ->postJson('/api/staff/salary-claims/records/mark-paid/bulk', [
                'entries' => [
                    ['owner_id' => $owner->id, 'claim_id' => $approvedClaim->id, 'expected_version' => 1],
                    ['owner_id' => $owner->id, 'claim_id' => $pendingClaim->id, 'expected_version' => 1],
                ],
                'payment_date' => '2026-04-23',
                'payment_reference' => 'BULK-TRX-01',
            ])
            ->assertOk();

        $response->assertJsonCount(1, 'data.updated_rows');
        $response->assertJsonCount(1, 'data.skipped');
        $response->assertJsonPath('data.updated_rows.0.id', $approvedClaim->id);
        $response->assertJsonPath('data.updated_rows.0.status', 'Paid');

        $approvedClaim->refresh();
        $pendingClaim->refresh();
        $this->assertSame('Paid', $approvedClaim->status);
        $this->assertSame('Pending', $pendingClaim->status);
        $this->assertDatabaseHas('workflow_notifications', [
            'module' => 'salary',
            'record_id' => $approvedClaim->id,
            'event_type' => 'paid',
            'owner_user_id' => $owner->id,
        ]);
        $this->assertDatabaseMissing('workflow_notifications', [
            'module' => 'salary',
            'record_id' => $pendingClaim->id,
            'event_type' => 'paid',
        ]);
    }

    public function test_mark_paid_rejects_a_stale_version_without_payment_side_effects(): void
    {
        $manager = User::factory()->create(['status' => 'Active']);
        $this->grantPermission($manager, 'staff.salary.pay');
        $owner = User::factory()->create(['status' => 'Active']);
        $claim = $this->createSalaryClaim($owner, [
            'status' => 'Approved',
            'version' => 2,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/staff/salary-claims/records/{$owner->id}/{$claim->id}/mark-paid", [
                'payment_date' => '2026-04-23',
                'expected_version' => 1,
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'PAYROLL_CLAIM_VERSION_CONFLICT')
            ->assertJsonPath('currentVersion', 2);

        $claim->refresh();
        $this->assertSame('Approved', $claim->status);
        $this->assertNull($claim->paid_at);
        $this->assertDatabaseCount('payroll_claim_payment_events', 0);
    }

    public function test_mark_paid_rolls_back_when_notification_outbox_persistence_fails(): void
    {
        $manager = User::factory()->create(['status' => 'Active']);
        $this->grantPermission($manager, 'staff.salary.pay');
        $owner = User::factory()->create(['status' => 'Active']);
        $claim = $this->createSalaryClaim($owner, ['status' => 'Approved']);
        $notificationService = \Mockery::mock(WorkflowNotificationService::class);
        $notificationService->shouldReceive('emit')
            ->once()
            ->andThrow(new \RuntimeException('Simulated notification persistence failure.'));
        $this->app->instance(WorkflowNotificationService::class, $notificationService);

        $this->actingAs($manager)
            ->postJson("/api/staff/salary-claims/records/{$owner->id}/{$claim->id}/mark-paid", [
                'payment_date' => '2026-04-23',
                'expected_version' => 1,
            ])
            ->assertStatus(500);

        $claim->refresh();
        $this->assertSame('Approved', $claim->status);
        $this->assertSame(1, $claim->version);
        $this->assertNull($claim->paid_at);
        $this->assertDatabaseCount('payroll_claim_payment_events', 0);
    }

    public function test_mark_paid_rejects_changed_approved_overtime_snapshot(): void
    {
        $manager = User::factory()->create(['status' => 'Active']);
        $this->grantPermission($manager, 'staff.salary.pay');
        $owner = User::factory()->create(['status' => 'Active']);
        $overtime = OvertimeRecord::query()->create([
            'user_id' => $owner->id,
            'display_id' => 'OT-SNAPSHOT-PAYMENT',
            'overtime_type' => 'weekday',
            'claim_date' => '2026-04-14',
            'start_time' => '18:00',
            'end_time' => '19:00',
            'is_overnight' => false,
            'duration_minutes' => 60,
            'reason' => 'Approved overtime included in salary payment.',
            'status' => 'Approved',
            'workflow_stage' => 'done',
            'approval_history' => [],
            'version' => 1,
        ]);
        $claim = $this->createSalaryClaim($owner, [
            'status' => 'Approved',
            'overtime_rows' => [[
                'overtimeRecordId' => $overtime->id,
                'overtimePublicId' => $overtime->public_id,
                'overtimeRecordVersion' => 1,
            ]],
        ]);
        $overtime->update(['version' => 2]);

        $this->actingAs($manager)
            ->postJson("/api/staff/salary-claims/records/{$owner->id}/{$claim->id}/mark-paid", [
                'payment_date' => '2026-04-23',
                'expected_version' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('overtime_snapshot');

        $claim->refresh();
        $this->assertSame('Approved', $claim->status);
        $this->assertSame(1, $claim->version);
        $this->assertNull($claim->paid_at);
        $this->assertDatabaseCount('payroll_claim_payment_events', 0);
    }

    private function grantPermission(User $user, string $permissionName): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);
        $user->givePermissionTo($permission);
    }

    private function createSalaryClaim(User $user, array $overrides = []): PayrollClaim
    {
        $base = [
            'user_id' => $user->id,
            'display_id' => 'CLM-2026-'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
            'claim_type' => 'salary',
            'category' => 'Salary',
            'period' => 'April 2026',
            'period_value' => '2026-04',
            'amount' => 1966.0,
            'approved_overtime_payout' => 0,
            'adjustments_total' => 0,
            'projected_net_payout' => 1966.0,
            'status' => 'Pending',
            'submitted_at' => now(),
            'submitted_by' => $user->name,
            'submitted_by_name' => $user->name,
            'updated_by' => $user->name,
            'updated_by_name' => $user->name,
            'workflow_stage' => 'done',
            'workflow_snapshot' => [],
            'next_action_role' => null,
            'approval_history' => [],
            'payroll_snapshot' => ['basic' => 1966, 'net' => 1966],
            'overtime_rows' => [],
            'overtime_rate_snapshot' => null,
            'payslip_snapshot' => null,
            'notes' => 'Salary claim for test',
            'attachment_id' => null,
        ];

        return PayrollClaim::query()->create(array_merge($base, $overrides));
    }
}
