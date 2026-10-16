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
use Tests\TestCase;

/**
 * Branding: the user-facing product name is "Invitation Studio" and default
 * framework branding (name, logo, footer attribution) must not appear in
 * rendered email or other user-facing output.
 */
class EmailBrandingTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://127.0.0.1:8000/invite/ABC123DEF456GHI789JKL';

    private function sampleEvent(): Event
    {
        $event = new Event([
            'title' => 'Amina & Yusuf Wedding',
            'event_start' => Carbon::parse('2026-11-14 16:00:00'),
            'timezone' => 'Africa/Dar_es_Salaam',
            'venue' => 'Serena Hotel, Dar es Salaam',
        ]);

        $event->setRelation('user', new User(['name' => 'Zainab Ally']));

        return $event;
    }

    private function guest(string $name = 'Fatima'): Guest
    {
        return new Guest(['name' => $name]);
    }

    private function plainText(InvitationNotification|ReminderNotification $notification, object $guest): string
    {
        $message = $notification->toMail($guest);

        return (string) app(Markdown::class)->renderText((string) $message->markdown, $message->data());
    }

    public function test_application_name_is_the_brand(): void
    {
        $this->assertSame('Invitation Studio', config('app.name'));
    }

    public function test_invitation_email_html_uses_the_brand_without_laravel_branding(): void
    {
        $message = (new InvitationNotification($this->sampleEvent(), self::URL))->toMail($this->guest());
        $html = (string) $message->render();

        // Brand present, framework branding absent.
        $this->assertStringContainsString('Invitation Studio', $html);
        $this->assertStringNotContainsString('Laravel', $html);
        $this->assertStringNotContainsString('laravel.com', $html);
        $this->assertStringNotContainsString('notification-logo', $html);

        // Brand appears as the header wordmark and the footer attribution.
        $this->assertMatchesRegularExpression('/<td class="header".*?Invitation Studio.*?<\/td>/s', $html);
        $this->assertStringContainsString('© '.date('Y').' Invitation Studio. All rights reserved.', $html);

        // The email content is preserved.
        $this->assertStringContainsString("You're Invited!", $html);
        $this->assertStringContainsString('Dear Fatima,', $html);
        $this->assertStringContainsString('View Invitation &amp; RSVP', $html);
        $this->assertStringContainsString(self::URL, $html);
        $this->assertStringContainsString('Serena Hotel, Dar es Salaam', $html);
    }

    public function test_reminder_email_html_uses_the_brand_without_laravel_branding(): void
    {
        $message = (new ReminderNotification($this->sampleEvent(), self::URL))->toMail($this->guest());
        $html = (string) $message->render();

        $this->assertStringContainsString('Invitation Studio', $html);
        $this->assertStringNotContainsString('Laravel', $html);
        $this->assertStringNotContainsString('laravel.com', $html);

        $this->assertStringContainsString('A Friendly Reminder', $html);
        $this->assertStringContainsString('Dear Fatima,', $html);
        $this->assertStringContainsString(self::URL, $html);
        $this->assertStringContainsString('© '.date('Y').' Invitation Studio. All rights reserved.', $html);
    }

    public function test_email_plain_text_is_branded_and_complete(): void
    {
        $text = $this->plainText(new InvitationNotification($this->sampleEvent(), self::URL), $this->guest());

        $this->assertStringContainsString('Invitation Studio', $text);
        $this->assertStringNotContainsString('Laravel', $text);

        // Structure and content remain complete.
        $this->assertStringContainsString("You're Invited!", $text);
        $this->assertStringContainsString('Dear Fatima,', $text);
        $this->assertStringContainsString('Event Details', $text);
        $this->assertStringContainsString('View Invitation & RSVP:', $text);
        $this->assertStringContainsString("\n".self::URL."\n", $text);
        $this->assertStringContainsString("\n\n", $text);
        $this->assertStringContainsString('© '.date('Y').' Invitation Studio. All rights reserved.', $text);
    }

    public function test_delivery_lab_preview_matches_the_branded_email(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);
        $guest = Guest::factory()->create([
            'event_id' => $event->id,
            'name' => 'Ada',
            'email' => 'ada@example.test',
        ]);

        $message = (new InvitationNotification($event, $guest->invite_url_absolute))->toMail($guest);

        $response = $this->actingAs($owner)
            ->getJson(route('api.delivery-lab.preview', [
                'event' => $event->id,
                'guest' => $guest->id,
                'kind' => 'invitation',
                'channel' => 'mail',
            ]))
            ->assertOk();

        // Preview still matches the real rendered email, and is branded.
        $this->assertSame((string) $message->render(), (string) $response->json('content.html'));
        $this->assertStringContainsString('Invitation Studio', (string) $response->json('content.html'));
        $this->assertStringNotContainsString('Laravel', (string) $response->json('content.html'));
        $this->assertStringContainsString('Invitation Studio', (string) $response->json('content.text'));
    }

    public function test_public_invitation_page_shows_the_brand_without_laravel_branding(): void
    {
        $event = Event::factory()->create();
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $response = $this->get(route('invites.show', ['token' => $guest->invite_token]))->assertOk();

        $response->assertSee('Invitation Studio', false);
        $response->assertDontSee('Laravel', false);
        $response->assertDontSee('laravel.com', false);
    }
}
