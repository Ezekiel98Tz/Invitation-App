<?php

namespace Tests\Feature\Api;

use App\Models\Contact;
use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class GuestContactIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function event(User $owner): Event
    {
        return Event::factory()->create(['user_id' => $owner->id]);
    }

    public function test_creating_a_guest_resolves_and_reuses_an_account_contact(): void
    {
        $owner = $this->owner();
        $eventA = $this->event($owner);
        $eventB = $this->event($owner);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.store', [$eventA->id]), ['name' => 'Ada', 'email' => 'ada@example.test'])
            ->assertStatus(201);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.store', [$eventB->id]), ['name' => 'Ada Again', 'email' => '  ADA@Example.TEST '])
            ->assertStatus(201);

        $this->assertSame(1, Contact::count());
        $contact = Contact::first();
        $this->assertSame($owner->id, $contact->user_id);
        $this->assertSame($contact->id, Guest::where('event_id', $eventA->id)->first()->contact_id);
        $this->assertSame($contact->id, Guest::where('event_id', $eventB->id)->first()->contact_id);
    }

    public function test_guest_api_response_does_not_expose_contact_id(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $response = $this->actingAs($owner)
            ->postJson(route('api.events.guests.store', [$event->id]), ['name' => 'Ada', 'email' => 'ada@example.test'])
            ->assertStatus(201);

        $this->assertArrayNotHasKey('contact_id', $response->json());
        $response->assertJsonMissingPath('contact_id');
    }

    public function test_client_supplied_contact_id_is_ignored(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $foreign = Contact::factory()->create([
            'user_id' => User::factory()->create()->id,
            'email' => 'foreign@example.test',
            'normalized_email' => 'foreign@example.test',
            'phone' => null,
            'normalized_phone' => null,
        ]);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.store', [$event->id]), [
                'name' => 'Ada',
                'email' => 'ada@example.test',
                'contact_id' => $foreign->id,
            ])
            ->assertStatus(201);

        $guest = Guest::where('email', 'ada@example.test')->first();
        $this->assertNotSame($foreign->id, $guest->contact_id);
        $this->assertSame($owner->id, $guest->contact->user_id);
    }

    public function test_event_level_duplicate_detection_is_unchanged(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.store', [$event->id]), ['name' => 'Ada', 'email' => 'dup@example.test'])
            ->assertStatus(201);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.store', [$event->id]), ['name' => 'Ada Two', 'email' => 'dup@example.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame(1, Guest::count());
        $this->assertSame(1, Contact::count());
    }

    public function test_creation_conflict_returns_a_validation_error_and_creates_nothing(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        Contact::factory()->create(['user_id' => $owner->id, 'email' => 'a@example.test', 'normalized_email' => 'a@example.test', 'phone' => null, 'normalized_phone' => null]);
        Contact::factory()->create(['user_id' => $owner->id, 'email' => null, 'normalized_email' => null, 'phone' => '+255700000001', 'normalized_phone' => '255700000001']);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.store', [$event->id]), ['name' => 'Conflict', 'email' => 'a@example.test', 'phone' => '+255700000001'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame(0, Guest::count());
        $this->assertSame(2, Contact::count());
    }

    public function test_staff_creation_resolves_within_the_owner_account(): void
    {
        $owner = $this->owner();
        $staff = User::factory()->create(['role' => 'staff', 'owner_id' => $owner->id]);
        $event = $this->event($owner);
        $ownerContact = Contact::factory()->create([
            'user_id' => $owner->id,
            'email' => 'team@example.test',
            'normalized_email' => 'team@example.test',
            'phone' => null,
            'normalized_phone' => null,
        ]);

        $this->actingAs($staff)
            ->postJson(route('api.events.guests.store', [$event->id]), ['name' => 'Member', 'email' => 'team@example.test'])
            ->assertStatus(201);

        $guest = Guest::first();
        $this->assertSame($ownerContact->id, $guest->contact_id);
        $this->assertSame($owner->id, $guest->contact->user_id);
        $this->assertSame(1, Contact::count());
    }

    public function test_cross_account_contact_is_never_reused(): void
    {
        $ownerA = $this->owner();
        $ownerB = $this->owner();
        $eventA = $this->event($ownerA);
        $foreign = Contact::factory()->create([
            'user_id' => $ownerB->id,
            'email' => 'shared@example.test',
            'normalized_email' => 'shared@example.test',
            'phone' => null,
            'normalized_phone' => null,
        ]);

        $this->actingAs($ownerA)
            ->postJson(route('api.events.guests.store', [$eventA->id]), ['name' => 'X', 'email' => 'shared@example.test'])
            ->assertStatus(201);

        $guest = Guest::first();
        $this->assertNotSame($foreign->id, $guest->contact_id);
        $this->assertSame($ownerA->id, $guest->contact->user_id);
        $this->assertSame(2, Contact::count());
    }

    public function test_update_name_only_keeps_the_contact(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.store', [$event->id]), ['name' => 'Ada', 'email' => 'ada@example.test'])
            ->assertStatus(201);

        $guest = Guest::first();
        $contactId = $guest->contact_id;
        $this->assertNotNull($contactId);

        $this->actingAs($owner)
            ->putJson(route('api.events.guests.update', [$event->id, $guest->id]), ['name' => 'Ada Lovelace'])
            ->assertOk();

        $guest->refresh();
        $this->assertSame('Ada Lovelace', $guest->name);
        $this->assertSame($contactId, $guest->contact_id);
        $this->assertSame(1, Contact::count());
    }

    public function test_update_to_a_novel_email_keeps_the_contact(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.store', [$event->id]), ['name' => 'Ada', 'email' => 'ada@example.test'])
            ->assertStatus(201);

        $guest = Guest::first();
        $contactId = $guest->contact_id;

        $this->actingAs($owner)
            ->putJson(route('api.events.guests.update', [$event->id, $guest->id]), ['email' => 'nobody-else@example.test'])
            ->assertOk();

        $guest->refresh();
        $this->assertSame('nobody-else@example.test', $guest->email);
        $this->assertSame($contactId, $guest->contact_id);
        $this->assertSame(1, Contact::count());
    }

    public function test_update_to_another_contacts_email_is_rejected_and_preserves_data(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.store', [$event->id]), ['name' => 'Ada', 'email' => 'ada@example.test'])
            ->assertStatus(201);

        $guest = Guest::first();
        $contactId = $guest->contact_id;

        Contact::factory()->create(['user_id' => $owner->id, 'email' => 'other@example.test', 'normalized_email' => 'other@example.test', 'phone' => null, 'normalized_phone' => null]);

        $this->actingAs($owner)
            ->putJson(route('api.events.guests.update', [$event->id, $guest->id]), ['email' => 'other@example.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $guest->refresh();
        $this->assertSame('ada@example.test', $guest->email);
        $this->assertSame($contactId, $guest->contact_id);
    }

    public function test_update_to_another_contacts_phone_is_rejected(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.store', [$event->id]), ['name' => 'Ada', 'email' => 'ada@example.test', 'phone' => '+255700000001'])
            ->assertStatus(201);

        $guest = Guest::first();
        $contactId = $guest->contact_id;

        Contact::factory()->create(['user_id' => $owner->id, 'email' => null, 'normalized_email' => null, 'phone' => '+255700000002', 'normalized_phone' => '255700000002']);

        $this->actingAs($owner)
            ->putJson(route('api.events.guests.update', [$event->id, $guest->id]), ['phone' => '+255700000002'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $guest->refresh();
        $this->assertSame('+255700000001', $guest->phone);
        $this->assertSame($contactId, $guest->contact_id);
    }

    public function test_guest_operational_fields_and_token_are_intact_after_creation(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.store', [$event->id]), ['name' => 'Ada', 'email' => 'ada@example.test', 'phone' => '+255700000001'])
            ->assertStatus(201);

        $guest = Guest::first();
        $this->assertSame($event->id, $guest->event_id);
        $this->assertSame('Ada', $guest->name);
        $this->assertSame('ada@example.test', $guest->email);
        $this->assertSame('+255700000001', $guest->phone);
        $this->assertSame('pending', $guest->status);
        $this->assertSame(32, strlen((string) $guest->invite_token));
        $this->assertNotNull($guest->contact_id);
    }

    public function test_failed_guest_creation_leaves_no_orphan_contact(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        Guest::creating(function () {
            throw new RuntimeException('boom');
        });

        try {
            $this->withoutExceptionHandling();
            try {
                $this->actingAs($owner)
                    ->postJson(route('api.events.guests.store', [$event->id]), ['name' => 'Ada', 'email' => 'ada@example.test']);
                $this->fail('Expected the guest creation to throw.');
            } catch (RuntimeException $e) {
                // expected
            }
        } finally {
            Guest::flushEventListeners();
        }

        $this->assertSame(0, Contact::count());
        $this->assertSame(0, Guest::count());
    }
}
