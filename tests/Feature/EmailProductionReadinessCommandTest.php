<?php

namespace Tests\Feature;

use App\Services\WorkflowNotifications\WorkflowEmailModuleGate;
use Tests\TestCase;

class EmailProductionReadinessCommandTest extends TestCase
{
    public function test_command_passes_with_complete_production_email_configuration(): void
    {
        app()->detectEnvironment(fn () => 'production');

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.mail-provider.test',
            'mail.mailers.smtp.username' => 'production-user',
            'mail.mailers.smtp.password' => 'production-password',
            'mail.from.address' => 'no-reply@amiosh.com',
            'mail.workflow_notifications.enabled' => true,
            'mail.workflow_notifications.modules' => array_fill_keys(WorkflowEmailModuleGate::MODULES, true),
            'mail.message_digest.enabled' => true,
            'queue.default' => 'database',
        ]);

        $this->artisan('email:production-readiness')
            ->expectsOutputToContain('Email production readiness passed.')
            ->assertSuccessful();
    }

    public function test_command_fails_when_a_required_module_is_missing(): void
    {
        app()->detectEnvironment(fn () => 'production');

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.mail-provider.test',
            'mail.mailers.smtp.username' => 'production-user',
            'mail.mailers.smtp.password' => 'production-password',
            'mail.from.address' => 'no-reply@amiosh.com',
            'mail.workflow_notifications.enabled' => true,
            'mail.workflow_notifications.modules' => array_fill_keys(
                array_diff(WorkflowEmailModuleGate::MODULES, ['inspection']),
                true,
            ),
            'mail.message_digest.enabled' => true,
            'queue.default' => 'database',
        ]);

        $this->artisan('email:production-readiness')
            ->expectsOutputToContain('Email production readiness failed.')
            ->assertFailed();
    }
}
