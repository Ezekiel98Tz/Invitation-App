<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Notifications\ReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Focused coverage for the invitation and reminder emails: warm, personalized
 * copy, graceful handling of missing data, HTML escaping, the RSVP button and
 * fallback link, the plain-text fallback, and preview-to-delivery parity for
 * the Delivery Lab and event-level previews.
 */
class GuestEmailCompositionTest extends TestCase
{
    use RefreshDatabase;

    private function sampleEvent(array $attributes = [], ?User $owner = null): Event
    {
        $event = new Event(array_merge([
            'title' => 'Amina & Yusuf Wedding',
            'event_start' => Carbon::parse('2026-11-14 16:00:00'),
            'timezone' => 'Africa/Dar_es_Salaam',
            'venue' => 'Serena Hotel, Dar es Salaam',
        ], $attributes));

        $event->setRelation('user', $owner ?? new User(['name' => 'Zainab Ally']));

        return $event;
    }

    private function invitationHtml(Event $event, ?object $guest, ?string $url = null): string
    {
        return (string) (new InvitationNotification($event, $url ?? 'http://127.0.0.1:8000/invite/ABC123'))->toMail($guest)->render();
    }

    public function test_invitation_email_has_a_warm_subject_and_personalized_copy(): void
    {
        $event = $this->sampleEvent();
        $guest = new Guest(['name' => 'Fatima']);

        $message = (new InvitationNotification($event, 'http://127.0.0.1:8000/invite/ABC123'))->toMail($guest);

        $this->assertSame("You're Invited to Amina & Yusuf Wedding 🎉", (string) $message->subject);

        $html = (string) $message->render();

        $this->assertStringContainsString("You're Invited!", $html);
        $this->assertStringContainsString('Dear Fatima,', $html);
        $this->assertStringContainsString('We would be delighted to have you join us for', $html);
        $this->assertStringContainsString('Amina &amp; Yusuf Wedding', $html);
        $this->assertStringContainsString('Event Details', $html);
        $this->assertStringContainsString('Your invitation is ready', $html);
        $this->assertStringContainsString('View Invitation &amp; RSVP', $html);
        $this->assertStringContainsString('Zainab Ally', $html);

        // Not the old system-notification wording.
        $this->assertStringNotContainsString('You are invited to', $html);
    }

    public function test_reminder_email_subject_and_body_are_distinct_from_the_invitation(): void
    {
        $event = $this->sampleEvent();
        $guest = new Guest(['name' => 'Fatima']);
        $url = 'http://127.0.0.1:8000/invite/ABC123';

        $invitation = (new InvitationNotification($event, $url))->toMail($guest);
        $reminder = (new ReminderNotification($event, $url))->toMail($guest);

        $this->assertSame('A Friendly Reminder — Amina & Yusuf Wedding', (string) $reminder->subject);
        $this->assertNotSame((string) $invitation->subject, (string) $reminder->subject);

        $html = (string) $reminder->render();

        $this->assertStringContainsString('A Friendly Reminder', $html);
        $this->assertStringContainsString("We're following up on your invitation to", $html);
        $this->assertStringContainsString('We look forward to hearing from you!', $html);
        $this->assertStringContainsString('Dear Fatima,', $html);
        $this->assertStringContainsString($url, $html);

        $this->assertNotSame((string) $invitation->render(), $html);
    }

    public function test_event_details_are_rendered_and_missing_fields_are_omitted(): void
    {
        $guest = new Guest(['name' => 'Fatima']);

        // Full details.
        $full = $this->invitationHtml($this->sampleEvent(), $guest);
        $this->assertStringContainsString('Event Details', $full);
        $this->assertStringContainsString('Date:', $full);
        $this->assertStringContainsString('(Africa/Dar_es_Salaam)', $full);
        $this->assertStringContainsString('Venue:', $full);
        $this->assertStringContainsString('Serena Hotel, Dar es Salaam', $full);

        // Venue only (no start date).
        $venueOnly = $this->invitationHtml($this->sampleEvent(['event_start' => null]), $guest);
        $this->assertStringContainsString('Event Details', $venueOnly);
        $this->assertStringContainsString('Venue:', $venueOnly);
        $this->assertStringNotContainsString('Date:', $venueOnly);

        // Date only (no venue).
        $dateOnly = $this->invitationHtml($this->sampleEvent(['venue' => '  ']), $guest);
        $this->assertStringContainsString('Date:', $dateOnly);
        $this->assertStringNotContainsString('Venue:', $dateOnly);

        // No meaningful details: the whole section is omitted.
        $none = $this->invitationHtml($this->sampleEvent(['event_start' => null, 'venue' => '']), $guest);
        $this->assertStringNotContainsString('Event Details', $none);
        $this->assertStringNotContainsString('Date:', $none);
        $this->assertStringNotContainsString('Venue:', $none);
        // The rest of the email is still present.
        $this->assertStringContainsString('Your invitation is ready', $none);
    }

    public function test_missing_or_blank_guest_name_is_handled_gracefully(): void
    {
        $event = $this->sampleEvent();
        $url = 'http://127.0.0.1:8000/invite/ABC123';

        foreach ([null, '', '   '] as $name) {
            $guest = new Guest(['name' => $name]);
            $message = (new InvitationNotification($event, $url))->toMail($guest);
            $html = (string) $message->render();
            $text = (string) app(Markdown::class)->renderText((string) $message->markdown, $message->data());

            $this->assertStringNotContainsString('Dear', $html);
            $this->assertStringNotContainsString('Dear', $text);
            $this->assertStringNotContainsString('null', $html);
            // The invitation is still complete and readable.
            $this->assertStringContainsString("You're Invited!", $html);
            $this->assertStringContainsString($url, $html);
        }
    }

