<?php

namespace Tests\Feature\Api;

use App\Models\Contact;
use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function event(User $owner, array $attributes = []): Event
    {
        return Event::factory()->create(array_merge(['user_id' => $owner->id], $attributes));
    }

    private function guest(Event $event, array $attributes = []): Guest
    {
        return Guest::factory()->create(array_merge(['event_id' => $event->id], $attributes));
    }

    public function test_owner_can_verify_a_guest_belonging_to_their_event(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event, ['status' => 'pending']);

        $response = $this->actingAs($owner)
            ->getJson(route('api.events.guests.verification', [$event->id, $guest->id]))
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('reason', null)
            ->assertJsonPath('guest.id', $guest->id)
            ->assertJsonPath('guest.status', 'pending')
            ->assertJsonPath('event.id', $event->id)
            ->assertJsonStructure([
                'valid',
                'reason',
                'guest' => ['id', 'name', 'status'],
                'event' => ['id', 'title', 'event_start'],
            ]);

        // Minimal payload only: no token and no contact data.
        $response->assertJsonMissingPath('guest.invite_token');
        $response->assertJsonMissingPath('guest.contact');
        $this->assertStringNotContainsString((string) $guest->invite_token, (string) $response->getContent());
    }

    public function test_verification_requires_authentication(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);

        $this->getJson(route('api.events.guests.verification', [$event->id, $guest->id]))->assertStatus(401);
    }

    public function test_staff_can_verify_a_guest_on_their_owners_event(): void
    {
        $owner = $this->owner();
        $staff = User::factory()->create(['role' => 'staff', 'owner_id' => $owner->id]);
        $event = $this->event($owner);
        $guest = $this->guest($event);

        $this->actingAs($staff)
            ->getJson(route('api.events.guests.verification', [$event->id, $guest->id]))
            ->assertOk()
            ->assertJsonPath('valid', true);
    }

    public function test_cross_account_verification_is_forbidden(): void
    {
        $owner = $this->owner();
        $intruder = $this->owner();
        $event = $this->event($owner);
        $guest = $this->guest($event);

        $this->actingAs($intruder)
            ->getJson(route('api.events.guests.verification', [$event->id, $guest->id]))
            ->assertForbidden();
    }

    public function test_guest_from_another_event_is_not_found(): void
    {
        $owner = $this->owner();
        $eventA = $this->event($owner);
        $eventB = $this->event($owner);
        $guestB = $this->guest($eventB);

        $this->actingAs($owner)
            ->getJson(route('api.events.guests.verification', [$eventA->id, $guestB->id]))
            ->assertNotFound();
    }

    public function test_expired_invitation_reports_the_reason(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner, ['event_start' => now()->subDay()]);
        $guest = $this->guest($event);

        $this->actingAs($owner)
            ->getJson(route('api.events.guests.verification', [$event->id, $guest->id]))
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('reason', 'expired');
    }

    public function test_verification_does_not_leak_contact_data(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $contact = Contact::factory()->create([
            'user_id' => $owner->id,
            'name' => 'Private Contact',
            'email' => 'private@example.test',
            'normalized_email' => 'private@example.test',
            'phone' => null,
            'normalized_phone' => null,
        ]);
        $guest = $this->guest($event, ['contact_id' => $contact->id]);

        $response = $this->actingAs($owner)
            ->getJson(route('api.events.guests.verification', [$event->id, $guest->id]))
            ->assertOk();

        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('private@example.test', $content);
        $this->assertStringNotContainsString('Private Contact', $content);
        $response->assertJsonMissingPath('guest.contact_id');
    }
}
