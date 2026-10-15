<?php

namespace Tests\Feature\Api;

use App\Jobs\SendInvitationJob;
use App\Jobs\SendReminderJob;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Notifications\ReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DeliveryRetryTest extends TestCase
{
    use RefreshDatabase;

    private function guest(array $attributes = []): Guest
    {
        $event = Event::factory()->create();

        return Guest::factory()->create(array_merge(['event_id' => $event->id], $attributes));
    }

    private function configureSmsOnly(): void
    {
        config([
            'mail.default' => 'array',
            'invitations.channels' => ['sms'],
            'invitations.sms.endpoint' => 'https://sms.example.test/send',
        ]);
    }

    public function test_duplicate_invitation_job_does_not_resend_or_duplicate_rows(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['message_id' => 'ref-1'], 200)]);
        $this->configureSmsOnly();

        $guest = $this->guest(['email' => null, 'phone' => '+255700000001', 'sent_at' => null]);

        (new SendInvitationJob($guest))->handle();
        $rows = InvitationDelivery::count();
        Http::assertSentCount(1);

        // A duplicate queued job for the same guest must be a no-op.
        (new SendInvitationJob($guest))->handle();

        $this->assertSame($rows, InvitationDelivery::count());
        Http::assertSentCount(1);
        $this->assertNotNull($guest->fresh()->sent_at);
    }

    public function test_a_deliberate_reminder_is_still_possible_after_a_previous_one(): void
    {
        Notification::fake();
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);

        $guest = $this->guest(['email' => 'guest@example.test', 'status' => 'pending', 'reminded_at' => null]);

        (new SendReminderJob($guest))->handle();
        $guest->refresh();
        $this->assertNotNull($guest->reminded_at);
        $this->assertSame(1, InvitationDelivery::where('kind', 'reminder')->count());

        // A deliberate new reminder resets reminded_at and is a new operation.
        $guest->forceFill(['reminded_at' => null])->save();
        (new SendReminderJob($guest->fresh()))->handle();

        $this->assertSame(2, InvitationDelivery::where('kind', 'reminder')->count());
        $this->assertNotNull($guest->fresh()->reminded_at);
        Notification::assertSentToTimes($guest, ReminderNotification::class, 2);
    }

    public function test_a_duplicate_reminder_job_is_a_no_op(): void
    {
        Notification::fake();
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);

        $guest = $this->guest(['email' => 'guest@example.test', 'status' => 'pending', 'reminded_at' => null]);

        (new SendReminderJob($guest))->handle();
        $rows = InvitationDelivery::where('kind', 'reminder')->count();

        (new SendReminderJob($guest->fresh()))->handle();

        $this->assertSame($rows, InvitationDelivery::where('kind', 'reminder')->count());
        Notification::assertSentToTimes($guest, ReminderNotification::class, 1);
    }

    public function test_open_for_reuses_rows_only_when_requested(): void
    {
        $guest = $this->guest();

        $first = InvitationDelivery::openFor($guest->id, 'invitation', 'sms', false);
        $reused = InvitationDelivery::openFor($guest->id, 'invitation', 'sms', true);
        $fresh = InvitationDelivery::openFor($guest->id, 'invitation', 'sms', false);

        $this->assertSame($first->id, $reused->id);
        $this->assertNotSame($first->id, $fresh->id);
        $this->assertSame(2, InvitationDelivery::where('kind', 'invitation')->count());
    }

    public function test_completed_deliveries_are_never_resent(): void
    {
        $guest = $this->guest(['sent_at' => null, 'phone' => '+255700000001', 'email' => null]);
        $delivery = InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => 'invitation',
            'channel' => 'sms',
            'status' => 'delivered',
            'provider_message_id' => 'done',
        ]);

        $this->assertTrue($delivery->isCompleted());
        $this->assertTrue(InvitationDelivery::openFor($guest->id, 'invitation', 'sms', true)->isCompleted());
    }
}
