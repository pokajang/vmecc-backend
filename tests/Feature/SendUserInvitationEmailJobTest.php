<?php

namespace Tests\Feature;

use App\Jobs\SendUserInvitationEmailJob;
use App\Models\User;
use App\Models\UserInvitationDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class SendUserInvitationEmailJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_sends_invitation_and_marks_delivery_as_sent(): void
    {
        $user = User::factory()->create([
            'name' => 'Queued User',
            'email' => 'queued.user@example.test',
            'status' => 'Active',
        ]);
        $delivery = UserInvitationDelivery::create([
            'user_id' => $user->id,
            'recipient_email' => $user->email,
            'status' => 'queued',
            'attempts' => 0,
        ]);

        Notification::fake();

        (new SendUserInvitationEmailJob($delivery->id))->handle();

        $delivery->refresh();
        $this->assertSame('sent', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNull($delivery->last_error);
        $this->assertNotNull($delivery->sent_at);
        Notification::assertSentTo($user, \App\Notifications\UserInvitationNotification::class);
    }

    public function test_job_marks_delivery_failed_when_user_or_email_is_invalid(): void
    {
        $user = User::factory()->create(['status' => 'Active']);
        $deliveryForMissingUser = UserInvitationDelivery::create([
            'user_id' => $user->id,
            'recipient_email' => 'missing.user@example.test',
            'status' => 'queued',
        ]);

        $user->forceDelete();

        (new SendUserInvitationEmailJob($deliveryForMissingUser->id))->handle();
        $deliveryForMissingUser->refresh();
        $this->assertSame('failed', $deliveryForMissingUser->status);
        $this->assertSame(1, $deliveryForMissingUser->attempts);

        $activeUser = User::factory()->create(['status' => 'Active']);
        $deliveryForMissingEmail = UserInvitationDelivery::create([
            'user_id' => $activeUser->id,
            'recipient_email' => '   ',
            'status' => 'queued',
        ]);

        (new SendUserInvitationEmailJob($deliveryForMissingEmail->id))->handle();
        $deliveryForMissingEmail->refresh();
        $this->assertSame('failed', $deliveryForMissingEmail->status);
        $this->assertSame(1, $deliveryForMissingEmail->attempts);
    }

    public function test_job_marks_delivery_failed_when_send_fails_and_rethrows_exception(): void
    {
        config(['mail.default' => 'invalid']);

        $user = User::factory()->create([
            'name' => 'Broken Mail User',
            'email' => 'broken.mail@example.test',
            'status' => 'Active',
        ]);
        $delivery = UserInvitationDelivery::create([
            'user_id' => $user->id,
            'recipient_email' => $user->email,
            'status' => 'queued',
            'attempts' => 0,
        ]);

        $thrown = null;
        try {
            (new SendUserInvitationEmailJob($delivery->id))->handle();
        } catch (RuntimeException | \Throwable $exception) {
            $thrown = $exception;
        }

        $delivery->refresh();
        $this->assertNotNull($thrown);
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotEmpty($delivery->last_error);
    }

    public function test_job_failed_hook_marks_delivery_as_failed(): void
    {
        $user = User::factory()->create(['status' => 'Active']);
        $delivery = UserInvitationDelivery::create([
            'user_id' => $user->id,
            'recipient_email' => $user->email,
            'status' => 'queued',
        ]);
        $job = new SendUserInvitationEmailJob($delivery->id);

        $job->failed(new RuntimeException('Permanent failure'));

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('Permanent failure', $delivery->last_error);
    }
}
