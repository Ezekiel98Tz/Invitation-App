<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Notifications\ReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Focused coverage for the SMS and WhatsApp invitation/reminder composition.
 *
 * These tests assert the *content* requirements (readable spacing, a call to
 * action immediately before a complete unique URL, personalization, WhatsApp
 * markup) and, crucially, that the Delivery Lab preview returns exactly the
 * same text the channel receives (preview-to-delivery parity).
 */
class GuestMessageCompositionTest extends TestCase
{
    use RefreshDatabase;

    private function makeGuest(array $guest = [], array $event = []): Guest
    {
        $eventModel = Event::factory()->create(array_merge([
            'title' => 'Amina & Yusuf Wedding',
        ], $event));

        return Guest::factory()->create(array_merge([
            'event_id' => $eventModel->id,
            'name' => 'Fatima',
            'email' => 'fatima@example.test',
            'phone' => '+255700000001',
        ], $guest));
    }

    private function previewUrl(Guest $guest, string $kind, string $channel): string
    {
        return route('api.delivery-lab.preview', [
            'event' => $guest->event_id,
            'guest' => $guest->id,
            'kind' => $kind,
            'channel' => $channel,
        ]);
    }

    public function test_sms_invitation_has_readable_paragraph_breaks_and_a_complete_url(): void
    {
        $guest = $this->makeGuest();
        $message = (new InvitationNotification($guest->event, $guest->invite_url_absolute))->toSms($guest)['message'];

        $this->assertStringContainsString('Invited', $message);
        $this->assertStringContainsString($guest->event->title, $message);

        // Intentional blank lines separate the heading, invitation and CTA.
        $this->assertStringContainsString("\n\n", $message);

        // The call to action sits immediately before the URL, on the next line.
        $this->assertStringContainsString('RSVP:'."\n".$guest->invite_url_absolute, $message);

        // The complete unique URL is present exactly once and is never shortened.
        $this->assertSame(1, substr_count($message, $guest->invite_url_absolute));
        $this->assertStringStartsWith(config('app.url'), $guest->invite_url_absolute);
        $this->assertStringContainsString('/invite/'.$guest->invite_token, $message);

        // No rich-text formatting that plain SMS clients would not render.
        $this->assertStringNotContainsString('*', $message);
    }

    public function test_whatsapp_invitation_uses_blank_lines_personalization_and_a_url_on_its_own_line(): void
    {
        $guest = $this->makeGuest();
        $message = (new InvitationNotification($guest->event, $guest->invite_url_absolute))->toWhatsApp($guest)['message'];

        // WhatsApp *bold* markup and a personalized greeting.
        $this->assertStringContainsString('*You\'re Invited!*', $message);
        $this->assertStringContainsString('Hello Fatima!', $message);
        $this->assertStringContainsString('*'.$guest->event->title.'*', $message);

        // Blank lines separate greeting, invitation, CTA, URL and closing.
        $this->assertStringContainsString("\n\n", $message);

        // The URL is on its own line so it is easy to tap.
        $this->assertStringContainsString("\n".$guest->invite_url_absolute."\n", $message);
        $this->assertSame(1, substr_count($message, $guest->invite_url_absolute));
    }

    public function test_sms_reminder_is_distinct_from_the_invitation_and_keeps_the_url(): void
    {
        $guest = $this->makeGuest();
        $invitationSms = (new InvitationNotification($guest->event, $guest->invite_url_absolute))->toSms($guest)['message'];
        $reminderSms = (new ReminderNotification($guest->event, $guest->invite_url_absolute))->toSms($guest)['message'];

        $this->assertNotSame($invitationSms, $reminderSms);
        $this->assertStringContainsString('Reminder', $reminderSms);
        $this->assertStringContainsString($guest->event->title, $reminderSms);
        $this->assertStringContainsString("\n\n", $reminderSms);
        $this->assertStringContainsString('RSVP here:'."\n".$guest->invite_url_absolute, $reminderSms);
        $this->assertSame(1, substr_count($reminderSms, $guest->invite_url_absolute));
    }

    public function test_whatsapp_reminder_is_distinct_from_the_invitation_and_keeps_the_url(): void
    {
        $guest = $this->makeGuest();
        $invitationWa = (new InvitationNotification($guest->event, $guest->invite_url_absolute))->toWhatsApp($guest)['message'];
        $reminderWa = (new ReminderNotification($guest->event, $guest->invite_url_absolute))->toWhatsApp($guest)['message'];

        $this->assertNotSame($invitationWa, $reminderWa);
        $this->assertStringContainsString('Reminder', $reminderWa);
        $this->assertStringContainsString('Hello Fatima!', $reminderWa);
        $this->assertStringContainsString('*'.$guest->event->title.'*', $reminderWa);
        $this->assertStringContainsString("\n".$guest->invite_url_absolute."\n", $reminderWa);
        $this->assertSame(1, substr_count($reminderWa, $guest->invite_url_absolute));
    }

