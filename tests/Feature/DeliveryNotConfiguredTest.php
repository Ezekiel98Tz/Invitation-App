<?php

namespace Tests\Feature;

use App\Jobs\SendInvitationJob;
use App\Jobs\SendReminderJob;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An unavailable/unconfigured transport must be recorded as a failure and must
 * never leave a misleading "guest was invited/reminded" marker behind.
 */
class DeliveryNotConfiguredTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_sms_is_recorded_as_failed_and_does_not_mark_guest_sent(): void
    {
        config([
            'mail.default' => 'array',
            'invitations.channels' => ['sms'],
            'invitations.sms.endpoint' => null,
        ]);

        $guest = Guest::factory()->create([
            'email' => null,
            'phone' => '+255700000001',
            'sent_at' => null,
        ]);

        (new SendInvitationJob($guest))->handle();

        $delivery = InvitationDelivery::where('guest_id', $guest->id)->where('channel', 'sms')->firstOrFail();

        $this->assertSame('failed', $delivery->status);
        $this->assertSame('not_configured', $delivery->meta['provider']['reason']);
        $this->assertNotSame('sent', $delivery->status);
        $this->assertNull($guest->fresh()->sent_at);
    }

    public function test_unconfigured_whatsapp_reminder_does_not_mark_guest_reminded(): void
    {
        config([
            'mail.default' => 'array',
            'invitations.channels' => ['whatsapp'],
            'invitations.whatsapp.endpoint' => null,
        ]);

        $guest = Guest::factory()->create([
            'email' => null,
            'phone' => '+255700000001',
            'status' => 'pending',
            'reminded_at' => null,
        ]);

        (new SendReminderJob($guest))->handle();

        $delivery = InvitationDelivery::where('guest_id', $guest->id)->where('channel', 'whatsapp')->firstOrFail();

        $this->assertSame('failed', $delivery->status);
        $this->assertNull($guest->fresh()->reminded_at);
    }

    public function test_a_configured_channel_still_marks_the_guest_sent(): void
    {
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);

        $guest = Guest::factory()->create(['email' => 'guest@example.test', 'sent_at' => null]);

        (new SendInvitationJob($guest))->handle();

        $delivery = InvitationDelivery::where('guest_id', $guest->id)->where('channel', 'mail')->firstOrFail();

        $this->assertSame('sent', $delivery->status);
        $this->assertNotNull($guest->fresh()->sent_at);
    }
}
