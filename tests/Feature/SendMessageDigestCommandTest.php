<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use App\Notifications\MessageDigestNotification;
use Carbon\Carbon;
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

    public function test_digest_leaves_messages_created_after_its_cutoff_for_the_next_run(): void
    {
        Notification::fake();
        config(['mail.message_digest.enabled' => true]);

        $cutoff = Carbon::parse('2026-07-20 09:00:00');
        Carbon::setTestNow($cutoff);

        $sender = User::factory()->create();
        $recipient = User::factory()->create([
            'status' => 'active',
            'last_message_digest_at' => $cutoff->copy()->subHour(),
        ]);

        Message::query()->create([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => $recipient->id,
            'subject' => 'Before cutoff',
            'body' => 'Included in the first digest.',
        ]);
        $afterCutoff = Message::query()->create([
            'sender_user_id' => $sender->id,
            'recipient_user_id' => $recipient->id,
            'subject' => 'After cutoff',
            'body' => 'Held for the next digest.',
        ]);
        $afterCutoff->forceFill(['created_at' => $cutoff->copy()->addMinute()])->saveQuietly();

        $this->artisan('messages:digest')->assertSuccessful();

        $firstDigest = Notification::sent($recipient, MessageDigestNotification::class)->first();
        $firstRendered = (string) $firstDigest->toMail($recipient)->render();
        $this->assertStringContainsString('Included in the first digest.', $firstRendered);
        $this->assertStringNotContainsString('Held for the next digest.', $firstRendered);
        $this->assertTrue($recipient->fresh()->last_message_digest_at->equalTo($cutoff));

        Carbon::setTestNow($cutoff->copy()->addMinutes(2));
        $this->artisan('messages:digest')->assertSuccessful();

        $digests = Notification::sent($recipient, MessageDigestNotification::class);
        $this->assertCount(2, $digests);
        $secondRendered = (string) $digests->last()->toMail($recipient)->render();
        $this->assertStringContainsString('Held for the next digest.', $secondRendered);

        Carbon::setTestNow();
    }
}
