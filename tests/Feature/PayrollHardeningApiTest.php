<?php

namespace Tests\Feature;

use App\Models\PayrollClaim;
use App\Models\PayrollClaimDraft;
use App\Models\PayrollClaimItem;
use App\Models\OvertimeRecord;
use App\Models\SalaryAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkflowAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PayrollHardeningApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_payslips_endpoint_requires_self_payroll_permission(): void
    {
        $user = User::factory()->create(['status' => 'Active']);
        $this->actingAs($user);

        $this->getJson('/api/payroll/payslips')->assertStatus(403);
    }

    public function test_payslips_endpoint_returns_salary_claim_rows_with_downloadable_flag(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        $approvedSalary = $this->createPayrollClaim($user, [
            'claim_type' => 'salary',
            'status' => 'Approved',
            'period_value' => '2026-03',
            'display_id' => 'CLM-2026-021',
        ]);
        $pendingSalary = $this->createPayrollClaim($user, [
            'claim_type' => 'salary',
            'status' => 'Pending',
            'period_value' => '2026-02',
            'display_id' => 'CLM-2026-020',
        ]);
        $this->createPayrollClaim($user, [
            'claim_type' => 'expense',
            'status' => 'Approved',
            'period_value' => '2026-03',
        ]);

        $response = $this->getJson('/api/payroll/payslips');
        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        $rows = collect($response->json('data'))->keyBy('payslip_id');
        $this->assertSame(true, $rows[$approvedSalary->id]['downloadable']);
        $this->assertSame(false, $rows[$pendingSalary->id]['downloadable']);
        $this->assertArrayNotHasKey('download_url', $rows[$approvedSalary->id]);
        $this->assertMatchesRegularExpression(
            '/^payslip-march2026_[a-z0-9-]+\\.pdf$/',
            (string) data_get($rows[$approvedSalary->id], 'download_filename'),
        );
    }

    public function test_payslips_endpoint_includes_salary_record_details_when_available(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        $this->createSalaryAssignment($user, [
            'reference_id' => 'SAL-2026-001',
            'effective_from' => '2026-03-01',
            'basic_salary' => 3200,
            'allowance_total' => 450,
            'allowances' => [
                ['name' => 'Transport', 'amount' => 250],
                ['name' => 'Meal', 'amount' => 200],
            ],
            'employee_contributions' => ['epf' => 352, 'perkeso' => 23.8, 'sip' => 8.4],
            'employer_contributions' => ['epf' => 384, 'perkeso' => 28.2, 'sip' => 8.4],
        ]);

        $claim = $this->createPayrollClaim($user, [
            'claim_type' => 'salary',
            'status' => 'Approved',
            'period_value' => '2026-03',
            'payroll_snapshot' => [
                'basic' => 3200,
                'allowance' => 450,
                'gross' => 3650,
                'totalDeductions' => 384.2,
                'net' => 3265.8,
            ],
            'amount' => 3400,
            'approved_overtime_payout' => 120,
        ]);

        $response = $this->getJson('/api/payroll/payslips');
        $response->assertOk();

        $row = collect($response->json('data'))
            ->first(fn ($entry) => (int) ($entry['payslip_id'] ?? 0) === (int) $claim->id);

        $this->assertIsArray($row);
        $this->assertSame('SAL-2026-001', data_get($row, 'salary_record.referenceId'));
        $this->assertSame(3200.0, (float) data_get($row, 'baseline.basicSalary'));
        $this->assertSame(450.0, (float) data_get($row, 'baseline.allowanceTotal'));
        $this->assertSame('hybrid', (string) data_get($row, 'baseline_source'));
        $this->assertArrayHasKey('totals', $row);
        $this->assertArrayHasKey('overtime', $row);
    }

    public function test_self_payroll_user_can_read_own_overtime_records_for_salary_claim_projection(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        OvertimeRecord::query()->create([
            'user_id' => $user->id,
            'display_id' => 'OT-2026-700',
            'overtime_type' => 'weekend',
            'claim_date' => '2026-04-04',
            'start_time' => '17:00:00',
            'end_time' => '21:00:00',
            'is_overnight' => false,
            'duration_minutes' => 240,
            'reason' => 'Weekend support',
            'status' => 'Approved',
            'applied_at' => now()->subDay(),
            'workflow_stage' => 'done',
            'workflow_snapshot' => [],
            'next_action_role' => null,
            'applicant_roles' => [],
            'approval_history' => [],
            'submitted_by' => $user->name,
            'attachment_id' => null,
        ]);

        $response = $this->getJson('/api/overtime?month=2026-04&status=Approved');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.display_id', 'OT-2026-700');
        $response->assertJsonPath('data.0.status', 'Approved');
    }

    public function test_payslip_download_uses_authenticated_pdf_for_downloadable_salary_claim(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        $claim = $this->createPayrollClaim($user, [
            'claim_type' => 'salary',
            'status' => 'Approved',
            'display_id' => 'CLM-2026-099',
            'period_value' => '2026-03',
            'approval_history' => [[
                'action' => 'Approved',
                'at' => now()->toIso8601String(),
            ]],
        ]);

        $response = $this->get("/api/payroll/payslips/{$claim->id}/download");
        $response->assertOk();

        $contentType = (string) $response->headers->get('content-type');
        $this->assertStringContainsString('application/pdf', $contentType);
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $contentDisposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('attachment;', $contentDisposition);
        $this->assertStringContainsString('.pdf', $contentDisposition);
    }

    public function test_payslip_download_rejects_when_required_personal_profile_fields_are_missing(): void
    {
        $user = $this->createPayrollUser([
            'phone' => null,
        ]);
        $this->actingAs($user);

        $claim = $this->createPayrollClaim($user, [
            'claim_type' => 'salary',
            'status' => 'Approved',
            'display_id' => 'CLM-2026-199',
            'period_value' => '2026-03',
            'approval_history' => [[
                'action' => 'Approved',
                'at' => now()->toIso8601String(),
            ]],
        ]);

        $this->getJson("/api/payroll/payslips/{$claim->id}/download")
            ->assertStatus(422)
            ->assertJsonPath('missing_fields.0', 'phone');
    }

    public function test_payslips_index_marks_download_unavailable_when_profile_is_incomplete(): void
    {
        $user = $this->createPayrollUser([
            'ic_number' => null,
        ]);
        $this->actingAs($user);

        $claim = $this->createPayrollClaim($user, [
            'claim_type' => 'salary',
            'status' => 'Approved',
            'period_value' => '2026-03',
            'display_id' => 'CLM-2026-198',
        ]);

        $response = $this->getJson('/api/payroll/payslips');
        $response->assertOk();

        $row = collect($response->json('data'))
            ->first(fn ($entry) => (int) ($entry['payslip_id'] ?? 0) === (int) $claim->id);

        $this->assertIsArray($row);
        $this->assertFalse((bool) data_get($row, 'downloadable'));
        $this->assertFalse((bool) data_get($row, 'employee_profile_complete'));
        $this->assertContains('ic_number', (array) data_get($row, 'employee_profile_missing_fields', []));
    }

    public function test_payslip_download_rejects_non_downloadable_status(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        $claim = $this->createPayrollClaim($user, [
            'claim_type' => 'salary',
            'status' => 'Pending',
        ]);

        $this->getJson("/api/payroll/payslips/{$claim->id}/download")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Payslip download unavailable for this record.');
    }

    public function test_payslip_template_renders_compact_payroll_only_layout_without_workflow_fields(): void
    {
        $html = view('pdf.payroll-payslip', [
            'payslip' => [
                'reference' => 'CLM-2026-016',
                'period' => [
                    'label' => 'April 2026',
                    'value' => '2026-04',
                    'startDate' => '2026-04-01',
                    'endDate' => '2026-04-30',
                ],
                'paymentDate' => '2026-04-22',
                'status' => 'Approved',
                'issuedAt' => now()->toIso8601String(),
                'employeeName' => 'Jang',
                'employeeProfile' => [
                    'icNumber' => '900101-01-1234',
                ],
                'employeeRoles' => ['System Administrator'],
                'employeeStatutory' => [
                    'epfNo' => 'EPF-1',
                    'perkesoNo' => 'PERKESO-1',
                    'incomeTaxNo' => 'TAX-1',
                ],
                'employer' => [
                    'name' => 'ACME Sdn Bhd',
                    'registrationNumber' => 'REG-1',
                    'myTaxNumber' => 'MYTAX-1',
                    'email' => 'payroll@acme.com',
                    'phone' => '0123456789',
                ],
                'totals' => [
                    'baselineNetSalary' => 1966,
                    'adjustmentsTotal' => 10,
                    'approvedOvertimePayout' => 5,
                    'netPayable' => 1981,
                ],
                'baseline' => [
                    'employeeContributions' => [
                        'epf' => 220,
                        'perkeso' => 10,
                        'sip' => 4,
                    ],
                    'employerContributions' => [
                        'epf' => 260,
                        'perkeso' => 10,
                        'sip' => 4,
                    ],
                    // Would be duplicate if rendered together with contribution map.
                    'deductionItems' => [
                        ['label' => 'EPF', 'amount' => 220],
                    ],
                ],
                'generatedAt' => now()->toIso8601String(),
                'generatedBy' => [
                    'name' => 'Admin User',
                ],
            ],
        ])->render();

        $this->assertStringContainsString('Payroll Totals', $html);
        $this->assertStringContainsString('Statutory Contributions', $html);
        $this->assertStringContainsString('Net Payable', $html);
        $this->assertStringContainsString('Payment Date', $html);
        $this->assertStringContainsString('Generated at', $html);

        // Workflow/system metadata removed from rendered template.
        $this->assertStringNotContainsString('Status</td>', $html);
        $this->assertStringNotContainsString('Issued At', $html);
        $this->assertStringNotContainsString('Generated by', $html);

        // Redundant statutory rows are deduped to a single contribution row per key.
        $this->assertSame(1, substr_count($html, '<td>EPF</td>'));
        $this->assertSame(1, substr_count($html, '<td>PERKESO (SOCSO)</td>'));
        $this->assertSame(1, substr_count($html, '<td>SIP</td>'));
    }

    public function test_payslip_template_falls_back_to_deduction_items_when_contribution_maps_are_empty(): void
    {
        $html = view('pdf.payroll-payslip', [
            'payslip' => [
                'reference' => 'CLM-2026-017',
                'period' => [
                    'label' => 'April 2026',
                    'value' => '2026-04',
                    'startDate' => '2026-04-01',
                    'endDate' => '2026-04-30',
                ],
                'paymentDate' => '2026-04-22',
                'employeeName' => 'Jang',
                'employeeProfile' => [
                    'icNumber' => '900101-01-1234',
                ],
                'employeeRoles' => ['System Administrator'],
                'employeeStatutory' => [
                    'epfNo' => 'EPF-1',
                    'perkesoNo' => 'PERKESO-1',
                    'incomeTaxNo' => 'TAX-1',
                ],
                'totals' => [
                    'baselineNetSalary' => 1966,
                    'adjustmentsTotal' => 0,
                    'approvedOvertimePayout' => 0,
                    'netPayable' => 1966,
                ],
                'baseline' => [
                    'employeeContributions' => [],
                    'employerContributions' => [],
                    'deductionItems' => [
                        ['label' => 'Loan Recovery', 'amount' => 120],
                    ],
                ],
                'generatedAt' => now()->toIso8601String(),
            ],
        ])->render();

        $this->assertStringContainsString('<td>Loan Recovery</td>', $html);
        $this->assertStringContainsString('<td class="right">120.00</td>', $html);
        $this->assertMatchesRegularExpression('/<td class="right">\\s*-\\s*<\\/td>/', $html);
    }

    public function test_payslip_download_rehydrates_blank_snapshot_employer_fields_from_company_settings(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        Setting::query()->updateOrCreate(
            ['key' => 'payroll_company_profile'],
            ['value' => [
                'legalName' => 'Example Payroll Sdn Bhd',
                'registrationNumber' => 'SSM-998877-X',
                'myTaxNumber' => 'MYTAX-123',
                'address' => 'KL',
                'email' => 'payroll@example.com',
                'phone' => '0123000000',
                'financeContactName' => 'Finance Team',
                'financeContactEmail' => 'finance@example.com',
                'financeContactPhone' => '0123000001',
            ]],
        );

        $claim = $this->createPayrollClaim($user, [
            'status' => 'Approved',
            'period_value' => '2026-04',
            'approval_history' => [[
                'action' => 'Approved',
                'at' => now()->toIso8601String(),
            ]],
            'payslip_snapshot' => [
                'reference' => 'CLM-2026-777',
                'period' => [
                    'label' => 'April 2026',
                    'value' => '2026-04',
                    'startDate' => '2026-04-01',
                    'endDate' => '2026-04-30',
                ],
                'employeeName' => $user->name,
                'employeeProfile' => [
                    'icNumber' => $user->ic_number,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ],
                'employeeRoles' => ['Payroll Self-Service Tester'],
                'employeeStatutory' => [
                    'epfNo' => data_get($user->statutory_info, 'epfNo'),
                    'perkesoNo' => data_get($user->statutory_info, 'perkesoNo'),
                    'incomeTaxNo' => data_get($user->statutory_info, 'incomeTaxNo'),
                ],
                // Legacy blank values should not override current company settings.
                'employer' => [
                    'name' => '',
                    'registrationNumber' => '',
                    'myTaxNumber' => '',
                    'email' => '',
                    'phone' => '',
                    'financeContactName' => '',
                    'financeContactEmail' => '',
                    'financeContactPhone' => '',
                ],
                'totals' => [
                    'baselineNetSalary' => 1966,
                    'adjustmentsTotal' => 0,
                    'approvedOvertimePayout' => 0,
                    'netPayable' => 1966,
                ],
                'baseline' => [
                    'employeeContributions' => ['epf' => 220],
                    'employerContributions' => ['epf' => 260],
                    'deductionItems' => [],
                ],
            ],
        ]);

        $this->get("/api/payroll/payslips/{$claim->id}/download")->assertOk();

        $claim->refresh();
        $this->assertSame('Example Payroll Sdn Bhd', (string) data_get($claim->payslip_snapshot, 'employer.name'));
        $this->assertSame('SSM-998877-X', (string) data_get($claim->payslip_snapshot, 'employer.registrationNumber'));
    }

    public function test_payslip_download_rehydrates_blank_snapshot_employee_ic_number_from_user_profile(): void
    {
        $user = $this->createPayrollUser([
            'ic_number' => '900101-01-1234',
        ]);
        $this->actingAs($user);

        $claim = $this->createPayrollClaim($user, [
            'status' => 'Approved',
            'period_value' => '2026-04',
            'approval_history' => [[
                'action' => 'Approved',
                'at' => now()->toIso8601String(),
            ]],
            'payslip_snapshot' => [
                'reference' => 'CLM-2026-778',
                'period' => [
                    'label' => 'April 2026',
                    'value' => '2026-04',
                    'startDate' => '2026-04-01',
                    'endDate' => '2026-04-30',
                ],
                'employeeName' => $user->name,
                'employeeProfile' => [
                    'icNumber' => '',
                    'email' => $user->email,
                    'phone' => $user->phone,
                ],
                'employeeRoles' => ['Payroll Self-Service Tester'],
                'employeeStatutory' => [
                    'epfNo' => data_get($user->statutory_info, 'epfNo'),
                    'perkesoNo' => data_get($user->statutory_info, 'perkesoNo'),
                    'incomeTaxNo' => data_get($user->statutory_info, 'incomeTaxNo'),
                ],
                'employer' => [
                    'name' => 'Example Payroll Sdn Bhd',
                    'registrationNumber' => 'SSM-998877-X',
                ],
                'totals' => [
                    'baselineNetSalary' => 1966,
                    'adjustmentsTotal' => 0,
                    'approvedOvertimePayout' => 0,
                    'netPayable' => 1966,
                ],
                'baseline' => [
                    'employeeContributions' => ['epf' => 220],
                    'employerContributions' => ['epf' => 260],
                    'deductionItems' => [],
                ],
            ],
        ]);

        $this->get("/api/payroll/payslips/{$claim->id}/download")->assertOk();

        $claim->refresh();
        $this->assertSame('900101-01-1234', (string) data_get($claim->payslip_snapshot, 'employeeProfile.icNumber'));
    }

    public function test_draft_store_strips_legacy_attachment_binary_and_marks_needs_reattach(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        $response = $this->postJson('/api/payroll/claims/drafts', [
            'claim_type' => 'expense',
            'draft_id' => 'draft-secure-1',
            'payload' => [
                'id' => 'draft-secure-1',
                'claimType' => 'expense',
                'status' => 'Approved',
                'savedItems' => [[
                    'category' => 'Fuel',
                    'amount' => 15.5,
                    'attachmentDataUrl' => 'data:application/pdf;base64,Zm9v',
                    'attachmentName' => 'legacy.pdf',
                ]],
                'draftItem' => [
                    'category' => 'Fuel',
                    'attachmentDataUrl' => 'data:image/png;base64,Zm9v',
                    'attachmentName' => 'legacy-item.png',
                ],
                'updatedAt' => now()->toIso8601String(),
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.payload.savedItems.0.needsReattach', true);
        $response->assertJsonPath('data.payload.savedItems.0.attachmentMigrationAttempted', true);
        $response->assertJsonPath('data.payload.savedItems.0.attachmentUploadState', 'failed');

        $savedItem = $response->json('data.payload.savedItems.0');
        $this->assertArrayNotHasKey('attachmentDataUrl', $savedItem);
        $this->assertArrayNotHasKey('legacyAttachmentDataUrl', $savedItem);
        $this->assertArrayNotHasKey('status', (array) $response->json('data.payload'));

        $stored = PayrollClaimDraft::query()->firstOrFail();
        $storedJson = json_encode($stored->payload, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($storedJson);
        $this->assertStringNotContainsString('data:application/pdf;base64', $storedJson);
        $this->assertStringNotContainsString('data:image/png;base64', $storedJson);
    }

    public function test_workflow_attachment_delete_is_blocked_when_linked_to_payroll_claim(): void
    {
        $user = User::factory()->create(['status' => 'Active']);
        $this->actingAs($user);

        $attachment = WorkflowAttachment::query()->create([
            'owner_user_id' => $user->id,
            'disk' => 'local',
            'path' => "workflow-attachments/{$user->id}/linked-proof.pdf",
            'original_name' => 'linked-proof.pdf',
            'mime_type' => 'application/pdf',
            'size' => 128,
            'checksum' => null,
            'uploaded_at' => now(),
        ]);

        $this->createPayrollClaim($user, [
            'claim_type' => 'salary',
            'status' => 'Pending',
            'attachment_id' => $attachment->id,
        ]);

        $this->deleteJson("/api/workflow/attachments/{$attachment->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['attachment']);

        $this->assertDatabaseHas('workflow_attachments', ['id' => $attachment->id]);
    }

    public function test_payroll_claim_submit_rejects_attachment_owned_by_other_user(): void
    {
        $claimant = $this->createPayrollUser();
        $otherUser = User::factory()->create(['status' => 'Active']);
        $this->actingAs($claimant);

        $foreignAttachment = WorkflowAttachment::query()->create([
            'owner_user_id' => $otherUser->id,
            'disk' => 'local',
            'path' => "workflow-attachments/{$otherUser->id}/foreign-proof.pdf",
            'original_name' => 'foreign-proof.pdf',
            'mime_type' => 'application/pdf',
            'size' => 256,
            'checksum' => null,
            'uploaded_at' => now(),
        ]);

        $this->postJson('/api/payroll/claims', [
            'claim_type' => 'expense',
            'period' => 'March 2026',
            'period_value' => '2026-03',
            'items' => [[
                'claimType' => 'Fuel',
                'amount' => 45.8,
                'claimDate' => now()->toDateString(),
                'attachmentId' => $foreignAttachment->id,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors(['attachment_id']);
    }

    public function test_salary_claim_submit_includes_approved_overtime_snapshot_for_selected_month(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        OvertimeRecord::query()->create([
            'user_id' => $user->id,
            'display_id' => 'OT-2026-801',
            'overtime_type' => 'weekend',
            'claim_date' => '2026-04-05',
            'start_time' => '18:00:00',
            'end_time' => '22:00:00',
            'is_overnight' => false,
            'duration_minutes' => 240,
            'reason' => 'Approved April OT',
            'status' => 'Approved',
            'applied_at' => now()->subDay(),
            'workflow_stage' => 'done',
            'workflow_snapshot' => [],
            'next_action_role' => null,
            'applicant_roles' => [],
            'approval_history' => [],
            'submitted_by' => $user->name,
            'attachment_id' => null,
        ]);
        OvertimeRecord::query()->create([
            'user_id' => $user->id,
            'display_id' => 'OT-2026-802',
            'overtime_type' => 'weekday',
            'claim_date' => '2026-05-01',
            'start_time' => '19:00:00',
            'end_time' => '20:00:00',
            'is_overnight' => false,
            'duration_minutes' => 60,
            'reason' => 'Approved May OT',
            'status' => 'Approved',
            'applied_at' => now(),
            'workflow_stage' => 'done',
            'workflow_snapshot' => [],
            'next_action_role' => null,
            'applicant_roles' => [],
            'approval_history' => [],
            'submitted_by' => $user->name,
            'attachment_id' => null,
        ]);

        $response = $this->postJson('/api/payroll/claims', [
            'claim_type' => 'salary',
            'period' => 'April 2026',
            'period_value' => '2026-04',
            'items' => [],
            'payroll_snapshot' => [
                'basic' => 2600,
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.period_value', '2026-04');
        $response->assertJsonCount(1, 'data.overtime_rows');
        $response->assertJsonPath('data.overtime_rows.0.claimDate', '2026-04-05');
        $response->assertJsonPath('data.overtime_rows.0.status', 'Approved');
        $this->assertGreaterThan(0, (float) $response->json('data.approved_overtime_payout'));
    }

    public function test_salary_claim_submit_rejects_duplicate_period_for_same_user(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        $this->createPayrollClaim($user, [
            'claim_type' => 'salary',
            'status' => 'Pending',
            'period' => 'April 2026',
            'period_value' => '2026-04',
        ]);

        $this->postJson('/api/payroll/claims', [
            'claim_type' => 'salary',
            'period' => 'April 2026',
            'period_value' => '2026-04',
            'items' => [],
            'payroll_snapshot' => [
                'basic' => 2600,
                'net' => 1966,
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period_value'])
            ->assertJsonPath('errors.period_value.0', 'Salary claim for April 2026 already exists.');
    }

    public function test_salary_claim_submit_allows_same_period_when_existing_claim_is_cancelled(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        $this->createPayrollClaim($user, [
            'claim_type' => 'salary',
            'status' => 'Cancelled',
            'period' => 'April 2026',
            'period_value' => '2026-04',
        ]);

        $this->postJson('/api/payroll/claims', [
            'claim_type' => 'salary',
            'period' => 'April 2026',
            'period_value' => '2026-04',
            'items' => [],
            'payroll_snapshot' => [
                'basic' => 2600,
                'net' => 1966,
            ],
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.period_value', '2026-04');
    }

    public function test_salary_claim_update_rejects_switch_to_duplicate_period_for_same_user(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        $existing = $this->createPayrollClaim($user, [
            'claim_type' => 'salary',
            'status' => 'Pending',
            'period' => 'April 2026',
            'period_value' => '2026-04',
        ]);
        $editable = $this->createPayrollClaim($user, [
            'claim_type' => 'salary',
            'status' => 'Pending',
            'period' => 'May 2026',
            'period_value' => '2026-05',
        ]);

        $this->assertNotSame($existing->id, $editable->id);

        $this->putJson("/api/payroll/claims/{$editable->id}", [
            'claim_type' => 'salary',
            'period' => 'April 2026',
            'period_value' => '2026-04',
            'items' => [],
            'payroll_snapshot' => [
                'basic' => 2600,
                'net' => 1966,
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period_value'])
            ->assertJsonPath('errors.period_value.0', 'Salary claim for April 2026 already exists.');
    }

    public function test_salary_claim_submit_uses_role_based_normal_hours_for_overtime_snapshot(): void
    {
        $user = $this->createPayrollUser();
        $systemAdministratorRole = Role::query()->firstOrCreate(
            ['name' => 'System Administrator', 'guard_name' => 'web'],
            ['name' => 'System Administrator', 'guard_name' => 'web'],
        );
        $user->assignRole($systemAdministratorRole);
        $this->actingAs($user);

        Setting::query()->updateOrCreate(
            ['key' => 'overtime_rate_settings'],
            ['value' => [
                'weekdayMultiplier' => '1.5',
                'weekendMultiplier' => '2.0',
                'publicHolidayMultiplier' => '3.0',
                'baseHourCalculation' => [
                    'mode' => 'month_days_division',
                    'monthlyDivisor' => '26',
                    'globalNormalHoursPerDay' => '12',
                    'normalHoursStrategy' => 'role_based',
                    'defaultRoleHoursPerDay' => '8',
                    'roleNormalHoursPerDay' => [
                        'System Administrator' => '4',
                    ],
                ],
            ]],
        );

        OvertimeRecord::query()->create([
            'user_id' => $user->id,
            'display_id' => 'OT-2026-901',
            'overtime_type' => 'weekend',
            'claim_date' => '2026-04-04',
            'start_time' => '18:00:00',
            'end_time' => '22:00:00',
            'is_overnight' => false,
            'duration_minutes' => 240,
            'reason' => 'Weekend support',
            'status' => 'Approved',
            'applied_at' => now()->subDay(),
            'workflow_stage' => 'done',
            'workflow_snapshot' => [],
            'next_action_role' => null,
            'applicant_roles' => [],
            'approval_history' => [],
            'submitted_by' => $user->name,
            'attachment_id' => null,
        ]);
        OvertimeRecord::query()->create([
            'user_id' => $user->id,
            'display_id' => 'OT-2026-902',
            'overtime_type' => 'publicHoliday',
            'claim_date' => '2026-04-13',
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'is_overnight' => false,
            'duration_minutes' => 60,
            'reason' => 'Holiday support',
            'status' => 'Approved',
            'applied_at' => now()->subDay(),
            'workflow_stage' => 'done',
            'workflow_snapshot' => [],
            'next_action_role' => null,
            'applicant_roles' => [],
            'approval_history' => [],
            'submitted_by' => $user->name,
            'attachment_id' => null,
        ]);

        $response = $this->postJson('/api/payroll/claims', [
            'claim_type' => 'salary',
            'period' => 'April 2026',
            'period_value' => '2026-04',
            'items' => [],
            'payroll_snapshot' => [
                'basic' => 2000,
                'net' => 1966,
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.approved_overtime_payout', 183.37);
        $response->assertJsonPath('data.projected_net_payout', 2149.37);
        $response->assertJsonPath('data.overtime_rate_snapshot.globalNormalHoursPerDayUsed', 4);
        $response->assertJsonPath('data.overtime_rate_snapshot.normalHoursStrategyUsed', 'role_based');

        $rows = collect($response->json('data.overtime_rows'));
        $this->assertCount(2, $rows);
        $this->assertSame(183.37, round((float) $rows->sum('payoutUsed'), 2));
        $this->assertSame(
            [4.0],
            $rows->pluck('globalNormalHoursPerDayUsed')->map(fn ($hours) => (float) $hours)->unique()->values()->all(),
        );
    }

    public function test_claim_submit_consumes_source_draft_in_same_transaction(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        $draft = $this->createPayrollDraft($user, [
            'claim_type' => 'salary',
            'draft_id' => 'draft-consume-salary-1',
        ]);

        $response = $this->postJson('/api/payroll/claims', [
            'claim_type' => 'salary',
            'period' => 'April 2026',
            'period_value' => '2026-04',
            'source_draft_id' => 'draft-consume-salary-1',
            'source_draft_type' => 'salary',
            'items' => [],
            'payroll_snapshot' => [
                'basic' => 2600,
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.consumed_draft_id', 'draft-consume-salary-1');
        $response->assertJsonPath('data.consumed_draft_type', 'salary');
        $this->assertDatabaseMissing('payroll_claim_drafts', ['id' => $draft->id]);
    }

    public function test_claim_update_consumes_source_draft_when_present(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        $claim = $this->createPayrollClaim($user, [
            'claim_type' => 'expense',
            'status' => 'Pending',
            'period_value' => '2026-04',
        ]);
        $draft = $this->createPayrollDraft($user, [
            'claim_type' => 'expense',
            'draft_id' => 'draft-consume-expense-1',
        ]);

        $response = $this->putJson("/api/payroll/claims/{$claim->id}", [
            'claim_type' => 'expense',
            'period' => 'April 2026',
            'period_value' => '2026-04',
            'source_draft_id' => 'draft-consume-expense-1',
            'source_draft_type' => 'expense',
            'items' => [[
                'claimType' => 'Fuel',
                'amount' => 12.5,
                'claimDate' => now()->toDateString(),
                'lineNotes' => 'Fuel update',
            ]],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.consumed_draft_id', 'draft-consume-expense-1');
        $response->assertJsonPath('data.consumed_draft_type', 'expense');
        $this->assertDatabaseMissing('payroll_claim_drafts', ['id' => $draft->id]);
    }

    public function test_claim_submit_does_not_consume_other_users_draft(): void
    {
        $claimant = $this->createPayrollUser();
        $otherUser = $this->createPayrollUser();
        $this->actingAs($claimant);

        $foreignDraft = $this->createPayrollDraft($otherUser, [
            'claim_type' => 'salary',
            'draft_id' => 'foreign-shared-draft-id',
        ]);

        $response = $this->postJson('/api/payroll/claims', [
            'claim_type' => 'salary',
            'period' => 'April 2026',
            'period_value' => '2026-04',
            'source_draft_id' => 'foreign-shared-draft-id',
            'source_draft_type' => 'salary',
            'items' => [],
            'payroll_snapshot' => [
                'basic' => 2600,
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.consumed_draft_id', null);
        $this->assertDatabaseHas('payroll_claim_drafts', ['id' => $foreignDraft->id]);
    }

    public function test_claim_submit_failure_does_not_consume_source_draft(): void
    {
        $claimant = $this->createPayrollUser();
        $otherUser = User::factory()->create(['status' => 'Active']);
        $this->actingAs($claimant);

        $draft = $this->createPayrollDraft($claimant, [
            'claim_type' => 'expense',
            'draft_id' => 'draft-must-survive-failure',
        ]);

        $foreignAttachment = WorkflowAttachment::query()->create([
            'owner_user_id' => $otherUser->id,
            'disk' => 'local',
            'path' => "workflow-attachments/{$otherUser->id}/invalid.pdf",
            'original_name' => 'invalid.pdf',
            'mime_type' => 'application/pdf',
            'size' => 256,
            'checksum' => null,
            'uploaded_at' => now(),
        ]);

        $this->postJson('/api/payroll/claims', [
            'claim_type' => 'expense',
            'period' => 'April 2026',
            'period_value' => '2026-04',
            'source_draft_id' => 'draft-must-survive-failure',
            'source_draft_type' => 'expense',
            'items' => [[
                'claimType' => 'Fuel',
                'amount' => 45.8,
                'claimDate' => now()->toDateString(),
                'attachmentId' => $foreignAttachment->id,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors(['attachment_id']);

        $this->assertDatabaseHas('payroll_claim_drafts', ['id' => $draft->id]);
    }

    public function test_claim_submit_is_idempotent_per_submission_key(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        $payload = [
            'claim_type' => 'salary',
            'period' => 'April 2026',
            'period_value' => '2026-04',
            'submission_key' => 'salary-submit-key-001',
            'items' => [[
                'claimType' => 'Addition',
                'title' => 'Manual adjustment',
                'amount' => 120,
                'claimDate' => '2026-04-12',
            ]],
            'payroll_snapshot' => [
                'basic' => 2600,
                'net' => 2100,
            ],
        ];

        $first = $this->postJson('/api/payroll/claims', $payload);
        $first->assertStatus(201);
        $firstClaimId = (int) $first->json('data.id');

        $second = $this->postJson('/api/payroll/claims', $payload);
        $second->assertOk();
        $second->assertJsonPath('data.id', $firstClaimId);
        $second->assertJsonPath('data.idempotent_replay', true);

        $this->assertSame(
            1,
            PayrollClaim::query()
                ->where('user_id', $user->id)
                ->where('submission_key', 'salary-submit-key-001')
                ->count(),
        );
    }

    public function test_claim_submit_idempotent_replay_does_not_recreate_or_reconsume_draft(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        $draft = $this->createPayrollDraft($user, [
            'claim_type' => 'salary',
            'draft_id' => 'draft-idempotent-consume-1',
        ]);

        $payload = [
            'claim_type' => 'salary',
            'period' => 'April 2026',
            'period_value' => '2026-04',
            'submission_key' => 'salary-submit-key-consume-1',
            'source_draft_id' => 'draft-idempotent-consume-1',
            'source_draft_type' => 'salary',
            'items' => [],
            'payroll_snapshot' => [
                'basic' => 2600,
                'net' => 2100,
            ],
        ];

        $first = $this->postJson('/api/payroll/claims', $payload);
        $first->assertStatus(201);
        $firstClaimId = (int) $first->json('data.id');
        $first->assertJsonPath('data.consumed_draft_id', 'draft-idempotent-consume-1');
        $this->assertDatabaseMissing('payroll_claim_drafts', ['id' => $draft->id]);

        $second = $this->postJson('/api/payroll/claims', $payload);
        $second->assertOk();
        $second->assertJsonPath('data.id', $firstClaimId);
        $second->assertJsonPath('data.idempotent_replay', true);
        $second->assertJsonPath('data.consumed_draft_id', null);

        $this->assertSame(
            1,
            PayrollClaim::query()
                ->where('user_id', $user->id)
                ->where('submission_key', 'salary-submit-key-consume-1')
                ->count(),
        );
    }

    public function test_draft_store_generates_and_reuses_draft_id_when_missing(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        $first = $this->postJson('/api/payroll/claims/drafts', [
            'claim_type' => 'expense',
            'payload' => [
                'claimType' => 'expense',
                'period' => '2026-04',
                'periodConfirmed' => true,
                'savedItems' => [],
                'draftItem' => ['category' => 'Fuel', 'amount' => '10'],
            ],
        ]);
        $first->assertOk();
        $generatedDraftId = (string) $first->json('data.draft_id');
        $this->assertNotSame('', trim($generatedDraftId));

        $second = $this->postJson('/api/payroll/claims/drafts', [
            'claim_type' => 'expense',
            'payload' => [
                'claimType' => 'expense',
                'period' => '2026-04',
                'periodConfirmed' => true,
                'savedItems' => [],
                'draftItem' => ['category' => 'Fuel', 'amount' => '25'],
            ],
        ]);
        $second->assertOk();
        $second->assertJsonPath('data.draft_id', $generatedDraftId);

        $this->assertSame(
            1,
            PayrollClaimDraft::query()
                ->where('user_id', $user->id)
                ->where('claim_type', 'expense')
                ->count(),
        );
    }

    public function test_payslip_totals_use_explicit_salary_totals_without_item_or_baseline_fallback(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        $claim = $this->createPayrollClaim($user, [
            'claim_type' => 'salary',
            'status' => 'Approved',
            'period_value' => '2026-04',
            'amount' => 2600,
            'approved_overtime_payout' => 30,
            'adjustments_total' => 25,
            'projected_net_payout' => 777,
            'payroll_snapshot' => [
                'basic' => 2600,
                'net' => 2100,
            ],
        ]);

        PayrollClaimItem::query()->create([
            'payroll_claim_id' => $claim->id,
            'line_no' => 1,
            'item_type' => 'Addition',
            'title' => 'Large addition',
            'claim_date' => '2026-04-10',
            'amount' => 500,
            'notes' => 'manual',
            'item_meta' => [],
            'attachment_id' => null,
        ]);
        PayrollClaimItem::query()->create([
            'payroll_claim_id' => $claim->id,
            'line_no' => 2,
            'item_type' => 'Deduction',
            'title' => 'Large deduction',
            'claim_date' => '2026-04-11',
            'amount' => 100,
            'notes' => 'manual',
            'item_meta' => [],
            'attachment_id' => null,
        ]);

        $response = $this->getJson('/api/payroll/payslips');
        $response->assertOk();

        $row = collect($response->json('data'))
            ->first(fn ($entry) => (int) ($entry['payslip_id'] ?? 0) === (int) $claim->id);

        $this->assertIsArray($row);
        $this->assertSame(25.0, (float) data_get($row, 'adjustments_total'));
        $this->assertSame(25.0, (float) data_get($row, 'totals.adjustmentsTotal'));
        $this->assertSame(777.0, (float) data_get($row, 'projected_net_payout'));
        $this->assertSame(777.0, (float) data_get($row, 'totals.netPayable'));
        $this->assertSame(400.0, (float) collect(data_get($row, 'adjustments', []))->sum('signedAmount'));
    }

    public function test_salary_claim_format_defaults_null_salary_totals_to_zero(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        $claim = $this->createPayrollClaim($user, [
            'claim_type' => 'salary',
            'adjustments_total' => null,
            'projected_net_payout' => null,
            'period_value' => '2026-04',
        ]);

        $response = $this->getJson('/api/payroll/claims');
        $response->assertOk();

        $row = collect($response->json('data'))
            ->first(fn ($entry) => (int) ($entry['id'] ?? 0) === (int) $claim->id);

        $this->assertIsArray($row);
        $this->assertSame(0.0, (float) data_get($row, 'adjustments_total'));
        $this->assertSame(0.0, (float) data_get($row, 'projected_net_payout'));
    }

    public function test_live_smoke_salary_draft_submit_has_no_ghost_draft_and_correct_totals(): void
    {
        $user = $this->createPayrollUser();
        $this->actingAs($user);

        $draftSave = $this->postJson('/api/payroll/claims/drafts', [
            'claim_type' => 'salary',
            'payload' => [
                'claimType' => 'salary',
                'period' => '2026-04',
                'periodConfirmed' => true,
                'savedItems' => [[
                    'claimType' => 'Addition',
                    'title' => 'Draft adjustment',
                    'claimDate' => '2026-04-10',
                    'amount' => 10,
                    'lineNotes' => 'draft row',
                ]],
                'draftItem' => [],
                'updatedAt' => now()->toIso8601String(),
            ],
        ]);
        $draftSave->assertOk();
        $draftId = trim((string) $draftSave->json('data.draft_id'));
        $this->assertNotSame('', $draftId);
        $this->assertDatabaseHas('payroll_claim_drafts', [
            'user_id' => $user->id,
            'claim_type' => 'salary',
            'draft_id' => $draftId,
        ]);

        $submissionKey = 'smoke-salary-submit-key-001';
        $submitPayload = [
            'claim_type' => 'salary',
            'period' => 'April 2026',
            'period_value' => '2026-04',
            'submission_key' => $submissionKey,
            'source_draft_id' => $draftId,
            'source_draft_type' => 'salary',
            'items' => [
                [
                    'claimType' => 'Addition',
                    'title' => 'Transport top-up',
                    'claimDate' => '2026-04-11',
                    'amount' => 80,
                    'lineNotes' => 'manual addition',
                ],
                [
                    'claimType' => 'Deduction',
                    'title' => 'Penalty',
                    'claimDate' => '2026-04-12',
                    'amount' => 30,
                    'lineNotes' => 'manual deduction',
                ],
            ],
            'payroll_snapshot' => [
                'basic' => 2600,
                'net' => 2000,
            ],
        ];

        $submit = $this->postJson('/api/payroll/claims', $submitPayload);
        $submit->assertStatus(201);
        $submit->assertJsonPath('data.consumed_draft_id', $draftId);
        $submit->assertJsonPath('data.idempotent_replay', false);
        $submit->assertJsonPath('data.adjustments_total', 50);
        $submit->assertJsonPath('data.projected_net_payout', 2050);
        $submittedClaimId = (int) $submit->json('data.id');
        $this->assertGreaterThan(0, $submittedClaimId);

        $draftsAfterSubmit = $this->getJson('/api/payroll/claims/drafts?claim_type=salary');
        $draftsAfterSubmit->assertOk();
        $draftRows = collect($draftsAfterSubmit->json('data'));
        $this->assertSame(0, $draftRows->count(), 'Expected no salary drafts after submit.');

        $claimsIndex = $this->getJson('/api/payroll/claims');
        $claimsIndex->assertOk();
        $submittedRow = collect($claimsIndex->json('data'))
            ->first(fn ($entry) => (int) ($entry['id'] ?? 0) === $submittedClaimId);
        $this->assertIsArray($submittedRow);
        $this->assertSame(50.0, (float) data_get($submittedRow, 'adjustments_total'));
        $this->assertSame(2050.0, (float) data_get($submittedRow, 'projected_net_payout'));

        $replay = $this->postJson('/api/payroll/claims', $submitPayload);
        $replay->assertOk();
        $replay->assertJsonPath('data.id', $submittedClaimId);
        $replay->assertJsonPath('data.idempotent_replay', true);
        $replay->assertJsonPath('data.consumed_draft_id', null);
        $this->assertSame(
            1,
            PayrollClaim::query()
                ->where('user_id', $user->id)
                ->where('submission_key', $submissionKey)
                ->count(),
        );

        $draftsAfterReplay = $this->getJson('/api/payroll/claims/drafts?claim_type=salary');
        $draftsAfterReplay->assertOk();
        $this->assertSame(0, count($draftsAfterReplay->json('data') ?? []));
    }

    private function createPayrollUser(array $overrides = []): User
    {
        $permission = Permission::query()->firstOrCreate(
            ['name' => 'self.payroll', 'guard_name' => 'web'],
            ['name' => 'self.payroll', 'guard_name' => 'web'],
        );
        $role = Role::query()->firstOrCreate(
            ['name' => 'Payroll Self-Service Tester', 'guard_name' => 'web'],
            ['name' => 'Payroll Self-Service Tester', 'guard_name' => 'web'],
        );
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }

        $defaults = [
            'status' => 'Active',
            'phone' => '0123456789',
            'ic_number' => '900101-01-1234',
            'statutory_info' => [
                'epfNo' => 'EPF-112233',
                'perkesoNo' => 'SOC-112233',
                'incomeTaxNo' => 'TAX-112233',
            ],
        ];

        $user = User::factory()->create(array_merge($defaults, $overrides));
        $user->assignRole($role);

        return $user;
    }

    private function createPayrollClaim(User $user, array $overrides = []): PayrollClaim
    {
        $base = [
            'user_id' => $user->id,
            'display_id' => 'CLM-2026-'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
            'claim_type' => 'salary',
            'category' => 'Salary',
            'period' => 'March 2026',
            'period_value' => '2026-03',
            'amount' => 1000,
            'approved_overtime_payout' => 0,
            'status' => 'Pending',
            'submitted_at' => now(),
            'submitted_by' => $user->name,
            'submitted_by_name' => $user->name,
            'updated_by' => $user->name,
            'updated_by_name' => $user->name,
            'workflow_stage' => 'check',
            'workflow_snapshot' => [
                'checkRole' => 'Admin',
                'reviewRole' => 'Finance',
                'approveRole' => 'Contract Manager',
            ],
            'next_action_role' => 'Admin',
            'approval_history' => [],
            'payroll_snapshot' => ['basic' => 1000, 'net' => 1000],
            'overtime_rows' => [],
            'overtime_rate_snapshot' => null,
            'notes' => 'Test payroll claim',
            'attachment_id' => null,
        ];

        return PayrollClaim::query()->create(array_merge($base, $overrides));
    }

    private function createSalaryAssignment(User $user, array $overrides = []): SalaryAssignment
    {
        $base = [
            'reference_id' => null,
            'employee_user_id' => $user->id,
            'status' => 'Active',
            'effective_from' => now()->startOfMonth()->toDateString(),
            'basic_salary' => 0,
            'allowance_total' => 0,
            'allowances' => [],
            'employee_contributions' => [],
            'employer_contributions' => [],
            'notes_history' => [],
            'updated_by' => 'System',
        ];

        return SalaryAssignment::query()->create(array_merge($base, $overrides));
    }

    private function createPayrollDraft(User $user, array $overrides = []): PayrollClaimDraft
    {
        $base = [
            'user_id' => $user->id,
            'claim_type' => 'salary',
            'draft_id' => 'draft-'.strtolower((string) \Illuminate\Support\Str::uuid()),
            'payload' => [
                'id' => 'draft-test',
                'claimType' => 'salary',
                'period' => '2026-04',
                'periodConfirmed' => true,
                'updatedAt' => now()->toIso8601String(),
            ],
            'saved_at' => now(),
        ];

        return PayrollClaimDraft::query()->create(array_merge($base, $overrides));
    }
}
