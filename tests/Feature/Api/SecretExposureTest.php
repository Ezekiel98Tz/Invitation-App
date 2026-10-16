<?php

namespace Tests\Feature\Api;

use App\Jobs\SendEntryCredentialJob;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationCredential;
use App\Models\InvitationDelivery;
use App\Models\User;
use App\Services\CredentialIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\TestCase;

/**
 * Secret-exposure regression tests.
 *
 * Confirms that invitation tokens and entry-credential secrets do not leak
 * through model serialization, API responses, audit logs, delivery metadata or
 * sandbox captures. The known, intentionally-retained exposure in the guest
 * XLSX export (`invite_token`, pre-existing product behaviour) is documented by
 * a test rather than changed.
 */
class SecretExposureTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function event(User $owner): Event
    {
        return Event::factory()->create([
            'user_id' => $owner->id,
            'event_start' => now()->addDay(),
            'settings' => ['capabilities' => ['check_in' => true]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function guest(Event $event, array $attributes = []): Guest
    {
        return Guest::factory()->create(array_merge([
            'event_id' => $event->id,
            'email' => 'guest@example.test',
            'phone' => '+255700000001',
            'status' => 'accepted',
        ], $attributes));
    }

    // -------------------------------------------------- model serialization

    public function test_invitation_credential_serialization_never_exposes_the_secret(): void
    {
        $guest = $this->guest($this->event($this->owner()));
        $credential = app(CredentialIssuer::class)->activate($guest);

        $array = $credential->fresh()->toArray();

        $this->assertArrayNotHasKey('code_hash', $array);
        $this->assertArrayNotHasKey('code_encrypted', $array);
        $this->assertArrayNotHasKey('active_key', $array);
    }

    public function test_attendance_serialization_hides_the_uniqueness_helper(): void
    {
        $attendance = new Attendance(['active_key' => 123]);
        $attendance->status = Attendance::STATUS_VALID;

        $this->assertArrayNotHasKey('active_key', $attendance->toArray());
    }

    // ------------------------------------------------------------ API payloads

    public function test_credential_show_payload_omits_secret_columns_and_the_code(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);
        app(CredentialIssuer::class)->activate($guest);

        $response = $this->actingAs($owner)
            ->getJson(route('api.events.guests.credential.show', ['event' => $event->id, 'guest' => $guest->id]))
            ->assertOk();

        $response->assertJsonMissingPath('credential.code_hash');
        $response->assertJsonMissingPath('credential.code_encrypted');
        $response->assertJsonMissingPath('credential.active_key');
        $response->assertJsonMissingPath('credential.code');

        $body = $response->getContent();
        $this->assertStringNotContainsString('code_hash', $body);
        $this->assertStringNotContainsString('code_encrypted', $body);
    }

    public function test_credential_reveal_returns_the_code_but_audit_logs_keep_only_ids(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);
        $credential = app(CredentialIssuer::class)->activate($guest);
        $code = (string) $credential->revealSecret();

        $response = $this->actingAs($owner)
            ->postJson(route('api.events.guests.credential.reveal', ['event' => $event->id, 'guest' => $guest->id]))
            ->assertOk();

        // The access-controlled reveal is the one place the code is returned.
        $this->assertSame($code, $response->json('code'));

        $log = \App\Models\ActivityLog::where('action', 'credential.reveal')->firstOrFail();
        $this->assertSame($owner->id, $log->user_id);
        $this->assertStringNotContainsString($code, (string) json_encode($log->meta));
        $this->assertStringNotContainsString($code, (string) json_encode($log->toArray()));
    }

    public function test_public_invite_response_never_leaks_the_invite_token_or_credential_secrets(): void
    {
        $event = $this->event($this->owner());
        $guest = $this->guest($event, ['status' => 'pending']);
        app(CredentialIssuer::class)->ensure($guest);

        $response = $this->getJson(route('api.invites.show', ['token' => $guest->invite_token]))
            ->assertOk();

        $body = $response->getContent();
        $this->assertStringNotContainsString($guest->invite_token, $body);
        $this->assertStringNotContainsString('code_hash', $body);
        $this->assertStringNotContainsString('code_encrypted', $body);
    }

    // -------------------------------------------------------- delivery metadata

    public function test_sandbox_capture_redacts_the_entry_code_across_the_whole_meta(): void
    {
        Http::fake();

        config([
            'mail.default' => 'array',
            'invitations.channels' => ['sms'],
            'delivery_lab.enabled' => true,
            'delivery_lab.sandbox' => true,
            'delivery_lab.recipients' => ['+255700000001'],
        ]);

        $guest = $this->guest($this->event($this->owner()), ['email' => null]);
        $credential = app(CredentialIssuer::class)->activate($guest);
        $code = (string) $credential->revealSecret();

        (new SendEntryCredentialJob($guest))->handle();

        $delivery = InvitationDelivery::where('guest_id', $guest->id)
            ->where('kind', 'credential')
            ->where('channel', 'sms')
            ->firstOrFail();

        $captured = (string) ($delivery->meta['captured']['message'] ?? '');

        $this->assertStringContainsString('[redacted]', $captured);
        $this->assertStringNotContainsString($code, $captured);
        $this->assertStringNotContainsString($code, (string) json_encode($delivery->meta));
        $this->assertStringNotContainsString($code, (string) $delivery->error);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ known behaviour

    public function test_guest_serialization_exposes_invite_token_but_export_does_not(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['status' => 'pending']);

        // Operator-facing guest serialization intentionally exposes the invite
        // token (the SPA builds the copy/QR invite link from it) while keeping
        // the contact association hidden. This test documents the boundary.
        $array = $guest->toArray();
        $this->assertArrayNotHasKey('contact_id', $array);
        $this->assertArrayHasKey('invite_token', $array);

        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is required for XLSX exports.');
        }

        $response = $this->actingAs($owner)->get(route('api.events.guests.export', ['event' => $event->id]));
        $response->assertOk()->assertDownload();

        $headers = SimpleExcelReader::create($response->baseResponse->getFile()->getPathname())->getHeaders();

        // Hardened: the downloadable export must not carry the bearer RSVP token.
        $this->assertNotContains('invite_token', $headers);
    }
}