    public function test_whatsapp_omits_the_greeting_when_the_guest_name_is_missing_or_blank(): void
    {
        $event = Event::factory()->create();
        $notification = new InvitationNotification($event, 'https://example.test/invite/abc123');

        // null (attribute absent), empty string, and whitespace-only names.
        $notifiables = [
            new Guest(['name' => null]),
            new Guest(['name' => '']),
            new Guest(['name' => '   ']),
        ];

        foreach ($notifiables as $notifiable) {
            $message = $notification->whatsAppMessage($notifiable);

            $this->assertStringNotContainsString('Hello', $message);
            $this->assertStringNotContainsString('! ❤️', $message);
            $this->assertStringContainsString('*You\'re Invited!*', $message);
            $this->assertStringContainsString($event->title, $message);
            $this->assertStringContainsString('https://example.test/invite/abc123', $message);
        }
    }

    public function test_sms_composition_is_unaffected_by_a_missing_guest_name(): void
    {
        $event = Event::factory()->create();
        $notification = new InvitationNotification($event, 'https://example.test/invite/abc123');

        $named = $notification->smsMessage(new Guest(['name' => 'Fatima']));
        $unnamed = $notification->smsMessage(new Guest(['name' => null]));

        $this->assertSame($named, $unnamed);
        $this->assertStringContainsString('https://example.test/invite/abc123', $unnamed);
    }

    public function test_delivery_lab_character_count_matches_the_final_composed_sms_body(): void
    {
        Http::fake();

        $owner = User::factory()->create();
        $guest = $this->makeGuest([], ['user_id' => $owner->id]);

        $response = $this->actingAs($owner)
            ->getJson($this->previewUrl($guest, 'invitation', 'sms'))
            ->assertOk();

        $message = (string) $response->json('content.message');

        $this->assertStringContainsString("\n", $message);
        $this->assertSame(mb_strlen($message), $response->json('content.character_count'));
        $this->assertSame($guest->invite_url_absolute, $response->json('content.link'));
        $this->assertStringContainsString($guest->invite_url_absolute, $message);
    }

    public function test_preview_output_matches_the_notification_channel_payload_for_every_channel_and_kind(): void
    {
        Http::fake();

        $owner = User::factory()->create();
        $guest = $this->makeGuest([], ['user_id' => $owner->id]);

        foreach (['invitation', 'reminder'] as $kind) {
            $notification = $kind === 'reminder'
                ? new ReminderNotification($guest->event, $guest->invite_url_absolute)
                : new InvitationNotification($guest->event, $guest->invite_url_absolute);

            foreach (['sms', 'whatsapp'] as $channel) {
                $expected = $channel === 'sms'
                    ? $notification->toSms($guest)['message']
                    : $notification->toWhatsApp($guest)['message'];

                $this->actingAs($owner)
                    ->getJson($this->previewUrl($guest, $kind, $channel))
                    ->assertOk()
                    ->assertJsonPath('content.message', $expected);
            }
        }

        // Previewing never sends.
        Http::assertNothingSent();
    }

    public function test_line_breaks_survive_api_serialization_and_match_the_composed_body(): void
    {
        $owner = User::factory()->create();
        $guest = $this->makeGuest([], ['user_id' => $owner->id]);

        $expected = (new InvitationNotification($guest->event, $guest->invite_url_absolute))->toSms($guest)['message'];

        $response = $this->actingAs($owner)
            ->getJson($this->previewUrl($guest, 'invitation', 'sms'))
            ->assertOk();

        // The decoded JSON keeps real newlines (not collapsed to spaces).
        $this->assertSame($expected, $response->json('content.message'));
        $this->assertStringContainsString("\n\n", (string) $response->json('content.message'));

        // The raw JSON escapes the newlines rather than dropping them.
        $this->assertStringContainsString('\n', (string) $response->getContent());
    }

    public function test_invitation_preview_endpoint_keeps_the_complete_url_and_token_intact(): void
    {
        $owner = User::factory()->create();
        $guest = $this->makeGuest([], ['user_id' => $owner->id]);

        $response = $this->actingAs($owner)
            ->getJson(route('api.events.invitations.preview', $guest->event_id))
            ->assertOk();

        foreach (['sms', 'whatsapp'] as $channel) {
            $message = (string) $response->json("channels.$channel.message");

            $this->assertStringContainsString($guest->invite_url_absolute, $message);
            $this->assertStringContainsString('/invite/'.$guest->invite_token, $message);
            $this->assertStringContainsString("\n\n", $message);
        }
    }
}