    public function test_dynamic_values_are_html_escaped(): void
    {
        $event = $this->sampleEvent([
            'title' => 'Amina & Yusuf <script>alert(1)</script>',
            'venue' => 'Venue "X" <b>bold</b>',
        ]);
        $guest = new Guest(['name' => '<b>Eve</b>']);

        $html = $this->invitationHtml($event, $guest);

        // Raw markup from data must never appear as markup.
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>Eve</b>', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);

        // It is present, escaped.
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
        $this->assertStringContainsString('&lt;b&gt;Eve&lt;/b&gt;', $html);
    }

    public function test_rsvp_button_and_fallback_link_share_the_complete_url(): void
    {
        $event = $this->sampleEvent();
        $guest = new Guest(['name' => 'Fatima']);
        $url = 'http://127.0.0.1:8000/invite/UNIQUE7465TOKEN';

        $message = (new InvitationNotification($event, $url))->toMail($guest);
        $html = (string) $message->render();

        // Button and visible fallback link both point at the same URL.
        $this->assertSame(2, substr_count($html, 'href="'.$url.'"'));

        // ...and the URL is visible as text in the fallback.
        $this->assertStringContainsString('>'.$url.'</a>', $html);

        $text = (string) app(Markdown::class)->renderText((string) $message->markdown, $message->data());
        $this->assertSame(2, substr_count($text, $url));
    }

    public function test_plain_text_email_is_readable_with_the_url_on_its_own_line(): void
    {
        $event = $this->sampleEvent();
        $guest = new Guest(['name' => 'Fatima']);
        $url = 'http://127.0.0.1:8000/invite/ABC123';

        $message = (new InvitationNotification($event, $url))->toMail($guest);
        $text = (string) app(Markdown::class)->renderText((string) $message->markdown, $message->data());

        foreach ([
            "You're Invited!",
            'Dear Fatima,',
            'Event Details',
            'Date:',
            'Venue:',
            'View Invitation & RSVP:',
            'We look forward to celebrating with you!',
            'Warm regards,',
            'Zainab Ally',
        ] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }

        // Paragraph breaks are preserved and the URL sits on its own line.
        $this->assertStringContainsString("\n\n", $text);
        $this->assertStringContainsString("\n".$url."\n", $text);

        // No markdown artifacts leak into the plain text.
        $this->assertStringNotContainsString('#', $text);
        $this->assertStringNotContainsString('**', $text);
        $this->assertStringNotContainsString('<', $text);
    }

    public function test_delivery_lab_preview_matches_the_notification_email(): void
    {
        Notification::fake();
        Http::fake();

        $owner = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);
        $guest = Guest::factory()->create([
            'event_id' => $event->id,
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'phone' => '+255700000001',
        ]);

        $url = $guest->invite_url_absolute;

        foreach (['invitation', 'reminder'] as $kind) {
            $notification = $kind === 'reminder'
                ? new ReminderNotification($event, $url)
                : new InvitationNotification($event, $url);

            $message = $notification->toMail($guest);
            $expectedHtml = (string) $message->render();
            $expectedText = (string) app(Markdown::class)->renderText((string) $message->markdown, $message->data());

            $response = $this->actingAs($owner)
                ->getJson(route('api.delivery-lab.preview', [
                    'event' => $event->id,
                    'guest' => $guest->id,
                    'kind' => $kind,
                    'channel' => 'mail',
                ]))
                ->assertOk()
                ->assertJsonPath('content.subject', (string) $message->subject)
                ->assertJsonPath('content.action_url', $url);

            $this->assertSame($expectedHtml, (string) $response->json('content.html'));
            $this->assertSame($expectedText, (string) $response->json('content.text'));
            $this->assertStringContainsString($url, (string) $response->json('content.text'));
        }

        // Rendering previews must never send or make requests.
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_event_level_preview_returns_the_real_email(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);
        $guest = Guest::factory()->create(['event_id' => $event->id, 'name' => 'Ada']);

        $message = (new InvitationNotification($event, $guest->invite_url_absolute))->toMail($guest);

        $this->actingAs($owner)
            ->getJson(route('api.events.invitations.preview', $event))
            ->assertOk()
            ->assertJsonPath('channels.mail.subject', (string) $message->subject)
            ->assertJsonPath('channels.mail.html', (string) $message->render())
            ->assertJsonPath('channels.mail.action_url', $guest->invite_url_absolute);
    }

    public function test_sms_and_whatsapp_preview_parity_is_unchanged(): void
    {
        Http::fake();

        $owner = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);
        $guest = Guest::factory()->create([
            'event_id' => $event->id,
            'name' => 'Ada',
            'phone' => '+255700000001',
        ]);

        $url = $guest->invite_url_absolute;

        foreach (['invitation', 'reminder'] as $kind) {
            $notification = $kind === 'reminder'
                ? new ReminderNotification($event, $url)
                : new InvitationNotification($event, $url);

            $this->actingAs($owner)
                ->getJson(route('api.delivery-lab.preview', [
                    'event' => $event->id,
                    'guest' => $guest->id,
                    'kind' => $kind,
                    'channel' => 'sms',
                ]))
                ->assertOk()
                ->assertJsonPath('content.message', $notification->toSms($guest)['message'])
                ->assertJsonPath('content.link', $url);

            $this->actingAs($owner)
                ->getJson(route('api.delivery-lab.preview', [
                    'event' => $event->id,
                    'guest' => $guest->id,
                    'kind' => $kind,
                    'channel' => 'whatsapp',
                ]))
                ->assertOk()
                ->assertJsonPath('content.message', $notification->toWhatsApp($guest)['message'])
                ->assertJsonPath('content.link', $url);
        }

        Http::assertNothingSent();
    }
}
