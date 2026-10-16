<?php

namespace Tests\Feature;

use App\Jobs\SendInvitationJob;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Invitation delivery under an asynchronous-style setup: SMS/WhatsApp are sent
 * inline within the job so the delivery rows reach a terminal state and
 * `sent_at` is set correctly, instead of remaining `queued` (which would let the
 * unsent scheduler re-dispatch and duplicate the invitation).
 */
class InvitationAsyncTest extends TestCase
{
    use RefreshDatabase;

    private function guest(array $attributes = []): Guest
    {
        $event = Event::factory()->create();

        return Guest::factory()->create(array_merge([
            'event_id' => $event->id,
            'email' => 'guest@example.test',
            'phone' => '+255700000001',
            'sent_at' => null,
        ], $attributes));
    }

    private function invitationRow(Guest $guest, string $channel): ?InvitationDelivery
    {
        return InvitationDelivery::where('guest_id', $guest->id)
            ->where('kind', 'invitation')
            ->where('channel', $channel)
            ->latest('id')
            ->first();
    }

    public function test_sms_invitation_is_sent_inline_and_sets_sent_at_without_queueing(): void
    {
        Bus::fake();

        config([
            'mail.default' => 'array',
            'invitations.channels' => ['sms'],
            'delivery_lab.enabled' => true,
            'delivery_lab.sandbox' => true,
            'delivery_lab.recipients' => ['+255700000001'],
        ]);

        $guest = $this->guest(['email' => null]);

        (new SendInvitationJob($guest))->handle();

        Bus::assertNotDispatched(SendQueuedNotifications::class);

        $row = $this->invitationRow($guest, 'sms');
        $this->assertNotNull($row);
        $this->assertSame('sent', $row->status);
        $this->assertNotNull($guest->fresh()->sent_at);
    }

    public function test_email_invitation_still_sets_sent_at(): void
    {
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);

        $guest = $this->guest(['phone' => null]);

        (new SendInvitationJob($guest))->handle();

        $row = $this->invitationRow($guest, 'mail');
        $this->assertSame('sent', $row->status);
        $this->assertNotNull($guest->fresh()->sent_at);
    }

    public function test_a_second_invitation_job_is_a_noop(): void
    {
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);

        $guest = $this->guest(['phone' => null]);

        (new SendInvitationJob($guest))->handle();
        $rows = InvitationDelivery::where('guest_id', $guest->id)->where('kind', 'invitation')->count();

        (new SendInvitationJob($guest->fresh()))->handle();

        $this->assertSame($rows, InvitationDelivery::where('guest_id', $guest->id)->where('kind', 'invitation')->count());
    }

    public function test_mixed_outcome_sets_sent_at_when_one_channel_is_accepted(): void
    {
        config([
            'mail.default' => 'array',
            'invitations.channels' => ['mail', 'sms'],
            'invitations.sms.endpoint' => null,
        ]);

        $guest = $this->guest();

        (new SendInvitationJob($guest))->handle();

        $this->assertSame('sent', $this->invitationRow($guest, 'mail')->status);
        $this->assertSame('failed', $this->invitationRow($guest, 'sms')->status);
        // At least one accepted channel → the guest is marked invited.
        $this->assertNotNull($guest->fresh()->sent_at);
    }

    public function test_unconfigured_only_channel_does_not_mark_invited(): void
    {
        config([
            'mail.default' => 'array',
            'invitations.channels' => ['sms'],
            'invitations.sms.endpoint' => null,
        ]);

        $guest = $this->guest(['email' => null]);

        (new SendInvitationJob($guest))->handle();

        $this->assertSame('failed', $this->invitationRow($guest, 'sms')->status);
        $this->assertNull($guest->fresh()->sent_at);
    }
}
