<?php

namespace App\Console\Commands;

use App\Services\WorkflowNotifications\WorkflowEmailModuleGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class CheckEmailProductionReadiness extends Command
{
    protected $signature = 'email:production-readiness
        {--send-to= : Send a live SMTP probe after all readiness checks pass}';

    protected $description = 'Validate production email flags, SMTP configuration, and queue settings.';

    public function handle(): int
    {
        $checks = $this->checks();

        $this->table(
            ['Check', 'Result', 'Detail'],
            collect($checks)->map(fn (array $check, string $name) => [
                $name,
                $check['ok'] ? 'PASS' : 'FAIL',
                $check['detail'],
            ])->values()->all(),
        );

        if (collect($checks)->contains(fn (array $check) => ! $check['ok'])) {
            $this->error('Email production readiness failed. Correct the failed checks before deployment.');

            return self::FAILURE;
        }

        $probeRecipient = trim((string) $this->option('send-to'));
        if ($probeRecipient !== '') {
            if (filter_var($probeRecipient, FILTER_VALIDATE_EMAIL) === false) {
                $this->error('The --send-to value must be a valid email address.');

                return self::INVALID;
            }

            try {
                Mail::raw(
                    'VMECC production email readiness probe completed successfully at '.now()->toIso8601String().'.',
                    function ($message) use ($probeRecipient) {
                        $message->to($probeRecipient)->subject('VMECC email readiness probe');
                    },
                );
                $this->info("SMTP probe accepted for {$probeRecipient}.");
            } catch (Throwable $exception) {
                $this->error('SMTP probe failed: '.$exception->getMessage());

                return self::FAILURE;
            }
        }

        $this->info('Email production readiness passed.');

        return self::SUCCESS;
    }

    /** @return array<string, array{ok: bool, detail: string}> */
    private function checks(): array
    {
        $mailer = trim((string) config('mail.default'));
        $host = trim((string) config('mail.mailers.smtp.host'));
        $username = trim((string) config('mail.mailers.smtp.username'));
        $password = trim((string) config('mail.mailers.smtp.password'));
        $from = trim((string) config('mail.from.address'));
        $queue = trim((string) config('queue.default'));
        $moduleGates = (array) config('mail.workflow_notifications.modules', []);
        $unavailableModules = collect(WorkflowEmailModuleGate::MODULES)
            ->reject(fn (string $module) => array_key_exists($module, $moduleGates) && (bool) $moduleGates[$module])
            ->values()
            ->all();

        return [
            'Application environment' => [
                'ok' => app()->environment('production'),
                'detail' => 'APP_ENV must be production.',
            ],
            'SMTP mailer' => [
                'ok' => $mailer === 'smtp',
                'detail' => "Configured mailer: {$mailer}",
            ],
            'SMTP host' => [
                'ok' => $host !== ''
                    && ! in_array(strtolower($host), ['localhost', '127.0.0.1', 'mailpit', 'mailhog'], true)
                    && ! str_contains(strtoupper($host), 'REPLACE_WITH'),
                'detail' => $host !== '' ? $host : 'Missing MAIL_HOST',
            ],
            'SMTP credentials' => [
                'ok' => $username !== ''
                    && $password !== ''
                    && ! str_contains(strtoupper($username.$password), 'REPLACE_WITH'),
                'detail' => 'MAIL_USERNAME and MAIL_PASSWORD must contain production credentials.',
            ],
            'From address' => [
                'ok' => filter_var($from, FILTER_VALIDATE_EMAIL) !== false
                    && ! str_ends_with(strtolower($from), '@example.com'),
                'detail' => $from !== '' ? $from : 'Missing MAIL_FROM_ADDRESS',
            ],
            'Workflow email' => [
                'ok' => (bool) config('mail.workflow_notifications.enabled', false),
                'detail' => 'WORKFLOW_EMAIL_ENABLED must be true.',
            ],
            'Workflow modules' => [
                'ok' => $unavailableModules === [],
                'detail' => $unavailableModules === []
                    ? 'All required modules are enabled.'
                    : 'Missing or disabled: '.implode(', ', $unavailableModules),
            ],
            'Message digest' => [
                'ok' => (bool) config('mail.message_digest.enabled', false),
                'detail' => 'MESSAGE_DIGEST_EMAIL_ENABLED must be true.',
            ],
            'Asynchronous queue' => [
                'ok' => $queue !== '' && ! in_array($queue, ['sync', 'null'], true),
                'detail' => "Configured queue: {$queue}",
            ],
        ];
    }
}
