<?php

namespace Tests\Feature\Api;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationCredential;
use App\Models\User;
use App\Services\CredentialIssuer;
use App\Support\CredentialAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialCheckInTest extends TestCase
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
            'event_start' => $start ?? now()->addDays(5),
            // Check-in/attendance and the operator credential endpoints require
            // the `check_in` capability.
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
            'status' => 'pending',
        ], $attributes));
    }

    private function issuer(): CredentialIssuer
    {
        return app(CredentialIssuer::class);
    }

    // ---------------------------------------------------------------- lifecycle

    public function test_accepting_an_invitation_issues_an_active_credential(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);

        $this->postJson(route('api.invites.store', ['token' => $guest->invite_token]), [
            'status' => 'accepted',
        ])->assertOk();

        $credential = InvitationCredential::where('guest_id', $guest->id)->firstOrFail();

        $this->assertSame(InvitationCredential::STATUS_ACTIVE, $credential->status);
        $this->assertNotNull($credential->code_hash);
        $this->assertNotNull($credential->activated_at);
        // The RSVP link token is untouched.
        $this->assertSame(32, strlen((string) $guest->fresh()->invite_token));
    }

    public function test_declining_revokes_the_credential_and_reaccepting_reactivates(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);

        $this->postJson(route('api.invites.store', ['token' => $guest->invite_token]), ['status' => 'accepted'])->assertOk();
        $first = InvitationCredential::where('guest_id', $guest->id)->firstOrFail();

        $this->postJson(route('api.invites.store', ['token' => $guest->invite_token]), ['status' => 'declined'])->assertOk();
        $this->assertSame(InvitationCredential::STATUS_REVOKED, $first->fresh()->status);
        $this->assertNull($first->fresh()->active_key);

        // Re-accepting issues a fresh active credential.
        $this->postJson(route('api.invites.store', ['token' => $guest->invite_token]), ['status' => 'accepted'])->assertOk();

        $current = $this->issuer()->current($guest->fresh());
        $this->assertNotNull($current);
        $this->assertSame(InvitationCredential::STATUS_ACTIVE, $current->status);
        $this->assertSame(1, InvitationCredential::where('guest_id', $guest->id)->whereIn('status', ['active', 'issued'])->count());
    }

    public function test_reissue_revokes_current_and_links_the_replacement(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['status' => 'accepted']);
        $first = $this->issuer()->activate($guest);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.credential.reissue', ['event' => $event->id, 'guest' => $guest->id]))
            ->assertStatus(201)
            ->assertJsonPath('credential.status', 'active')
            ->assertJsonStructure(['code']);

        $this->assertSame(InvitationCredential::STATUS_REVOKED, $first->fresh()->status);

        $second = $this->issuer()->current($guest->fresh());
        $this->assertNotNull($second);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->id, $second->replaces_id);
        $this->assertSame(1, InvitationCredential::where('guest_id', $guest->id)->where('status', 'active')->count());
    }

    public function test_credential_secret_is_hidden_from_serialization(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['status' => 'accepted']);
        $credential = $this->issuer()->activate($guest);

        $array = $credential->toArray();

        $this->assertArrayNotHasKey('code_hash', $array);
        $this->assertArrayNotHasKey('code_encrypted', $array);
        $this->assertArrayNotHasKey('active_key', $array);
        $this->assertNotNull($credential->revealSecret());
    }

    // ------------------------------------------------------------ authorization

    public function test_credential_endpoints_require_authentication_and_scope(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);
        $intruder = $this->owner();

        $issue = route('api.events.guests.credential.issue', ['event' => $event->id, 'guest' => $guest->id]);

        $this->postJson($issue)->assertStatus(401);

        $this->actingAs($intruder)->postJson($issue)->assertForbidden();
    }

    public function test_credential_for_guest_of_another_event_is_not_found(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $otherEvent = $this->event($owner);
        $guest = $this->guest($event);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.credential.issue', ['event' => $otherEvent->id, 'guest' => $guest->id]))
            ->assertNotFound();
    }

    // ---------------------------------------------------------- validity window

    public function test_credential_is_not_usable_before_the_validity_window(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, now()->addDays(3)->toDateTimeString());
        $guest = $this->guest($event, ['status' => 'accepted']);
        $credential = $this->issuer()->activate($guest);

        $this->assertSame(CredentialAccess::NOT_YET, CredentialAccess::status($credential));

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.check-in', ['event' => $event->id, 'guest' => $guest->id]), ['method' => 'manual'])
            ->assertStatus(422);

        $this->assertSame(0, Attendance::where('guest_id', $guest->id)->count());
    }

    public function test_credential_expires_after_the_validity_window(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, now()->subDays(2)->toDateTimeString());
        $guest = $this->guest($event, ['status' => 'accepted']);
        $credential = $this->issuer()->activate($guest);

        $this->assertSame(CredentialAccess::EXPIRED, CredentialAccess::status($credential));
    }

    // ------------------------------------------------------------------ check-in

    public function test_lookup_never_records_attendance(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, now()->subHour()->toDateTimeString());
        $guest = $this->guest($event, ['status' => 'accepted']);
        $credential = $this->issuer()->activate($guest);

        $this->actingAs($owner)
            ->getJson(route('api.events.check-in.lookup', ['event' => $event->id, 'guest_id' => $guest->id]))
            ->assertOk()
            ->assertJsonPath('state', 'valid')
            ->assertJsonPath('checked_in', false);

        $this->assertSame(0, Attendance::where('guest_id', $guest->id)->count());
    }

    public function test_check_in_records_attendance_with_operator_attribution_and_audit(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, now()->subHour()->toDateTimeString());
        $guest = $this->guest($event, ['status' => 'accepted']);
        $credential = $this->issuer()->activate($guest);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.check-in', ['event' => $event->id, 'guest' => $guest->id]), [
                'method' => 'manual',
                'code' => $credential->revealSecret(),
                'party_size' => 2,
            ])
            ->assertStatus(201)
            ->assertJsonPath('attendance.status', 'valid')
            ->assertJsonPath('attendance.party_size', 2);

        $attendance = Attendance::where('guest_id', $guest->id)->firstOrFail();
        $this->assertSame($owner->id, $attendance->checked_in_by);
        $this->assertSame(Attendance::STATUS_VALID, $attendance->status);
        $this->assertNotNull($attendance->checked_in_at);

        $this->assertSame(1, ActivityLog::where('action', 'attendance.check_in')->count());
    }

    public function test_duplicate_check_in_is_rejected(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, now()->subHour()->toDateTimeString());
        $guest = $this->guest($event, ['status' => 'accepted']);
        $this->issuer()->activate($guest);

        $url = route('api.events.guests.check-in', ['event' => $event->id, 'guest' => $guest->id]);

        $this->actingAs($owner)->postJson($url, ['method' => 'manual'])->assertStatus(201);
        $this->actingAs($owner)->postJson($url, ['method' => 'manual'])->assertStatus(422);

        $this->assertSame(1, Attendance::where('guest_id', $guest->id)->where('status', 'valid')->count());
    }

    public function test_a_mismatched_code_is_rejected(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, now()->subHour()->toDateTimeString());
        $guest = $this->guest($event, ['status' => 'accepted']);
        $other = $this->guest($event, ['status' => 'accepted']);
        $this->issuer()->activate($guest);
        $otherCredential = $this->issuer()->activate($other);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.check-in', ['event' => $event->id, 'guest' => $guest->id]), [
                'method' => 'code',
                'code' => $otherCredential->revealSecret(),
            ])
            ->assertStatus(422);

        $this->assertSame(0, Attendance::where('guest_id', $guest->id)->count());
    }

    public function test_reversal_preserves_history_and_allows_a_corrected_check_in(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, now()->subHour()->toDateTimeString());
        $guest = $this->guest($event, ['status' => 'accepted']);
        $this->issuer()->activate($guest);

        $checkIn = route('api.events.guests.check-in', ['event' => $event->id, 'guest' => $guest->id]);

        $this->actingAs($owner)->postJson($checkIn, ['method' => 'manual'])->assertStatus(201);
        $attendance = Attendance::where('guest_id', $guest->id)->firstOrFail();

        $this->actingAs($owner)
            ->postJson(route('api.events.attendances.reverse', ['event' => $event->id, 'attendance' => $attendance->id]), [
                'note' => 'Wrong guest scanned',
            ])
            ->assertOk()
            ->assertJsonPath('attendance.status', 'reversed');

        $attendance->refresh();
        $this->assertSame(Attendance::STATUS_REVERSED, $attendance->status);
        $this->assertNull($attendance->active_key);
        $this->assertSame($owner->id, $attendance->reversed_by);
        $this->assertSame('Wrong guest scanned', $attendance->reversal_reason);

        // A corrected check-in is now possible and both rows are retained.
        $this->actingAs($owner)->postJson($checkIn, ['method' => 'manual'])->assertStatus(201);
        $this->assertSame(2, Attendance::where('guest_id', $guest->id)->count());
        $this->assertSame(1, Attendance::where('guest_id', $guest->id)->where('status', 'valid')->count());
        $this->assertSame(1, ActivityLog::where('action', 'attendance.reverse')->count());
    }

    public function test_check_in_is_not_possible_for_a_declined_guest(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, now()->subHour()->toDateTimeString());
        $guest = $this->guest($event, ['status' => 'declined']);
        $this->issuer()->activate($guest);

        // activate() forces an active credential; simulate a decline via revoke.
        $this->issuer()->revoke($guest, 'rsvp_declined');

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.check-in', ['event' => $event->id, 'guest' => $guest->id]), ['method' => 'manual'])
            ->assertStatus(422);

        $this->assertSame(0, Attendance::where('guest_id', $guest->id)->count());
    }
}
