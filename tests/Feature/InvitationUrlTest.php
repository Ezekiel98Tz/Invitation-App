<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Notifications\ReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationUrlTest extends TestCase
{
    use RefreshDatabase;

    private function guest(): Guest
    {
        return Guest::factory()->create(['event_id' => Event::factory()->create()->id]);
    }

    public function test_invite_url_absolute_uses_application_base_url_and_relative_stays_relative(): void
    {
        $guest = $this->guest();

        $absolute = $guest->invite_url_absolute;
        $relative = $guest->invite_url;

        // Absolute form is resolved from the configured application URL.
        $this->assertSame(route('invites.show', ['token' => $guest->invite_token]), $absolute);
        $this->assertStringStartsWith(config('app.url'), $absolute);
        $this->assertStringContainsString('/invite/'.$guest->invite_token, $absolute);
        $this->assertStringStartsWith('http', $absolute);

        // Relative form is preserved for same-origin browser links.
        $this->assertSame(route('invites.show', ['token' => $guest->invite_token], absolute: false), $relative);
        $this->assertStringStartsWith('/', $relative);
        $this->assertNotSame($absolute, $relative);

        // Tokens are unchanged by URL resolution.
        $this->assertSame(32, strlen((string) $guest->invite_token));
    }

    public function test_invitation_notification_preview_uses_absolute_url(): void
    {
        $guest = $this->guest();
        $notification = new InvitationNotification($guest->event, $guest->invite_url_absolute);

        $preview = $notification->preview();

        $this->assertSame($guest->invite_url_absolute, $preview['mail']['action_url']);
        $this->assertStringStartsWith(config('app.url'), $preview['mail']['action_url']);
        $this->assertStringContainsString($guest->invite_url_absolute, $preview['sms']['message']);
        $this->assertStringContainsString($guest->invite_url_absolute, $preview['whatsapp']['message']);
    }

    public function test_reminder_notification_preview_uses_absolute_url(): void
    {
        $guest = $this->guest();
        $notification = new ReminderNotification($guest->event, $guest->invite_url_absolute);

        $preview = $notification->preview();

        $this->assertSame($guest->invite_url_absolute, $preview['mail']['action_url']);
        $this->assertStringStartsWith(config('app.url'), $preview['mail']['action_url']);
        $this->assertStringContainsString($guest->invite_url_absolute, $preview['sms']['message']);
    }

    public function test_invitation_preview_endpoint_returns_absolute_url(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $response = $this->actingAs($owner)
            ->getJson(route('api.events.invitations.preview', $event))
            ->assertOk();

        $actionUrl = $response->json('channels.mail.action_url');

        $this->assertIsString($actionUrl);
        $this->assertStringStartsWith(config('app.url'), $actionUrl);
        $this->assertStringContainsString('/invite/'.$guest->invite_token, $actionUrl);
    }
}
