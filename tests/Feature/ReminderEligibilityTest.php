<?php

namespace Tests\Feature;

use App\Jobs\SendReminderJob;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Reminder eligibility and the "provider acceptance != delivery" rule.
 *
 * Documented rules:
 *  - Individual (manual, operator-triggered) reminder: eligible when the guest
 *    is `pending` and has not been reminded yet. It deliberately does NOT
 *    require `sent_at` (an operator may have invited the guest out of band) —
 *    this is the manual override.
 *  - Bulk reminder (endpoint + `reminders:send` command): eligible only when
 *    the guest is `pending`, not yet reminded, AND was sent an invitation at
 *    least two days ago.
 *  - `reminded_at` is only written once a channel reached an *accepted* state
 *    (`sent`/`delivered`). Acceptance is not confirmed delivery.
 */
class ReminderEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function event(User $owner): Event
    {
        return Event::factory()->create(['user_id' => $owner->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function guest(Event $event, array $attributes = []): Guest
    {
        return Guest::factory()->create(array_merge([
            'event_id' => $event->id,
            'status' => 'pending',
            'sent_at' => null,
            'reminded_at' => null,
        ], $attributes));
    }

    // ----------------------------------------------------- individual reminder

    public function test_individual_reminder_queues_for_a_pending_unreminded_guest(): void
    {
        Queue::fake();

        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['sent_at' => now()->subDays(3)]);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.remind', ['event' => $event->id, 'guest' => $guest->id]))
            ->assertStatus(202)
            ->assertJson(['queued' => true]);

        Queue::assertPushed(SendReminderJob::class, 1);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $owner->id,
            'action' => 'guests.remind',
            'auditable_id' => $guest->id,
        ]);
    }

    public function test_individual_reminder_does_not_require_a_prior_send(): void
    {
        // Manual override: an operator may have invited the guest out of band,
        // so the individual action only requires pending + not yet reminded.
        Queue::fake();

        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['sent_at' => null]);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.remind', ['event' => $event->id, 'guest' => $guest->id]))
            ->assertStatus(202);

        Queue::assertPushed(SendReminderJob::class, 1);
    }

    public function test_individual_reminder_is_rejected_for_ineligible_guests(): void
    {
        Queue::fake();

        $owner = $this->owner();
        $event = $this->event($owner);

        $accepted = $this->guest($event, ['status' => 'accepted', 'sent_at' => now()->subDays(3)]);
        $declined = $this->guest($event, ['status' => 'declined', 'sent_at' => now()->subDays(3)]);
        $alreadyReminded = $this->guest($event, ['reminded_at' => now()->subDay()]);

        foreach ([$accepted, $declined, $alreadyReminded] as $guest) {
            $this->actingAs($owner)
                ->postJson(route('api.events.guests.remind', ['event' => $event->id, 'guest' => $guest->id]))
                ->assertStatus(422)
                ->assertJsonPath('message', 'This guest is not eligible for a reminder.');
        }

        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------- bulk (endpoint)

    public function test_bulk_reminders_require_a_send_at_least_two_days_old_and_pending_status(): void
    {
        Queue::fake();

        $owner = $this->owner();
        $event = $this->event($owner);

        $eligible = $this->guest($event, ['sent_at' => now()->subDays(3)]);
        $this->guest($event, ['sent_at' => null]); // never sent
        $this->guest($event, ['sent_at' => now()->subDay()]); // too recent
        $this->guest($event, ['status' => 'accepted', 'sent_at' => now()->subDays(3)]);
        $this->guest($event, ['sent_at' => now()->subDays(3), 'reminded_at' => now()->subDay()]); // already reminded

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.reminders', ['event' => $event->id]))
            ->assertStatus(202)
            ->assertJson(['queued' => 1]);

        Queue::assertPushed(
            SendReminderJob::class,
            fn (SendReminderJob $job) => $job->guest->id === $eligible->id
        );
        Queue::assertPushed(SendReminderJob::class, 1);
    }

    // ------------------------------------------------------------- bulk (command)

    public function test_reminders_send_command_queues_only_eligible_guests(): void
    {
        Queue::fake();

        $owner = $this->owner();
        $event = $this->event($owner);

        $eligible = $this->guest($event, ['sent_at' => now()->subDays(5)]);
        $this->guest($event, ['sent_at' => null]);
        $this->guest($event, ['sent_at' => now()->subDay()]);
        $this->guest($event, ['status' => 'declined', 'sent_at' => now()->subDays(5)]);
        $this->guest($event, ['sent_at' => now()->subDays(5), 'reminded_at' => now()->subDay()]);

        $this->artisan('reminders:send')->assertSuccessful();

        Queue::assertPushed(
            SendReminderJob::class,
            fn (SendReminderJob $job) => $job->guest->id === $eligible->id
        );
        Queue::assertPushed(SendReminderJob::class, 1);
    }

    // ------------------------------------------------------- job: set reminded_at

    public function test_reminder_job_marks_reminded_once_a_channel_is_accepted_but_not_delivery(): void
    {
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);

        $guest = $this->guest(Event::factory()->create(), [
            'email' => 'guest@example.test',
            'sent_at' => now()->subDays(3),
        ]);

        (new SendReminderJob($guest))->handle();

        $delivery = InvitationDelivery::where('guest_id', $guest->id)
            ->where('kind', 'reminder')
            ->where('channel', 'mail')
            ->firstOrFail();

        // Provider acceptance is recorded as `sent` — never `delivered`.
        $this->assertSame('sent', $delivery->status);
        $this->assertNotSame('delivered', $delivery->status);
        $this->assertNotNull($delivery->sent_at);
        $this->assertNull($delivery->delivered_at);

        $this->assertNotNull($guest->fresh()->reminded_at);
        // The invitation timestamp is never overwritten by a reminder.
        $this->assertNotNull($guest->fresh()->sent_at);
    }

    public function test_reminder_job_does_not_mark_reminded_when_no_channel_was_accepted(): void
    {
        config([
            'mail.default' => 'array',
            'invitations.channels' => ['sms'],
            'invitations.sms.endpoint' => null,
        ]);

        $guest = $this->guest(Event::factory()->create(), [
            'email' => null,
            'phone' => '+255700000001',
        ]);

        (new SendReminderJob($guest))->handle();

        $delivery = InvitationDelivery::where('guest_id', $guest->id)->where('channel', 'sms')->firstOrFail();

        $this->assertSame('failed', $delivery->status);
        $this->assertSame('not_configured', $delivery->meta['provider']['reason']);
        $this->assertNull($guest->fresh()->reminded_at);
    }

    public function test_reminder_job_is_a_noop_for_a_non_pending_guest(): void
    {
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);

        $guest = $this->guest(Event::factory()->create(), [
            'email' => 'guest@example.test',
            'status' => 'accepted',
            'sent_at' => now()->subDays(3),
        ]);

        (new SendReminderJob($guest))->handle();

        $this->assertSame(0, InvitationDelivery::where('guest_id', $guest->id)->count());
        $this->assertNull($guest->fresh()->reminded_at);
    }

    public function test_reminder_job_is_a_noop_when_already_reminded(): void
    {
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);

        $guest = $this->guest(Event::factory()->create(), [
            'email' => 'guest@example.test',
            'reminded_at' => Carbon::now()->subDay(),
        ]);

        (new SendReminderJob($guest))->handle();

        $this->assertSame(0, InvitationDelivery::where('guest_id', $guest->id)->count());
    }
}
