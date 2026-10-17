<?php

namespace Tests\Feature\Api;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationCredential;
use App\Models\User;
use App\Services\CredentialIssuer;
use App\Support\PassQrReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DigitalPassTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function event(User $owner, array $attributes = []): Event
    {
        return Event::factory()->create(array_merge([
            'user_id' => $owner->id,
            'event_start' => now()->subHour(),
            'settings' => [
                'allow_plus_ones' => false,
                'custom_questions' => [],
                'enable_waitlist' => false,
                'capabilities' => ['check_in' => true],
            ],
        ], $attributes));
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

    public function test_eligible_guest_receives_valid_pass_without_secrets_or_internal_ids(): void
    {
        $event = $this->event($this->owner());
        $guest = $this->guest($event, ['name' => 'Pass Holder']);
        $credential = app(CredentialIssuer::class)->activate($guest);
        $code = (string) $credential->revealSecret();

        $response = $this->getJson(route('api.invites.pass', ['token' => $guest->invite_token]))
            ->assertOk()
            ->assertJsonPath('guest.name', 'Pass Holder')
            ->assertJsonPath('event.title', $event->title)
            ->assertJsonPath('pass.state', 'valid')
            ->assertJsonPath('pass.qr_available', true);

        $body = $response->getContent();
        $this->assertStringNotContainsString($guest->invite_token, $body);
        $this->assertStringNotContainsString($code, $body);
        $this->assertStringNotContainsString('code_hash', $body);
        $this->assertStringNotContainsString('code_encrypted', $body);
        $this->assertStringNotContainsString('"id"', $body);
        $this->assertStringNotContainsString('guest@example.test', $body);
        $this->assertStringNotContainsString('+255700000001', $body);
    }

    public function test_pass_states_distinguish_pending_declined_not_yet_expired_revoked_and_disabled(): void
    {
        $owner = $this->owner();

        $pending = $this->guest($this->event($owner), ['status' => 'pending']);
        $this->getJson(route('api.invites.pass', ['token' => $pending->invite_token]))
            ->assertOk()
            ->assertJsonPath('pass.state', 'pending')
            ->assertJsonPath('pass.qr_available', false);

        $declined = $this->guest($this->event($owner), ['status' => 'declined']);
        $this->getJson(route('api.invites.pass', ['token' => $declined->invite_token]))
            ->assertOk()
            ->assertJsonPath('pass.state', 'declined')
            ->assertJsonPath('pass.qr_available', false);

        $futureEvent = $this->event($owner, ['event_start' => now()->addDays(2)]);
        $future = $this->guest($futureEvent);
        app(CredentialIssuer::class)->activate($future);
        $this->getJson(route('api.invites.pass', ['token' => $future->invite_token]))
            ->assertOk()
            ->assertJsonPath('pass.state', 'not_yet')
            ->assertJsonPath('pass.qr_available', false);

        $pastEvent = $this->event($owner, ['event_start' => now()->subDays(2)]);
        $past = $this->guest($pastEvent);
        app(CredentialIssuer::class)->activate($past);
        $this->getJson(route('api.invites.pass', ['token' => $past->invite_token]))
            ->assertOk()
            ->assertJsonPath('pass.state', 'expired')
            ->assertJsonPath('pass.qr_available', false);

        $revoked = $this->guest($this->event($owner));
        app(CredentialIssuer::class)->activate($revoked);
        app(CredentialIssuer::class)->revoke($revoked, 'operator_revoked');
        $this->getJson(route('api.invites.pass', ['token' => $revoked->invite_token]))
            ->assertOk()
            ->assertJsonPath('pass.state', 'revoked')
            ->assertJsonPath('pass.qr_available', false);

        $disabledEvent = $this->event($owner, [
            'settings' => [
                'allow_plus_ones' => false,
                'custom_questions' => [],
                'enable_waitlist' => false,
                'capabilities' => ['check_in' => false],
            ],
        ]);
        $disabled = $this->guest($disabledEvent);
        app(CredentialIssuer::class)->activate($disabled);
        $this->getJson(route('api.invites.pass', ['token' => $disabled->invite_token]))
            ->assertOk()
            ->assertJsonPath('pass.state', 'disabled')
            ->assertJsonPath('pass.qr_available', false);
    }

    public function test_missing_pass_reference_returns_not_found(): void
    {
        $this->getJson(route('api.invites.pass', ['token' => str_repeat('x', 32)]))
            ->assertNotFound();
    }

    public function test_pass_qr_is_svg_but_never_contains_the_short_code(): void
    {
        $event = $this->event($this->owner());
        $guest = $this->guest($event);
        $credential = app(CredentialIssuer::class)->activate($guest);
        $code = (string) $credential->revealSecret();

        $response = $this->get(route('api.invites.pass.qr', ['token' => $guest->invite_token]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');

        $this->assertStringContainsString('<svg', $response->getContent());
        $this->assertStringNotContainsString($code, $response->getContent());
        $this->assertStringNotContainsString($credential->code_hash, $response->getContent());
    }

    public function test_pass_qr_is_unavailable_for_ineligible_guests(): void
    {
        $pending = $this->guest($this->event($this->owner()), ['status' => 'pending']);

        $this->get(route('api.invites.pass.qr', ['token' => $pending->invite_token]))
            ->assertNotFound();
    }

    public function test_qr_lookup_is_read_only_and_exposes_the_valid_guest(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);
        $credential = app(CredentialIssuer::class)->activate($guest);
        $reference = PassQrReference::make($credential);

        $this->actingAs($owner)
            ->getJson(route('api.events.check-in.lookup', [
                'event' => $event->id,
                'qr_token' => $reference,
            ]))
            ->assertOk()
            ->assertJsonPath('state', 'valid')
            ->assertJsonPath('guest.name', $guest->name)
            ->assertJsonPath('checked_in', false);

        $this->assertSame(0, Attendance::where('guest_id', $guest->id)->count());
    }

    public function test_malformed_qr_and_qr_from_another_event_return_invalid_without_attendance(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $otherEvent = $this->event($owner);
        $otherGuest = $this->guest($otherEvent);
        $otherCredential = app(CredentialIssuer::class)->activate($otherGuest);
        $otherReference = PassQrReference::make($otherCredential);

        $this->actingAs($owner)
            ->getJson(route('api.events.check-in.lookup', [
                'event' => $event->id,
                'qr_token' => 'pass:v1:not-valid',
            ]))
            ->assertOk()
            ->assertJsonPath('state', 'invalid')
            ->assertJsonPath('guest', null);

        $this->actingAs($owner)
            ->getJson(route('api.events.check-in.lookup', [
                'event' => $event->id,
                'qr_token' => $otherReference,
            ]))
            ->assertOk()
            ->assertJsonPath('state', 'invalid')
            ->assertJsonPath('guest', null);

        $this->assertSame(0, Attendance::count());
    }

    public function test_explicit_qr_check_in_records_attendance_and_duplicate_is_rejected(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);
        $credential = app(CredentialIssuer::class)->activate($guest);
        $reference = PassQrReference::make($credential);
        $url = route('api.events.guests.check-in', ['event' => $event->id, 'guest' => $guest->id]);

        $this->actingAs($owner)
            ->postJson($url, ['method' => 'qr', 'qr_token' => $reference])
            ->assertStatus(201)
            ->assertJsonPath('attendance.method', 'qr')
            ->assertJsonPath('attendance.status', 'valid');

        $this->assertSame(1, Attendance::where('guest_id', $guest->id)->count());

        $this->actingAs($owner)
            ->postJson($url, ['method' => 'qr', 'qr_token' => $reference])
            ->assertStatus(422);

        $this->assertSame(1, Attendance::where('guest_id', $guest->id)->count());
    }

    public function test_qr_for_a_guest_does_not_authorize_another_guests_check_in(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);
        $other = $this->guest($event);
        app(CredentialIssuer::class)->activate($guest);
        $otherCredential = app(CredentialIssuer::class)->activate($other);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.check-in', ['event' => $event->id, 'guest' => $guest->id]), [
                'method' => 'qr',
                'qr_token' => PassQrReference::make($otherCredential),
            ])
            ->assertStatus(422);

        $this->assertSame(0, Attendance::count());
    }

    public function test_reissued_credential_invalidates_both_old_qr_and_old_manual_code(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);
        $old = app(CredentialIssuer::class)->activate($guest);
        $oldCode = (string) $old->revealSecret();
        $oldReference = PassQrReference::make($old);

        $new = app(CredentialIssuer::class)->reissue($guest, 'operator_reissue');
        $url = route('api.events.guests.check-in', ['event' => $event->id, 'guest' => $guest->id]);

        $this->actingAs($owner)
            ->getJson(route('api.events.check-in.lookup', [
                'event' => $event->id,
                'qr_token' => $oldReference,
            ]))
            ->assertOk()
            ->assertJsonPath('state', 'revoked');

        $this->actingAs($owner)
            ->postJson($url, ['method' => 'qr', 'qr_token' => $oldReference])
            ->assertStatus(422);

        $this->actingAs($owner)
            ->postJson($url, ['method' => 'code', 'code' => $oldCode])
            ->assertStatus(422);

        $this->assertSame(0, Attendance::count());
        $this->assertNotSame($old->id, $new->id);
    }

    public function test_current_manual_code_still_uses_the_existing_lookup_and_check_in_path(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);
        $credential = app(CredentialIssuer::class)->activate($guest);
        $code = (string) $credential->revealSecret();

        $this->actingAs($owner)
            ->getJson(route('api.events.check-in.lookup', [
                'event' => $event->id,
                'code' => $code,
            ]))
            ->assertOk()
            ->assertJsonPath('state', 'valid');

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.check-in', ['event' => $event->id, 'guest' => $guest->id]), [
                'method' => 'code',
                'code' => $code,
            ])
            ->assertStatus(201)
            ->assertJsonPath('attendance.method', 'code');
    }

    public function test_qr_lookup_reports_not_yet_and_expired_credentials(): void
    {
        $owner = $this->owner();

        $futureEvent = $this->event($owner, ['event_start' => now()->addDays(2)]);
        $futureGuest = $this->guest($futureEvent);
        $futureCredential = app(CredentialIssuer::class)->activate($futureGuest);

        $this->actingAs($owner)
            ->getJson(route('api.events.check-in.lookup', [
                'event' => $futureEvent->id,
                'qr_token' => PassQrReference::make($futureCredential),
            ]))
            ->assertOk()
            ->assertJsonPath('state', 'not_yet');

        $pastEvent = $this->event($owner, ['event_start' => now()->subDays(2)]);
        $pastGuest = $this->guest($pastEvent);
        $pastCredential = app(CredentialIssuer::class)->activate($pastGuest);

        $this->actingAs($owner)
            ->getJson(route('api.events.check-in.lookup', [
                'event' => $pastEvent->id,
                'qr_token' => PassQrReference::make($pastCredential),
            ]))
            ->assertOk()
            ->assertJsonPath('state', 'expired');

        $this->assertSame(0, Attendance::count());
    }

    public function test_already_checked_in_guest_is_reported_without_a_second_attendance_row(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);
        $credential = app(CredentialIssuer::class)->activate($guest);
        $reference = PassQrReference::make($credential);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.check-in', ['event' => $event->id, 'guest' => $guest->id]), [
                'method' => 'qr',
                'qr_token' => $reference,
            ])
            ->assertStatus(201);

        $this->actingAs($owner)
            ->getJson(route('api.events.check-in.lookup', [
                'event' => $event->id,
                'qr_token' => $reference,
            ]))
            ->assertOk()
            ->assertJsonPath('state', 'already_used')
            ->assertJsonPath('checked_in', true);

        $this->assertSame(1, Attendance::where('guest_id', $guest->id)->count());
    }
}
