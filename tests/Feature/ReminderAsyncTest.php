<?php

namespace Tests\Feature;

use App\Jobs\SendReminderJob;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Reminder lifecycle under an asynchronous-style setup: channel sends happen
 * inline within the job so `reminded_at` reflects an accepted channel instead
 * of staying null while a nested notification job is pending.
 */
class ReminderAsyncTest extends TestCase
{
    use RefreshDatabase;

    private function guest(array $attributes = []): Guest
    {
        $event = Event::factory()->create();

        return Guest::factory()->create(array_merge([
            'event_id' => $event->id,
            'email' => null,
            'phone' => '+255700000001',
            'status' => 'pending',
            'reminded_at' => null,
        ], $attributes));
    }

    public function test_reminder_marks_reminded_inline_without_queueing_a_notification(): void
    {
        Bus::fake();

        config([
            'mail.default' => 'array',
            'invitations.channels' => ['sms'],
            'delivery_lab.enabled' => true,
            'delivery_lab.sandbox' => true,
            'delivery_lab.recipients' => ['+255700000001'],
        ]);

        $guest = $this->guest();

        (new SendReminderJob($guest))->handle();

        Bus::assertNotDispatched(SendQueuedNotifications::class);

        $delivery = InvitationDelivery::where('guest_id', $guest->id)->where('kind', 'reminder')->firstOrFail();
        $this->assertSame('sent', $delivery->status);
        $this->assertNotNull($guest->fresh()->reminded_at);
    }

    public function test_a_second_reminder_job_is_a_noop(): void
    {
        config([
            'mail.default' => 'array',
            'invitations.channels' => ['sms'],
            'delivery_lab.enabled' => true,
            'delivery_lab.sandbox' => true,
            'delivery_lab.recipients' => ['+255700000001'],
        ]);

        $guest = $this->guest();

        (new SendReminderJob($guest))->handle();
        $rows = InvitationDelivery::where('guest_id', $guest->id)->where('kind', 'reminder')->count();

        (new SendReminderJob($guest->fresh()))->handle();

        $this->assertSame($rows, InvitationDelivery::where('guest_id', $guest->id)->where('kind', 'reminder')->count());
    }

    public function test_unconfigured_channel_does_not_mark_reminded(): void
    {
        config([
            'mail.default' => 'array',
            'invitations.channels' => ['sms'],
            'invitations.sms.endpoint' => null,
        ]);

        $guest = $this->guest();

        (new SendReminderJob($guest))->handle();

        $delivery = InvitationDelivery::where('guest_id', $guest->id)->where('kind', 'reminder')->firstOrFail();
        $this->assertSame('failed', $delivery->status);
        $this->assertNull($guest->fresh()->reminded_at);
    }
}
