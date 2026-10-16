<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Notifications\ReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DeliveryLabTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function guest(User $owner): Guest
    {
        $event = Event::factory()->create(['user_id' => $owner->id]);

        return Guest::factory()->create([
            'event_id' => $event->id,
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'phone' => '+255700000001',
        ]);
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

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson(route('api.delivery-lab.index'))->assertStatus(401);
    }

    public function test_dashboard_reports_mode_channels_and_counts_without_secrets(): void
    {
        config([
            'mail.default' => 'array',
            'delivery_lab.enabled' => true,
            'delivery_lab.sandbox' => true,
            'delivery_lab.recipients' => ['ada@example.test'],
            'invitations.channels' => ['mail', 'sms'],
            'invitations.sms.endpoint' => 'https://sms.example.test/send',
            'invitations.sms.token' => 'super-secret-token',
        ]);

        $owner = $this->owner();
        $guest = $this->guest($owner);

        InvitationDelivery::create([
            'guest_id' => $guest->id,
            'kind' => 'invitation',
            'channel' => 'sms',
            'status' => 'sent',
            'meta' => ['sandbox' => true],
        ]);

        $response = $this->actingAs($owner)
            ->getJson(route('api.delivery-lab.index'))
            ->assertOk()
            ->assertJsonPath('lab.enabled', true)
            ->assertJsonPath('lab.sandbox', true)
            ->assertJsonPath('lab.mode', 'sandbox')
            ->assertJsonPath('channels.sms.configured', true)
            ->assertJsonPath('channels.sms.mode', 'sandbox')
            ->assertJsonPath('channels.sms.verified', false)
            ->assertJsonPath('channels.mail.mode', 'sandbox')
            ->assertJsonPath('counts.sent', 1)
            ->assertJsonPath('counts.simulated', 1)
            ->assertJsonStructure(['recent' => [['id', 'channel', 'status', 'mode', 'recipient']]]);

        $this->assertStringNotContainsString('super-secret-token', (string) $response->getContent());
    }

    public function test_preview_matches_the_actual_composed_content_per_channel(): void
    {
        Notification::fake();
        Http::fake();

        config(['invitations.channels' => ['mail']]);

        $owner = $this->owner();
        $guest = $this->guest($owner);
        $absolute = $guest->invite_url_absolute;

        $notification = new InvitationNotification($guest->event, $absolute);
        $message = $notification->toMail($guest);

        $mail = $this->actingAs($owner)
            ->getJson($this->previewUrl($guest, 'invitation', 'mail'))
            ->assertOk()
            ->assertJsonPath('channel', 'mail')
            ->assertJsonPath('content.subject', (string) $message->subject)
            ->assertJsonPath('content.action_url', $absolute);

        // Previewed HTML and plain text are the exact notification output.
        $this->assertSame((string) $message->render(), (string) $mail->json('content.html'));

        $html = (string) $mail->json('content.html');
        $this->assertStringContainsString($guest->event->title, $html);
        $this->assertStringContainsString('<html', strtolower($html));

        // The RSVP button and the visible fallback link both use the complete
        // unique invitation URL.
        $this->assertSame(2, substr_count($html, 'href="'.$absolute.'"'));

        $text = (string) $mail->json('content.text');
        $this->assertStringContainsString('Dear Ada,', $text);
        $this->assertStringContainsString($guest->event->title, $text);
        $this->assertStringContainsString($absolute, $text);
        $this->assertStringContainsString("\n\n", $text);

        // The previewed SMS must be exactly what the SMS channel would send
        // (same composer), including the complete unique URL.
        $expectedSms = $notification->toSms($guest)['message'];

        $sms = $this->actingAs($owner)
            ->getJson($this->previewUrl($guest, 'invitation', 'sms'))
            ->assertOk()
            ->assertJsonPath('content.message', $expectedSms)
            ->assertJsonPath('content.link', $absolute);

        $this->assertSame(mb_strlen($expectedSms), $sms->json('content.character_count'));
        $this->assertStringContainsString("\n\n", $expectedSms);

        // The previewed WhatsApp reminder must be exactly what the WhatsApp
        // channel would send.
        $expectedWhatsApp = (new ReminderNotification($guest->event, $absolute))->toWhatsApp($guest)['message'];

        $this->actingAs($owner)
            ->getJson($this->previewUrl($guest, 'reminder', 'whatsapp'))
            ->assertOk()
            ->assertJsonPath('content.message', $expectedWhatsApp)
            ->assertJsonPath('content.link', $absolute)
            ->assertJsonStructure(['content' => ['template_indication']]);

        // Previewing must never send.
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_preview_requires_authentication_and_guards_account_boundaries(): void
    {
        $owner = $this->owner();
        $guest = $this->guest($owner);
        $intruder = $this->owner();

        $this->getJson($this->previewUrl($guest, 'invitation', 'sms'))->assertStatus(401);

        $this->actingAs($intruder)
            ->getJson($this->previewUrl($guest, 'invitation', 'sms'))
            ->assertForbidden();
    }

    public function test_preview_rejects_a_guest_from_another_event(): void
    {
        $owner = $this->owner();
        $guest = $this->guest($owner);
        $otherEvent = Event::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)
            ->getJson(route('api.delivery-lab.preview', [
                'event' => $otherEvent->id,
                'guest' => $guest->id,
                'kind' => 'invitation',
                'channel' => 'sms',
            ]))
            ->assertNotFound();
    }
}
