<?php

namespace Tests\Feature\Api;

use App\Jobs\SendEntryCredentialJob;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationDelivery;
use App\Models\User;
use App\Services\CredentialIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Secure delivery of entry credentials: correct delivery kind, secret
 * redaction in sandbox capture, fail-safe when no active credential, and
 * unconfigured-channel failure recording. No live provider is contacted.
 */
class EntryCredentialDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function event(User $owner, ?string $start = null): Event
    {
        return Event::factory()->create([
            'user_id' => $owner->id,
            'event_start' => $start ?? now()->addDay(),
            // The credential resend endpoint requires the `check_in`
            // capability.
            'settings' => [
                'capabilities' => ['check_in' => true],
            ],
        ]);
    }

    private function guest(Event $event, array $attributes = []): Guest
    {
        return Guest::factory()->create(array_merge([
            'event_id' => $event->id,
            'email' => 'guest@example.test',
            'phone' => '+255700000001',
            'status' => 'accepted',
        ], $attributes));
    }

    public function test_active_credential_is_sent_by_email_and_recorded_with_credential_kind(): void
    {
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);

        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['phone' => null]);
        app(CredentialIssuer::class)->activate($guest);

        (new SendEntryCredentialJob($guest))->handle();

        $delivery = InvitationDelivery::where('guest_id', $guest->id)
            ->where('kind', 'credential')
            ->where('channel', 'mail')
            ->firstOrFail();

        $this->assertSame('sent', $delivery->status);
        $this->assertNotSame('delivered', $delivery->status);
    }

    public function test_job_does_nothing_without_an_active_credential(): void
    {
        config(['mail.default' => 'array', 'invitations.channels' => ['mail']]);

        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['phone' => null]);

        (new SendEntryCredentialJob($guest))->handle();

        $this->assertSame(0, InvitationDelivery::where('guest_id', $guest->id)->count());
    }

    public function test_sandbox_capture_redacts_the_entry_code(): void
    {
        Http::fake();

        config([
            'mail.default' => 'array',
            'invitations.channels' => ['sms'],
            'delivery_lab.enabled' => true,
            'delivery_lab.sandbox' => true,
            'delivery_lab.recipients' => ['+255700000001'],
        ]);

        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['email' => null]);
        $credential = app(CredentialIssuer::class)->activate($guest);
        $code = (string) $credential->revealSecret();

        (new SendEntryCredentialJob($guest))->handle();

        $delivery = InvitationDelivery::where('guest_id', $guest->id)
            ->where('kind', 'credential')
            ->where('channel', 'sms')
            ->firstOrFail();

        $captured = (string) ($delivery->meta['captured']['message'] ?? '');

        $this->assertSame('sent', $delivery->status);
        $this->assertStringContainsString('[redacted]', $captured);
        $this->assertStringNotContainsString($code, $captured);
        $this->assertStringNotContainsString($code, json_encode($delivery->meta));

        Http::assertNothingSent();
    }

    public function test_unconfigured_sms_credential_delivery_is_recorded_as_failed(): void
    {
        config([
            'mail.default' => 'array',
            'invitations.channels' => ['sms'],
            'invitations.sms.endpoint' => null,
        ]);

        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['email' => null]);
        app(CredentialIssuer::class)->activate($guest);

        (new SendEntryCredentialJob($guest))->handle();

        $delivery = InvitationDelivery::where('guest_id', $guest->id)
            ->where('kind', 'credential')
            ->where('channel', 'sms')
            ->firstOrFail();

        $this->assertSame('failed', $delivery->status);
        $this->assertSame('not_configured', $delivery->meta['provider']['reason']);
    }

    public function test_resend_requires_an_active_credential(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.credential.resend', ['event' => $event->id, 'guest' => $guest->id]))
            ->assertStatus(422);
    }

    public function test_resend_is_authorized_and_account_scoped(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);
        app(CredentialIssuer::class)->activate($guest);
        $intruder = $this->owner();

        $url = route('api.events.guests.credential.resend', ['event' => $event->id, 'guest' => $guest->id]);

        $this->postJson($url)->assertStatus(401);
        $this->actingAs($intruder)->postJson($url)->assertForbidden();

        $this->actingAs($owner)->postJson($url)->assertStatus(202);
    }

    public function test_revoke_then_resend_is_rejected(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);
        app(CredentialIssuer::class)->activate($guest);
        app(CredentialIssuer::class)->revoke($guest, 'rsvp_declined');

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.credential.resend', ['event' => $event->id, 'guest' => $guest->id]))
            ->assertStatus(422);
    }
}
