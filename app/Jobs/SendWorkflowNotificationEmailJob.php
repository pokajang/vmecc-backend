<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WorkflowEmailDelivery;
use App\Models\WorkflowNotification;
use App\Services\AssignmentAuthorizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendWorkflowNotificationEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(private readonly int $notificationId)
    {
    }

    public function handle(): void
    {
        if (!config('mail.workflow_notifications.enabled', false)) {
            return;
        }

        $notification = WorkflowNotification::find($this->notificationId);
        if (!$notification) {
            return;
        }

        if (!$this->isEmailEnabledFor(
            (string) ($notification->module ?? ''),
            (string) ($notification->record_type ?? ''),
        )) {
            return;
        }

        $recipientIds = collect($notification->recipient_user_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($recipientIds->isEmpty()) {
            return;
        }

        $emails = User::query()
            ->whereIn('id', $recipientIds->all())
            ->whereNotNull('email')
            ->with(['roleAssignments.role'])
            ->get()
            ->mapWithKeys(function (User $user) {
                $email = strtolower(trim((string) $user->email));
                if ($email === '') return [];
                return [$email => $user];
            });

        if ($emails->isEmpty()) {
            return;
        }

        $frontendBase = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $fallbackUrl = $frontendBase !== '' ? "{$frontendBase}/notifications/workflow" : '/notifications/workflow';
        $authorizationService = app(AssignmentAuthorizationService::class);

        $subject = sprintf('[%s Workflow] %s', ucfirst((string) $notification->module), (string) $notification->title);

        foreach ($emails as $email => $recipientUser) {
            $actionUrl = $this->buildDeepLink(
                $notification,
                $recipientUser,
                $authorizationService,
                $frontendBase,
                $fallbackUrl,
            );
            $lines = [
                (string) $notification->message,
                '',
                'Module: ' . (string) $notification->module,
                'Event: ' . (string) $notification->event_type,
                $notification->record_display_id ? 'Record ID: ' . (string) $notification->record_display_id : null,
                'Action link: ' . $actionUrl,
            ];
            $body = collect($lines)->filter(fn ($line) => $line !== null)->implode("\n");

            $delivery = WorkflowEmailDelivery::create([
                'notification_id' => $notification->id,
                'recipient_email' => $email,
                'status' => 'queued',
                'attempts' => 0,
            ]);

            try {
                Mail::raw($body, function ($mail) use ($email, $subject) {
                    $mail->to($email)->subject($subject);
                });

                $delivery->update([
                    'status' => 'sent',
                    'attempts' => (int) $delivery->attempts + 1,
                    'sent_at' => now(),
                    'last_error' => null,
                ]);
            } catch (\Throwable $exception) {
                $delivery->update([
                    'status' => 'failed',
                    'attempts' => (int) $delivery->attempts + 1,
                    'last_error' => $exception->getMessage(),
                ]);

                Log::warning('Workflow notification email dispatch failed.', [
                    'notification_id' => $notification->id,
                    'recipient_email' => $email,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function buildDeepLink(
        WorkflowNotification $notification,
        User $recipient,
        AssignmentAuthorizationService $authorizationService,
        string $frontendBase,
        string $fallbackUrl,
    ): string {
        $metadata = is_array($notification->metadata) ? $notification->metadata : [];
        $module = strtolower(trim((string) ($notification->module ?? '')));
        $displayId = trim((string) ($notification->record_display_id ?? ''));
        $recordId = (int) ($notification->record_id ?? 0);
        $ownerUserId = (int) ($notification->owner_user_id ?? 0);
        $recordType = strtolower(trim((string) ($notification->record_type ?? '')));

        $viewerRoles = $authorizationService->getActiveRoleNames($recipient)
            ->map(fn ($role) => strtolower(trim((string) $role)))
            ->filter()
            ->values()
            ->all();
        $requiredRole = strtolower(trim((string) ($metadata['nextActionRole'] ?? $metadata['next_action_role'] ?? '')));
        $isSystemAdministrator = in_array('system administrator', $viewerRoles, true);
        $isActionRoleRecipient =
            $notification->action_required &&
            $requiredRole !== '' &&
            ($isSystemAdministrator || in_array($requiredRole, $viewerRoles, true));

        if ($module === 'overtime') {
            if ($isActionRoleRecipient && $ownerUserId > 0 && $recordId > 0) {
                return $this->asAbsolute(
                    "/staff/overtime-management/record/" . rawurlencode("{$ownerUserId}::{$recordId}"),
                    $frontendBase,
                );
            }
            if ($displayId !== '') {
                return $this->asAbsolute('/overtime/' . rawurlencode($displayId), $frontendBase);
            }
            return $this->asAbsolute('/overtime', $frontendBase);
        }

        if (in_array($module, ['salary', 'expense', 'exceptional'], true)) {
            if ($recordType === 'salary_assignment') {
                if ($recordId > 0 && $isActionRoleRecipient) {
                    return $this->asAbsolute(
                        '/staff/salary-claims/assignment/' . rawurlencode((string) $recordId) . '/view',
                        $frontendBase,
                    );
                }
                if ($recordId > 0) {
                    return $this->asAbsolute(
                        '/staff/salary-claims/set-salary?assignmentId=' . rawurlencode((string) $recordId),
                        $frontendBase,
                    );
                }
                return $this->asAbsolute('/staff/salary-claims/set-salary', $frontendBase);
            }

            $claimKey = $displayId !== '' ? $displayId : ($recordId > 0 ? (string) $recordId : '');
            if ($claimKey !== '' && $isActionRoleRecipient) {
                return $this->asAbsolute('/staff/salary-claims/claim/' . rawurlencode($claimKey), $frontendBase);
            }
            if ($claimKey !== '') {
                return $this->asAbsolute('/payroll/claims/' . rawurlencode($claimKey), $frontendBase);
            }
            return $this->asAbsolute('/payroll/claims', $frontendBase);
        }

        return $fallbackUrl;
    }

    private function asAbsolute(string $path, string $frontendBase): string
    {
        if ($frontendBase === '') return $path;
        return "{$frontendBase}{$path}";
    }

    private function isEmailEnabledFor(string $module, string $recordType): bool
    {
        $moduleGates = config('mail.workflow_notifications.modules', []);
        if (!is_array($moduleGates) || empty($moduleGates)) {
            return true;
        }

        $normalizedModule = strtolower(trim($module));
        $normalizedRecordType = strtolower(trim($recordType));

        if ($normalizedRecordType !== '' && array_key_exists($normalizedRecordType, $moduleGates)) {
            return (bool) $moduleGates[$normalizedRecordType];
        }

        if ($normalizedModule !== '' && array_key_exists($normalizedModule, $moduleGates)) {
            return (bool) $moduleGates[$normalizedModule];
        }

        return false;
    }
}
