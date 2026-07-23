<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FitnessTestReportViewBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_fitness_review_and_json_export_use_a_shared_ordered_view_model(): void
    {
        $user = $this->reporter();
        $payload = $this->phase9FitnessPayload($user);

        $this->actingAs($user)
            ->postJson('/api/reports', [
                'report_uid' => 'fitness-phase9-1',
                'display_id' => 'FIT-PHASE9-001',
                'report_type' => 'fitness-test',
                'status' => 'Submitted',
                'payload' => $payload,
            ])
            ->assertCreated();

        $detailResponse = $this->actingAs($user)->getJson('/api/reports/fitness-phase9-1')->assertOk();
        $exportResponse = $this->actingAs($user)->postJson('/api/reports/fitness-test/export', [
            'report_uid' => 'fitness-phase9-1',
        ])->assertOk();
        $export = json_decode((string) $exportResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $detailData = $detailResponse->json('data');
        $this->assertSame('2026-07', $detailData['reportingMonth']);
        $this->assertSame($detailData['reportingMonth'], $export['reportingMonth']);
        $this->assertSame('DOC-FIT-VIEW-001', $export['documentReference']);
        $this->assertSame('v2', $export['protocolRevision']);

        $this->assertSame(['group-shift-b', 'group-shift-a'], array_column($detailData['shiftGroups'], 'id'));
        $this->assertSame(['group-shift-b', 'group-shift-a'], array_column($export['shiftGroups'], 'id'));

        $detailCheckpointOrder = array_column($detailData['shiftGroups'][0]['participants'][1]['proficiency']['checkpoints'], 'checkpointCode');
        $exportCheckpointOrder = array_column($export['shiftGroups'][0]['participants'][1]['proficiency']['checkpoints'], 'checkpointCode');
        $this->assertSame(['CP1', 'CP2', 'CP10'], $detailCheckpointOrder);
        $this->assertSame(['CP1', 'CP2', 'CP10'], $exportCheckpointOrder);

        $this->assertSame(3, $detailData['participantCount']);
        $this->assertSame(1, $detailData['passedAssessmentCount']);
        $this->assertSame(2, $detailData['failedAssessmentCount']);
        $this->assertSame(0, $detailData['incompleteAssessmentCount']);
        $this->assertSame($detailData['completionStatistics'], $export['completionStatistics']);

        $this->assertSame($detailData['completionStatistics'], [
            'participantCount' => 3,
            'passedAssessmentCount' => 1,
            'failedAssessmentCount' => 2,
            'incompleteAssessmentCount' => 0,
        ]);
    }

    public function test_fitness_export_html_and_pdf_formats_return_expected_outputs(): void
    {
        $user = $this->reporter();
        $payload = $this->phase9FitnessPayload($user);

        $this->actingAs($user)
            ->postJson('/api/reports', [
                'report_uid' => 'fitness-phase9-2',
                'display_id' => 'FIT-PHASE9-002',
                'report_type' => 'fitness-test',
                'status' => 'Submitted',
                'payload' => $payload,
            ])
            ->assertCreated();

        $json = $this->actingAs($user)->postJson('/api/reports/fitness-test/export', [
            'report_uid' => 'fitness-phase9-2',
            'format' => 'json',
        ])->assertOk();
        $jsonPayload = json_decode((string) $json->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('FIT-PHASE9-002', $jsonPayload['displayId']);
        $this->assertSame('Submitted', $jsonPayload['status']);
        $this->assertSame(3, $jsonPayload['completionStatistics']['participantCount']);

        $html = $this->actingAs($user)->postJson('/api/reports/fitness-test/export', [
            'report_uid' => 'fitness-phase9-2',
            'format' => 'html',
        ])->assertOk();
        $html->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->assertStringContainsString('<h1>Fitness Test Report - FIT-PHASE9-002</h1>', $html->getContent());
        $this->assertStringContainsString('Alpha', $html->getContent());
        $this->assertStringContainsString('CP1', $html->getContent());

        $pdf = $this->actingAs($user)->postJson('/api/reports/fitness-test/export', [
            'report_uid' => 'fitness-phase9-2',
            'format' => 'pdf',
        ])->assertOk();
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('.pdf', (string) $pdf->headers->get('content-disposition'));

        $xlsx = $this->actingAs($user)->postJson('/api/reports/fitness-test/export', [
            'report_uid' => 'fitness-phase9-2',
            'format' => 'xlsx',
        ])->assertOk();
        $xlsx->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertStringStartsWith('PK', $xlsx->getContent());
        $this->assertStringContainsString('.xlsx', (string) $xlsx->headers->get('content-disposition'));
    }

    public function test_fitness_export_rejects_draft_report_and_unsupported_formats(): void
    {
        $user = $this->reporter();
        $payload = $this->phase9FitnessPayload($user);

        $this->actingAs($user)
            ->postJson('/api/reports', [
                'report_uid' => 'fitness-phase9-3',
                'display_id' => 'FIT-PHASE9-003',
                'report_type' => 'fitness-test',
                'status' => 'Draft',
                'payload' => $payload,
            ])
            ->assertCreated();

        $draftResponse = $this->actingAs($user)->postJson('/api/reports/fitness-test/export', [
            'report_uid' => 'fitness-phase9-3',
            'format' => 'json',
        ]);
        $draftResponse->assertStatus(422);
        $this->assertSame('REPORT_EXPORT_UNAVAILABLE', $draftResponse->json('code'));

        $invalidFormatResponse = $this->actingAs($user)->postJson('/api/reports/fitness-test/export', [
            'report_uid' => 'fitness-phase9-3',
            'format' => 'csv',
        ]);
        $invalidFormatResponse->assertStatus(422);
        $this->assertSame('REPORT_EXPORT_FORMAT_UNSUPPORTED', $invalidFormatResponse->json('code'));

        $json = $this->actingAs($user)->postJson('/api/reports/fitness-test/export', [
            'report_uid' => 'fitness-phase9-3',
            'format' => 'json',
        ]);
        $this->assertSame(422, $json->getStatusCode());
    }

    private function phase9FitnessPayload(User $assessor): array
    {
        return [
            'schemaVersion' => 1,
            'reportingMonth' => '2026-07',
            'documentReference' => 'DOC-FIT-VIEW-001',
            'protocolRevision' => 'v2',
            'shiftGroups' => [
                [
                    'id' => 'group-shift-b',
                    'shiftName' => 'Shift B',
                    'assessor' => ['userId' => (string) $assessor->id, 'name' => (string) $assessor->name],
                    'participants' => [
                        [
                            'id' => 'participant-a',
                            'userId' => (string) $assessor->id,
                            'name' => 'Alpha',
                            'role' => 'SC',
                            'source' => 'roster',
                            'ageSnapshot' => 28,
                            'fitness' => [
                                'sitUps' => 12,
                                'jumpingJacks' => 15,
                                'pushUps' => 10,
                                'testedOn' => '2026-07-01',
                                'result' => 'passed',
                            ],
                            'proficiency' => [
                                'durationSeconds' => 90,
                                'testedOn' => '2026-07-01',
                                'result' => 'passed',
                                'checkpoints' => [
                                    ['checkpointCode' => 'CP5', 'completed' => true, 'durationSeconds' => 10],
                                    ['checkpointCode' => 'CP1', 'completed' => true, 'durationSeconds' => 20],
                                ],
                            ],
                        ],
                        [
                            'id' => 'participant-b',
                            'userId' => (string) $assessor->id,
                            'name' => 'Beta',
                            'role' => 'SC',
                            'source' => 'roster',
                            'ageSnapshot' => 31,
                            'fitness' => [
                                'sitUps' => 6,
                                'jumpingJacks' => 8,
                                'pushUps' => 4,
                                'testedOn' => '2026-07-01',
                                'result' => 'failed',
                            ],
                            'proficiency' => [
                                'durationSeconds' => 60,
                                'testedOn' => '2026-07-01',
                                'result' => 'passed',
                                'checkpoints' => [
                                    ['checkpointCode' => 'CP10', 'completed' => true, 'durationSeconds' => 9],
                                    ['checkpointCode' => 'CP2', 'completed' => false, 'durationSeconds' => 12],
                                    ['checkpointCode' => 'CP1', 'completed' => true, 'durationSeconds' => 4],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'group-shift-a',
                    'shiftName' => 'Shift A',
                    'assessor' => ['userId' => (string) $assessor->id, 'name' => (string) $assessor->name],
                    'participants' => [
                        [
                            'id' => 'participant-c',
                            'userId' => (string) $assessor->id,
                            'name' => 'Gamma',
                            'role' => 'SC',
                            'source' => 'roster',
                            'ageSnapshot' => 30,
                            'fitness' => [
                                'sitUps' => 8,
                                'jumpingJacks' => 11,
                                'pushUps' => 7,
                                'testedOn' => '2026-07-01',
                                'result' => 'failed',
                            ],
                            'proficiency' => [
                                'durationSeconds' => 75,
                                'testedOn' => '2026-07-01',
                                'result' => 'failed',
                                'checkpoints' => [
                                    ['checkpointCode' => 'CP3', 'completed' => true, 'durationSeconds' => 8],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'reportDate' => '2026-07-01',
            'reportTime' => '09:00',
            'weather' => 'Routine',
            'incidentType' => 'Endurance Test',
            'location' => 'Training Yard',
            'details' => 'Initial fitness test.',
            'summary' => 'Shift report.',
            'chronology' => [['time' => '09:00', 'action' => 'Started']],
        ];
    }

    private function reporter(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $permission = Permission::query()->firstOrCreate([
            'name' => 'reports.fitness.view',
            'guard_name' => 'web',
        ]);
        $exportPermission = Permission::query()->firstOrCreate([
            'name' => 'reports.fitness.export',
            'guard_name' => 'web',
        ]);
        $role = Role::query()->firstOrCreate([
            'name' => 'Fitness View Reporter',
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        if (! $role->hasPermissionTo($exportPermission)) {
            $role->givePermissionTo($exportPermission);
        }
        $user->assignRole($role);

        return $user;
    }
}
