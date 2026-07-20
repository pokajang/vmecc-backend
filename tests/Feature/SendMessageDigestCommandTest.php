<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use App\Notifications\MessageDigestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendMessageDigestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_sends_only_when_enabled_and_does_not_repeat_old_unread_messages(): void
    {
        Notification::fake();

        $sender = User::factory()->create();
        $recipient = User::factory()->create([
            'status' => 'active',
            'last_message_digest_at' => now()->subHour(),
        ]);

        $oldMessage = Message::query()->create([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => $recipient->id,
            'subject' => 'Old unread message',
            'body' => 'Old digest body already covered.',
        ]);
        $oldMessage->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();
        Message::query()->create([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => $recipient->id,
            'subject' => 'New unread message',
            'body' => 'New digest body should be included.',
        ]);

        config(['mail.message_digest.enabled' => false]);
        $this->artisan('messages:digest')->assertSuccessful();
        Notification::assertNothingSent();

        config(['mail.message_digest.enabled' => true]);
        $this->artisan('messages:digest')->assertSuccessful();

        Notification::assertSentTo($recipient, MessageDigestNotification::class);

        $notification = Notification::sent($recipient, MessageDigestNotification::class)->first();
        $rendered = (string) $notification->toMail($recipient)->render();

        $this->assertStringContainsString('New digest body should be included.', $rendered);
        $this->assertStringNotContainsString('Old digest body already covered.', $rendered);
        $this->assertNotNull($recipient->fresh()->last_message_digest_at);
    }
}
