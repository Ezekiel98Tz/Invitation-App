<?php

namespace Tests\Feature;

use App\Jobs\SendInvitationJob;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DeliveryLabGuardTest extends TestCase
{
    use RefreshDatabase;

    private function guest(array $attributes = []): Guest
    {
        $event = Event::factory()->create();

        return Guest::factory()->create(array_merge(['event_id' => $event->id], $attributes));
    }

    private function invitationChannelList(Guest $guest): array
    {
        return (new InvitationNotification($guest->event, $guest->invite_url_absolute))->via($guest);
    }

    public function test_lab_is_disabled_by_default_and_delivery_is_unchanged(): void
    {
        // config/delivery_lab.php defaults to disabled; nothing was set in this test.
        $this->assertFalse((bool) config('delivery_lab.enabled'));

        $guest = $this->guest(['email' => 'anyone@example.test', 'phone' => '+255700000002']);

        $this->assertContains('mail', $this->invitationChannelList($guest));
    }

    public function test_lab_blocks_non_allowlisted_email(): void
    {
        config(['delivery_lab.enabled' => true, 'delivery_lab.recipients' => ['allowed@example.test']]);
        $guest = $this->guest(['email' => 'other@example.test']);

        $this->assertSame([], $this->invitationChannelList($guest));
    }

    public function test_lab_allows_allowlisted_email_case_insensitively(): void
    {
        config(['delivery_lab.enabled' => true, 'delivery_lab.recipients' => ['Allowed@Example.Test']]);
        $guest = $this->guest(['email' => 'allowed@example.test']);

        $this->assertContains('mail', $this->invitationChannelList($guest));
    }

    public function test_lab_blocks_non_allowlisted_phone_but_keeps_allowed_mail(): void
    {
        config([
            'invitations.channels' => ['mail', 'sms'],
            'delivery_lab.enabled' => true,
            'delivery_lab.recipients' => ['allowed@example.test'],
        ]);
        $guest = $this->guest(['email' => 'allowed@example.test', 'phone' => '+255700000002']);

        $channels = $this->invitationChannelList($guest);

        $this->assertContains('mail', $channels);
        $this->assertNotContains(SmsChannel::class, $channels);
    }

    public function test_lab_allows_allowlisted_phone_for_sms(): void
    {
        config([
            'invitations.channels' => ['mail', 'sms'],
            'delivery_lab.enabled' => true,
            'delivery_lab.recipients' => ['+255 700-000-001'],
        ]);
        $guest = $this->guest(['email' => null, 'phone' => '+255700000001']);

        $this->assertContains(SmsChannel::class, $this->invitationChannelList($guest));
    }

    public function test_blocked_job_sends_nothing_and_does_not_mark_guest_sent(): void
    {
        Http::fake();
        Notification::fake();

        config([
            'invitations.channels' => ['mail', 'sms'],
            'invitations.sms.endpoint' => 'https://sms.example.test/send',
            'delivery_lab.enabled' => true,
            'delivery_lab.recipients' => ['someone@example.test'], // does not match the guest
        ]);

        $guest = $this->guest(['email' => 'blocked@example.test', 'phone' => '+255700000099']);

        (new SendInvitationJob($guest))->handle();

        // No live fallback, no simulated "sent", no delivery rows.
        Http::assertNothingSent();
        Notification::assertNothingSent();
        $this->assertSame(0, InvitationDelivery::where('guest_id', $guest->id)->count());
        $this->assertNull($guest->fresh()->sent_at);
    }

    public function test_lab_with_empty_allowlist_blocks_everything(): void
    {
        Http::fake();
        Notification::fake();

        config([
            'invitations.channels' => ['mail', 'sms'],
            'invitations.sms.endpoint' => 'https://sms.example.test/send',
            'delivery_lab.enabled' => true,
            'delivery_lab.recipients' => [],
        ]);

        $guest = $this->guest(['email' => 'anyone@example.test', 'phone' => '+255700000001']);

        (new SendInvitationJob($guest))->handle();

        Http::assertNothingSent();
        Notification::assertNothingSent();
        $this->assertSame(0, InvitationDelivery::where('guest_id', $guest->id)->count());
        $this->assertNull($guest->fresh()->sent_at);
    }

    public function test_allowlisted_job_delivers_normally(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['message_id' => 'lab-1'], 200)]);

        config([
            'mail.default' => 'array',
            'invitations.channels' => ['mail', 'sms'],
            'invitations.sms.endpoint' => 'https://sms.example.test/send',
            'delivery_lab.enabled' => true,
            'delivery_lab.recipients' => ['allowed@example.test', '+255700000001'],
        ]);

        $guest = $this->guest(['email' => 'allowed@example.test', 'phone' => '+255700000001']);

        (new SendInvitationJob($guest))->handle();

        $this->assertNotNull($guest->fresh()->sent_at);
        $this->assertSame(2, InvitationDelivery::where('guest_id', $guest->id)->count());

        $sms = InvitationDelivery::where('guest_id', $guest->id)->where('channel', 'sms')->first();
        $this->assertNotNull($sms);
        $this->assertSame('sent', $sms->status);
        $this->assertSame('lab-1', $sms->provider_message_id);

        Http::assertSentCount(1);
    }

    public function test_sms_channel_guard_blocks_non_allowlisted_destination_directly(): void
    {
        Http::fake();

        config([
            'invitations.sms.endpoint' => 'https://sms.example.test/send',
            'delivery_lab.enabled' => true,
            'delivery_lab.recipients' => ['+255700000001'],
        ]);

        $guest = $this->guest(['email' => null, 'phone' => '+255700000002']);
        $notification = new InvitationNotification($guest->event, $guest->invite_url_absolute);

        (new SmsChannel())->send($guest, $notification);

        Http::assertNothingSent();
    }
}
