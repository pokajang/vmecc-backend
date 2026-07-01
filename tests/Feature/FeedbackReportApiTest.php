<?php

namespace Tests\Feature;

use App\Models\FeedbackReport;
use App\Models\User;
use App\Notifications\FeedbackReportSubmittedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FeedbackReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_submit_feedback_report(): void
    {
        Notification::fake();

        $reporter = User::factory()->create([
            'status' => 'active',
            'email' => 'reporter@example.test',
        ]);
        $admin = $this->systemAdministrator();

        $this->actingAs($reporter);

        $response = $this->postJson('/api/feedback-reports', [
            'message' => 'The inspection page button overlaps on mobile view.',
            'page_context' => [
                'path' => '/inspection',
                'search' => '?tab=records',
                'title' => 'Inspection',
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', FeedbackReport::STATUS_NEW)
            ->assertJsonPath('data.reporter.email', 'reporter@example.test')
            ->assertJsonPath('data.page.path', '/inspection');

        $this->assertDatabaseHas('feedback_reports', [
            'reporter_user_id' => $reporter->id,
            'message' => 'The inspection page button overlaps on mobile view.',
            'status' => FeedbackReport::STATUS_NEW,
        ]);

        $report = FeedbackReport::query()->firstOrFail();
        $this->assertSame('/inspection', $report->page_context['path'] ?? null);
        $this->assertSame('?tab=records', $report->page_context['search'] ?? null);
        $this->assertSame('Inspection', $report->page_context['title'] ?? null);

        Notification::assertSentTo(
            [$admin],
            FeedbackReportSubmittedNotification::class,
        );
    }

    public function test_sysadmin_email_notification_is_dispatched_on_submission(): void
    {
        Notification::fake();

        $reporter = User::factory()->create([
            'status' => 'active',
            'email' => 'submitter@example.test',
        ]);
        $admin = $this->systemAdministrator([
            'email' => 'sysadmin@example.test',
        ]);

        $this->actingAs($reporter);

        $this->postJson('/api/feedback-reports', [
            'message' => 'Need a clearer success state after saving draft changes.',
            'page_context' => [
                'path' => '/report/drill',
                'search' => '',
                'title' => 'Drill',
            ],
        ])->assertCreated();

        Notification::assertSentTo(
            [$admin],
            FeedbackReportSubmittedNotification::class,
            function (FeedbackReportSubmittedNotification $notification, array $channels, User $notifiable) {
                $mail = $notification->toMail($notifiable);

                return in_array('mail', $channels, true)
                    && $mail->markdown === 'emails.feedback-report-submitted'
                    && str_contains((string) ($mail->viewData['adminUrl'] ?? ''), '/admin/feedback-reports');
            },
        );
    }

    public function test_inactive_sysadmins_do_not_receive_feedback_report_email(): void
    {
        Notification::fake();

        $reporter = User::factory()->create([
            'status' => 'active',
            'email' => 'submitter@example.test',
        ]);
        $inactiveAdmin = $this->systemAdministrator([
            'status' => 'inactive',
            'email' => 'inactive-sysadmin@example.test',
        ]);

        $this->actingAs($reporter);

        $this->postJson('/api/feedback-reports', [
            'message' => 'Need a clearer error state after report submission failure.',
            'page_context' => [
                'path' => '/dashboard',
                'search' => '',
                'title' => 'Dashboard',
            ],
        ])->assertCreated();

        Notification::assertNotSentTo(
            [$inactiveAdmin],
            FeedbackReportSubmittedNotification::class,
        );
    }

    public function test_non_sysadmins_cannot_list_view_or_update_feedback_reports(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $report = FeedbackReport::query()->create([
            'reporter_user_id' => $user->id,
            'message' => 'A feedback report for access testing.',
            'status' => FeedbackReport::STATUS_NEW,
        ]);

        $employee = User::factory()->create(['status' => 'active']);
        $this->actingAs($employee);

        $this->getJson('/api/feedback-reports')->assertForbidden();
        $this->getJson("/api/feedback-reports/{$report->id}")->assertForbidden();
        $this->patchJson("/api/feedback-reports/{$report->id}", [
            'status' => FeedbackReport::STATUS_REVIEWING,
            'admin_note' => 'Checking',
        ])->assertForbidden();
    }

    public function test_sysadmin_can_update_feedback_report_lifecycle(): void
    {
        $reporter = User::factory()->create(['status' => 'active']);
        $admin = $this->systemAdministrator();
        $report = FeedbackReport::query()->create([
            'reporter_user_id' => $reporter->id,
            'message' => 'Feedback report lifecycle test payload.',
            'status' => FeedbackReport::STATUS_NEW,
            'page_context' => ['path' => '/dashboard', 'title' => 'Dashboard'],
        ]);

        $this->actingAs($admin);

        $this->getJson('/api/feedback-reports')
            ->assertOk()
            ->assertJsonPath('meta.counts.new', 1);

        $this->getJson("/api/feedback-reports/{$report->id}")
            ->assertOk()
            ->assertJsonPath('data.status', FeedbackReport::STATUS_NEW);

        foreach ([FeedbackReport::STATUS_REVIEWING, FeedbackReport::STATUS_RESOLVED, FeedbackReport::STATUS_DISMISSED] as $status) {
            $response = $this->patchJson("/api/feedback-reports/{$report->id}", [
                'status' => $status,
                'admin_note' => "Moved to {$status}.",
            ]);

            $response
                ->assertOk()
                ->assertJsonPath('data.status', $status)
                ->assertJsonPath('data.admin_note', "Moved to {$status}.");
        }

        $report->refresh();
        $this->assertSame(FeedbackReport::STATUS_DISMISSED, $report->status);
        $this->assertSame($admin->id, $report->reviewed_by);
        $this->assertNotNull($report->reviewed_at);
    }

    private function systemAdministrator(array $attributes = []): User
    {
        $role = Role::firstOrCreate(['name' => 'System Administrator', 'guard_name' => 'web']);

        $user = User::factory()->create(array_merge([
            'status' => 'active',
            'email' => 'sysadmin@example.test',
        ], $attributes));
        $user->assignRole($role);

        return $user;
    }
}
