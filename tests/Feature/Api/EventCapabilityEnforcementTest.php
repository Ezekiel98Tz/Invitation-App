<?php

namespace Tests\Feature\Api;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationCredential;
use App\Models\Pledge;
use App\Models\PledgeFulfillment;
use App\Models\PledgeImportBatch;
use App\Models\User;
use App\Services\CredentialIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Server-side capability enforcement.
 *
 * Frontend hiding is not authorization: when a capability is disabled for an
 * event the API must deny the corresponding endpoints, regardless of the UI.
 * `contributions` and `check_in` are implemented but default-off, so these
 * tests prove the default denies, opt-in allows, and ownership/staff rules are
 * still applied on top.
 */
class EventCapabilityEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function staff(User $owner): User
    {
        return User::factory()->create(['role' => 'staff', 'owner_id' => $owner->id]);
    }

    /**
     * @param  array<string, bool>  $capabilities
     * @param  array<string, mixed>  $attributes
     */
    private function event(User $owner, array $capabilities = [], array $attributes = []): Event
    {
        return Event::factory()->create(array_merge([
            'user_id' => $owner->id,
            'event_start' => now()->subHour(),
            'settings' => ['capabilities' => $capabilities],
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function guest(Event $event, array $attributes = []): Guest
    {
        return Guest::factory()->create(array_merge([
            'event_id' => $event->id,
            'status' => 'accepted',
            'email' => 'guest@example.test',
            'phone' => '+255700000001',
        ], $attributes));
    }

    private function pledge(Event $event): Pledge
    {
        return Pledge::factory()->create([
            'user_id' => $event->user_id,
            'event_id' => $event->id,
            'currency' => 'USD',
            'pledged_amount_minor' => 1000,
        ]);
    }

    private function batch(Event $event): PledgeImportBatch
    {
        return PledgeImportBatch::create([
            'user_id' => $event->user_id,
            'event_id' => $event->id,
            'original_filename' => 'pledges.csv',
            'status' => PledgeImportBatch::STATUS_UPLOADED,
            'columns' => ['pledger_name'],
            'mapping' => ['pledger_name' => 'pledger_name'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(array $settings = []): array
    {
        return [
            'title' => 'Capability Event',
            'event_start' => now()->addWeek()->toDateTimeString(),
            'venue' => 'The Hall',
            'settings' => $settings,
        ];
    }

    // ------------------------------------------------------------ contributions

    public function test_pledge_endpoints_are_denied_when_contributions_is_disabled(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, ['contributions' => false]);
        $pledge = $this->pledge($event);
        $fulfillment = PledgeFulfillment::factory()->create(['pledge_id' => $pledge->id]);
        $batch = $this->batch($event);

        // A clear denial, never a 500.
        $this->actingAs($owner)
            ->getJson(route('api.events.pledges.index', $event))
            ->assertForbidden()
            ->assertJsonPath('message', 'The contributions capability is not enabled for this event.');

        $this->actingAs($owner)->getJson(route('api.events.pledges.summary', $event))->assertForbidden();
        $this->actingAs($owner)->getJson(route('api.events.pledges.show', [$event, $pledge]))->assertForbidden();
        $this->actingAs($owner)->postJson(route('api.events.pledges.store', $event), [
            'pledger_name' => 'Blocked',
            'currency' => 'USD',
            'pledged_amount_minor' => 100,
        ])->assertForbidden();
        $this->actingAs($owner)->patchJson(route('api.events.pledges.update', [$event, $pledge]), ['pledger_name' => 'Nope'])->assertForbidden();
        $this->actingAs($owner)->postJson(route('api.events.pledges.cancel', [$event, $pledge]))->assertForbidden();
        $this->actingAs($owner)->postJson(route('api.events.pledges.fulfillments.store', [$event, $pledge]), ['amount_minor' => 100])->assertForbidden();
        $this->actingAs($owner)->postJson(route('api.events.pledges.fulfillments.reverse', [$event, $pledge, $fulfillment]))->assertForbidden();

        // Import/export endpoints are gated too.
        $this->actingAs($owner)->postJson(route('api.events.pledges.import.upload', $event), [
            'file' => UploadedFile::fake()->createWithContent('pledges.csv', "pledger_name\nAlice"),
        ])->assertForbidden();
        $this->actingAs($owner)->getJson(route('api.events.pledges.import.show', [$event, $batch]))->assertForbidden();
        $this->actingAs($owner)->getJson(route('api.events.pledges.import.template', $event))->assertForbidden();
        $this->actingAs($owner)->getJson(route('api.events.pledges.export', $event))->assertForbidden();

        // Nothing was mutated.
        $this->assertSame('USD', $pledge->fresh()->currency);
        $this->assertSame(1, Pledge::where('event_id', $event->id)->count());
    }

    public function test_pledge_endpoints_are_allowed_when_contributions_is_enabled(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, ['contributions' => true]);

        $this->actingAs($owner)
            ->getJson(route('api.events.pledges.index', $event))
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->actingAs($owner)->getJson(route('api.events.pledges.summary', $event))->assertOk();

        $created = $this->actingAs($owner)->postJson(route('api.events.pledges.store', $event), [
            'pledger_name' => 'Acme Ltd',
            'currency' => 'USD',
            'pledged_amount_minor' => 5000,
        ])->assertStatus(201);

        $pledgeId = $created->json('id');

        $this->actingAs($owner)->getJson(route('api.events.pledges.show', [$event, $pledgeId]))->assertOk();
        $this->actingAs($owner)->patchJson(route('api.events.pledges.update', [$event, $pledgeId]), ['pledger_name' => 'Acme 2'])->assertOk();
        $this->actingAs($owner)->postJson(route('api.events.pledges.fulfillments.store', [$event, $pledgeId]), ['amount_minor' => 1000])->assertStatus(201);
        $this->actingAs($owner)->postJson(route('api.events.pledges.cancel', [$event, $pledgeId]))->assertOk();
    }

    public function test_contributions_enforcement_respects_accounts_and_staff(): void
    {
        $owner = $this->owner();
        $staff = $this->staff($owner);
        $intruder = $this->owner();
        $otherStaff = $this->staff($intruder);

        // Disabled: everyone is denied, including the owner and their staff.
        $disabled = $this->event($owner, ['contributions' => false]);
        foreach ([$owner, $staff, $intruder, $otherStaff] as $user) {
            $this->actingAs($user)
                ->getJson(route('api.events.pledges.index', $disabled))
                ->assertForbidden();
        }

        // Enabled: capability passes, then normal ownership/staff rules apply.
        $enabled = $this->event($owner, ['contributions' => true]);
        $this->actingAs($owner)->getJson(route('api.events.pledges.index', $enabled))->assertOk();
        $this->actingAs($staff)->getJson(route('api.events.pledges.index', $enabled))->assertOk();
        $this->actingAs($intruder)->getJson(route('api.events.pledges.index', $enabled))->assertForbidden();
        $this->actingAs($otherStaff)->getJson(route('api.events.pledges.index', $enabled))->assertForbidden();
    }

    // ----------------------------------------------------------------- check_in

    public function test_check_in_endpoints_are_denied_when_check_in_is_disabled(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, ['check_in' => false]);
        $guest = $this->guest($event);
        $credential = app(CredentialIssuer::class)->activate($guest);

        $attendance = Attendance::create([
            'event_id' => $event->id,
            'guest_id' => $guest->id,
            'credential_id' => $credential->id,
            'checked_in_at' => now(),
            'checked_in_by' => $owner->id,
            'method' => Attendance::METHOD_MANUAL,
            'party_size' => 1,
            'status' => Attendance::STATUS_VALID,
            'active_key' => $guest->id,
        ]);

        // A clear denial, never a 500.
        $this->actingAs($owner)
            ->getJson(route('api.events.check-in.lookup', ['event' => $event->id, 'guest_id' => $guest->id]))
            ->assertForbidden()
            ->assertJsonPath('message', 'The check_in capability is not enabled for this event.');

        $this->actingAs($owner)->getJson(route('api.events.attendances.index', $event))->assertForbidden();
        $this->actingAs($owner)->postJson(route('api.events.guests.check-in', ['event' => $event->id, 'guest' => $guest->id]), ['method' => 'manual'])->assertForbidden();
        $this->actingAs($owner)->postJson(route('api.events.attendances.reverse', ['event' => $event->id, 'attendance' => $attendance->id]), ['note' => 'x'])->assertForbidden();

        // Operator credential endpoints require check_in since credentials exist for entry.
        $this->actingAs($owner)->getJson(route('api.events.guests.credential.show', ['event' => $event->id, 'guest' => $guest->id]))->assertForbidden();
        $this->actingAs($owner)->postJson(route('api.events.guests.credential.issue', ['event' => $event->id, 'guest' => $guest->id]))->assertForbidden();
        $this->actingAs($owner)->postJson(route('api.events.guests.credential.reveal', ['event' => $event->id, 'guest' => $guest->id]))->assertForbidden();
        $this->actingAs($owner)->postJson(route('api.events.guests.credential.resend', ['event' => $event->id, 'guest' => $guest->id]))->assertForbidden();

        $this->assertSame(Attendance::STATUS_VALID, $attendance->fresh()->status);
    }

    public function test_check_in_and_credential_endpoints_are_allowed_when_check_in_is_enabled(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, ['check_in' => true]);
        $guest = $this->guest($event);
        $credential = app(CredentialIssuer::class)->activate($guest);

        $this->actingAs($owner)
            ->getJson(route('api.events.check-in.lookup', ['event' => $event->id, 'guest_id' => $guest->id]))
            ->assertOk()
            ->assertJsonPath('checked_in', false);

        $checkIn = $this->actingAs($owner)->postJson(route('api.events.guests.check-in', ['event' => $event->id, 'guest' => $guest->id]), [
            'method' => 'manual',
            'code' => $credential->revealSecret(),
        ])->assertStatus(201);

        $attendanceId = $checkIn->json('attendance.id');

        $this->actingAs($owner)
            ->getJson(route('api.events.attendances.index', $event))
            ->assertOk()
            ->assertJsonPath('checked_in', 1);

        $this->actingAs($owner)->postJson(route('api.events.attendances.reverse', ['event' => $event->id, 'attendance' => $attendanceId]), ['note' => 'correction'])->assertOk();

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.credential.issue', ['event' => $event->id, 'guest' => $guest->id]))
            ->assertStatus(201)
            ->assertJsonStructure(['credential', 'code']);
    }

    public function test_check_in_enforcement_respects_accounts_and_staff(): void
    {
        $owner = $this->owner();
        $staff = $this->staff($owner);
        $intruder = $this->owner();
        $otherStaff = $this->staff($intruder);

        $disabled = $this->event($owner, ['check_in' => false]);
        $disabledGuest = $this->guest($disabled);
        foreach ([$owner, $staff, $intruder, $otherStaff] as $user) {
            $this->actingAs($user)
                ->getJson(route('api.events.check-in.lookup', ['event' => $disabled->id, 'guest_id' => $disabledGuest->id]))
                ->assertForbidden();
        }

        $enabled = $this->event($owner, ['check_in' => true]);
        $enabledGuest = $this->guest($enabled);
        app(CredentialIssuer::class)->activate($enabledGuest);

        $lookup = route('api.events.check-in.lookup', ['event' => $enabled->id, 'guest_id' => $enabledGuest->id]);
        $this->actingAs($owner)->getJson($lookup)->assertOk();
        $this->actingAs($staff)->getJson($lookup)->assertOk();
        $this->actingAs($intruder)->getJson($lookup)->assertForbidden();
        $this->actingAs($otherStaff)->getJson($lookup)->assertForbidden();
    }

    // ------------------------------------------------ unknown / unimplemented

    public function test_unimplemented_capability_cannot_be_enabled_via_api(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->postJson(route('api.events.store'), $this->eventPayload([
                'capabilities' => ['households' => true],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('settings.capabilities');
    }

    public function test_stored_unimplemented_capabilities_stay_off_and_stay_denied(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, ['households' => true, 'waitlist' => true, 'contributions' => false]);

        $this->assertFalse($event->capabilities['households']);
        $this->assertFalse($event->capabilities['waitlist']);
        $this->assertFalse($event->capabilities['contributions']);

        $this->actingAs($owner)->getJson(route('api.events.pledges.index', $event))->assertForbidden();
    }

    // ------------------------------------------------------ separation of concepts

    public function test_public_rsvp_flow_is_not_gated_by_contributions_or_check_in(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, ['contributions' => false, 'check_in' => false], ['event_start' => now()->addHour()]);
        $guest = $this->guest($event, ['status' => 'pending']);

        // The public invitation still resolves and RSVP still works...
        $this->getJson(route('api.invites.show', ['token' => $guest->invite_token]))->assertOk();
        $this->postJson(route('api.invites.store', ['token' => $guest->invite_token]), ['status' => 'accepted'])->assertOk();

        // ...and acceptance still issues an entry credential, independent of the
        // check_in capability (credential validity is a separate concept).
        $this->assertNotNull(InvitationCredential::where('guest_id', $guest->id)->first());
    }
}
