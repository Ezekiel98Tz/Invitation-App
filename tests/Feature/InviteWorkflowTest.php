<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Jobs\SendInvitationJob;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class InviteWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_generates_invite_token_on_create(): void
    {
        $guest = Guest::factory()->create(['invite_token' => null]);

        $this->assertNotNull($guest->invite_token);
        $this->assertSame(32, strlen($guest->invite_token));
    }

    public function test_invite_show_renders_for_pending_guest(): void
    {
        $guest = Guest::factory()->create(['status' => 'pending']);

        $this->get(route('invites.show', ['token' => $guest->invite_token]))
            ->assertOk()
            ->assertViewIs('invite.show');
    }

    public function test_invite_store_accept_creates_rsvp_and_updates_guest(): void
    {
        $event = Event::factory()->create([
            'settings' => [
                'allow_plus_ones' => true,
                'custom_questions' => [
                    ['key' => 'diet', 'type' => 'text', 'required' => true],
                ],
                'enable_waitlist' => false,
            ],
        ]);

        $guest = Guest::factory()->create([
            'event_id' => $event->id,
            'status' => 'pending',
        ]);

        $this->post(route('invites.store', ['token' => $guest->invite_token]), [
            'status' => 'accepted',
            'attending_count' => 2,
            'answers' => ['diet' => 'vegetarian'],
        ])->assertOk()->assertViewIs('invite.confirmed');

        $guest->refresh();

        $this->assertSame('accepted', $guest->status);

        $rsvp = Rsvp::where('guest_id', $guest->id)->first();
        $this->assertNotNull($rsvp);
        $this->assertSame(2, $rsvp->attending_count);
        $this->assertSame(['diet' => 'vegetarian'], $rsvp->answers);
    }

    public function test_guest_can_update_rsvp_before_event_starts(): void
    {
        $event = Event::factory()->create();
        $guest = Guest::factory()->create(['event_id' => $event->id, 'status' => 'pending']);

        $this->post(route('invites.store', ['token' => $guest->invite_token]), [
            'status' => 'accepted',
        ])->assertOk()->assertViewIs('invite.confirmed');

        $guest->refresh();
        $this->assertSame('accepted', $guest->status);

        $this->post(route('invites.store', ['token' => $guest->invite_token]), [
            'status' => 'declined',
        ])->assertOk()->assertViewIs('invite.confirmed');

        $guest->refresh();
        $this->assertSame('declined', $guest->status);
    }

    public function test_capacity_is_enforced_when_accepting(): void
    {
        $event = Event::factory()->create(['capacity' => 1]);

        $accepted = Guest::factory()->create(['event_id' => $event->id, 'status' => 'accepted']);
        $accepted->rsvp()->create(['guest_id' => $accepted->id, 'attending_count' => 1]);

        $pending = Guest::factory()->create(['event_id' => $event->id, 'status' => 'pending']);

        $this->from(route('invites.show', ['token' => $pending->invite_token]))
            ->post(route('invites.store', ['token' => $pending->invite_token]), [
                'status' => 'accepted',
            ])
            ->assertRedirect(route('invites.show', ['token' => $pending->invite_token]))
            ->assertSessionHasErrors('status');

        $pending->refresh();
        $this->assertSame('pending', $pending->status);
    }

    public function test_invites_are_locked_after_event_start(): void
    {
        $event = Event::factory()->create(['event_start' => now()->subMinute()]);
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $this->get(route('invites.show', ['token' => $guest->invite_token]))
            ->assertStatus(410)
            ->assertViewIs('invite.expired');

        $this->post(route('invites.store', ['token' => $guest->invite_token]), ['status' => 'declined'])
            ->assertStatus(410)
            ->assertViewIs('invite.expired');
    }

    public function test_event_capacity_check_uses_rsvp_attending_count(): void
    {
        $event = Event::factory()->create(['capacity' => 2]);

        $g1 = Guest::factory()->create(['event_id' => $event->id, 'status' => 'accepted']);
        $g1->rsvp()->create(['guest_id' => $g1->id, 'attending_count' => 1]);

        $g2 = Guest::factory()->create(['event_id' => $event->id, 'status' => 'accepted']);
        $g2->rsvp()->create(['guest_id' => $g2->id, 'attending_count' => 1]);

        $event->refresh();

        $this->assertTrue($event->isAtCapacity(1));
    }

    public function test_send_invitation_job_marks_sent_at_and_sends_notification(): void
    {
        Notification::fake();

        $guest = Guest::factory()->create(['sent_at' => null]);

        (new SendInvitationJob($guest))->handle();

        Notification::assertSentTo($guest, InvitationNotification::class);

        $guest->refresh();
        $this->assertNotNull($guest->sent_at);
    }
}
