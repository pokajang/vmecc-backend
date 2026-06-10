<?php

namespace Tests\Feature;

use App\Models\PayrollClaim;
use App\Models\User;
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
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Paid')
            ->assertJsonPath('data.payment_date', '2026-04-23')
            ->assertJsonPath('data.payment_reference', 'BANK-TRX-001')
            ->assertJsonPath('data.paid_by_user_id', $manager->id);

        $claim->refresh();
        $this->assertSame('Paid', $claim->status);
        $this->assertSame('2026-04-23', optional($claim->payment_date)->toDateString());
        $this->assertNotNull($claim->paid_at);
        $this->assertSame($manager->id, (int) $claim->paid_by_user_id);

        $this->assertDatabaseHas('payroll_claim_payment_events', [
            'claim_id' => $claim->id,
            'action' => 'mark_paid',
            'payment_date' => '2026-04-23',
            'payment_reference' => 'BANK-TRX-001',
            'acted_by_user_id' => $manager->id,
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
                    ['owner_id' => $owner->id, 'claim_id' => $approvedClaim->id],
                    ['owner_id' => $owner->id, 'claim_id' => $pendingClaim->id],
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
