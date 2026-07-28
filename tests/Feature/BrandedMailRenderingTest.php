<?php

namespace Tests\Feature;

use App\Mail\WorkflowDigestNotificationMail;
use App\Mail\WorkflowImmediateNotificationMail;
use App\Models\FeedbackReport;
use App\Models\User;
use App\Models\WorkflowNotification;
use App\Notifications\AdminResetPasswordNotification;
use App\Notifications\FeedbackReportSubmittedNotification;
use App\Notifications\MessageDigestNotification;
use Carbon\Carbon;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Collection;
use Tests\TestCase;

class BrandedMailRenderingTest extends TestCase
{
    public function test_immediate_workflow_email_uses_the_branded_shell_details_and_primary_button(): void
    {
        $recipient = new User(['name' => 'Azam Bin Husain', 'email' => 'azam@example.test']);
        $notification = new WorkflowNotification([
            'module' => 'inspection',
            'event_type' => 'submitted',
            'record_display_id' => 'INS-05-2872026',
            'title' => 'Request submitted',
            'message' => 'azam submitted Inspection INS-05-2872026.',
        ]);
        $actionUrl = 'https://app.example.test/inspection/report-123';

        $mail = new WorkflowImmediateNotificationMail($notification, $recipient, $actionUrl);
        $html = (string) $mail->render();

        $this->assertSame('[Inspection Workflow] Request submitted', $mail->subject);
        $this->assertBrandedShell($html);
        $this->assertStringContainsString('Inspection workflow', $html);
        $this->assertStringContainsString('Request submitted', $html);
        $this->assertStringContainsString('INS-05-2872026', $html);
        $this->assertStringContainsString('Open workflow item', $html);
        $this->assertStringContainsString('href="'.$actionUrl.'"', $html);
        $this->assertStringContainsString('background-color: #007e7a', $html);

        $text = (string) app(Markdown::class)->renderText('emails.workflow-immediate', [
            'notification' => $notification,
            'recipient' => $recipient,
            'actionUrl' => $actionUrl,
        ]);
        $this->assertStringContainsString('Module:', $text);
        $this->assertStringContainsString('INS-05-2872026', $text);
        $this->assertStringContainsString('Open workflow item: '.$actionUrl, $text);
        $this->assertStringNotContainsString('<table', $text);
        $this->assertSame(1, substr_count($text, $notification->message));
    }

    public function test_workflow_content_is_escaped_in_the_branded_email(): void
    {
        $recipient = new User(['name' => 'Reviewer']);
        $notification = new WorkflowNotification([
            'module' => 'inspection',
            'event_type' => 'submitted',
            'record_display_id' => '<script>alert("record")</script>',
            'title' => 'Request submitted',
            'message' => '<script>alert("message")</script>',
        ]);

        $html = (string) (new WorkflowImmediateNotificationMail(
            $notification,
            $recipient,
            'https://app.example.test/inspection/report-123',
        ))->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_workflow_digest_uses_the_same_shell_without_oversized_item_buttons(): void
    {
        $recipient = new User(['name' => 'Workflow Reviewer']);
        $reminders = new Collection([
            [
                'module' => 'inspection',
                'count' => 1,
                'items' => new Collection([
                    [
                        'title' => 'Request submitted',
                        'recordDisplayId' => 'INS-100',
                        'deepLink' => 'https://app.example.test/inspection/report-100',
                    ],
                ]),
            ],
        ]);

        $mail = new WorkflowDigestNotificationMail(
            $recipient,
            collect(),
            $reminders,
            Carbon::parse('2026-07-28 06:00:00'),
            Carbon::parse('2026-07-28 18:00:00'),
        );
        $html = (string) $mail->render();

        $this->assertBrandedShell($html);
        $this->assertStringContainsString('Pending action reminders', $html);
        $this->assertStringContainsString('INS-100', $html);
        $this->assertStringContainsString('href="https://app.example.test/inspection/report-100"', $html);
        $this->assertStringNotContainsString('Open workflow item', $html);
    }

    public function test_system_notifications_share_the_branded_shell(): void
    {
        $recipient = new User([
            'name' => 'System User',
            'email' => 'system.user@example.test',
        ]);

        $messageDigest = (string) (new MessageDigestNotification(
            1,
            [['name' => 'Operations', 'count' => 1]],
            [['name' => 'Operations', 'time' => '14:30', 'snippet' => 'Please review the update.']],
        ))->toMail($recipient)->render();

        $adminReset = (string) (new AdminResetPasswordNotification(
            'reset-token',
            'System Administrator',
            'admin@example.test',
        ))->toMail($recipient)->render();

        $defaultReset = (string) (new ResetPassword('reset-token'))
            ->toMail($recipient)
            ->render();

        $reporter = new User(['name' => 'Feedback Reporter', 'email' => 'reporter@example.test']);
        $feedbackReport = new FeedbackReport([
            'message' => 'The mobile action needs a clearer success state.',
            'page_context' => ['title' => 'Inspection', 'path' => '/inspection'],
        ]);
        $feedbackReport->created_at = now();
        $feedbackReport->setRelation('reporter', $reporter);
        $feedback = (string) (new FeedbackReportSubmittedNotification(
            $feedbackReport,
            'https://app.example.test/admin/feedback-reports',
        ))->toMail($recipient)->render();

        foreach ([$messageDigest, $adminReset, $defaultReset, $feedback] as $html) {
            $this->assertBrandedShell($html);
        }

        $this->assertStringContainsString('Open Messages', $messageDigest);
        $this->assertStringContainsString('Reset Password', $adminReset);
        $this->assertStringContainsString('Reset Password', $defaultReset);
        $this->assertStringContainsString('Review Feedback Reports', $feedback);
    }

    private function assertBrandedShell(string $html): void
    {
        $this->assertStringContainsString('VMECC OS', $html);
        $this->assertStringContainsString('border-top: 4px solid #007e7a', $html);
        $this->assertStringContainsString('Sent automatically by', $html);
        $this->assertStringContainsString('Please do not reply to this message.', $html);
    }
}
