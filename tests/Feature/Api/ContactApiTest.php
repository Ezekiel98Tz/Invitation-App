<?php

namespace Tests\Feature\Api;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ContactApiTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contact(User $owner, array $attributes = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'user_id' => $owner->id,
            'phone' => null,
            'normalized_phone' => null,
        ], $attributes));
    }

    private function event(User $owner): Event
    {
        return Event::factory()->create(['user_id' => $owner->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function guest(Event $event, array $attributes = []): Guest
    {
        return Guest::factory()->create(array_merge(['event_id' => $event->id], $attributes));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson(route('api.contacts.index'))->assertStatus(401);
    }

    public function test_index_lists_account_contacts_with_counts_and_hides_internal_fields(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $contact = $this->contact($owner, ['name' => 'Ada', 'email' => 'ada@example.test', 'normalized_email' => 'ada@example.test']);
        $this->guest($event, ['contact_id' => $contact->id]);
        $this->guest($event, ['contact_id' => $contact->id]);
        $this->contact($owner, ['name' => 'Bob', 'email' => 'bob@example.test', 'normalized_email' => 'bob@example.test']);

        $response = $this->actingAs($owner)->getJson(route('api.contacts.index'))->assertOk();

        $this->assertSame(2, $response->json('total'));
        $response->assertJsonPath('data.0.name', 'Ada');
        $response->assertJsonPath('data.0.guests_count', 2);
        $response->assertJsonMissingPath('data.0.normalized_email');
        $response->assertJsonMissingPath('data.0.normalized_phone');
        $response->assertJsonMissingPath('data.0.user_id');
    }

    public function test_index_is_scoped_to_the_owner(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $this->contact($owner, ['name' => 'Mine', 'email' => 'mine@example.test', 'normalized_email' => 'mine@example.test']);
        $this->contact($other, ['name' => 'Theirs', 'email' => 'theirs@example.test', 'normalized_email' => 'theirs@example.test']);

        $this->actingAs($owner)
            ->getJson(route('api.contacts.index'))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Mine');
    }

    public function test_index_search_matches_name_email_and_normalized_values(): void
    {
        $owner = $this->owner();
        $this->contact($owner, ['name' => 'Amara', 'email' => 'amara@example.test', 'normalized_email' => 'amara@example.test', 'phone' => '+255 700-000-001', 'normalized_phone' => '255700000001']);
        $this->contact($owner, ['name' => 'Baraka', 'email' => 'baraka@example.test', 'normalized_email' => 'baraka@example.test']);

        $this->actingAs($owner)
            ->getJson(route('api.contacts.index', ['search' => 'Amara']))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Amara');

        // Normalized email match (different case).
        $this->actingAs($owner)
            ->getJson(route('api.contacts.index', ['search' => 'AMARA@Example.TEST']))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Amara');

        // Normalized phone match.
        $this->actingAs($owner)
            ->getJson(route('api.contacts.index', ['search' => '255700000001']))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Amara');
    }

    public function test_index_paginates_and_orders_by_name_then_id(): void
    {
        $owner = $this->owner();
        for ($i = 1; $i <= 25; $i++) {
            $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $this->contact($owner, ['name' => "Person {$n}", 'email' => "person{$n}@example.test", 'normalized_email' => "person{$n}@example.test"]);
        }

        $first = $this->actingAs($owner)->getJson(route('api.contacts.index'))->assertOk();
        $this->assertSame(25, $first->json('total'));
        $this->assertCount(20, $first->json('data'));
        $first->assertJsonPath('data.0.name', 'Person 01');
        $first->assertJsonPath('last_page', 2);

        $second = $this->actingAs($owner)->getJson(route('api.contacts.index', ['page' => 2]))->assertOk();
        $this->assertCount(5, $second->json('data'));
        $second->assertJsonPath('data.0.name', 'Person 21');
    }

    public function test_show_returns_contact_details_and_hides_internal_fields(): void
    {
        $owner = $this->owner();
        $contact = $this->contact($owner, ['name' => 'Ada', 'email' => 'ada@example.test', 'normalized_email' => 'ada@example.test', 'notes' => 'VIP']);

        $this->actingAs($owner)
            ->getJson(route('api.contacts.show', $contact))
            ->assertOk()
            ->assertJsonPath('id', $contact->id)
            ->assertJsonPath('name', 'Ada')
            ->assertJsonPath('notes', 'VIP')
            ->assertJsonPath('guests_count', 0)
            ->assertJsonMissingPath('normalized_email')
            ->assertJsonMissingPath('user_id');
    }

    public function test_linked_guests_are_paginated_with_event_details_and_hide_contact_id(): void
    {
        $owner = $this->owner();
        $eventA = $this->event($owner);
        $eventB = $this->event($owner);
        $contact = $this->contact($owner, ['email' => 'ada@example.test', 'normalized_email' => 'ada@example.test']);
        $g1 = $this->guest($eventA, ['contact_id' => $contact->id, 'name' => 'Guest One']);
        $this->guest($eventB, ['contact_id' => $contact->id, 'name' => 'Guest Two']);

        $response = $this->actingAs($owner)->getJson(route('api.contacts.guests', $contact))->assertOk();

        $this->assertSame(2, $response->json('total'));
        $response->assertJsonPath('data.0.id', $g1->id);
        $response->assertJsonPath('data.0.event.title', $eventA->title);
        $response->assertJsonStructure(['data' => [['id', 'name', 'email', 'phone', 'status', 'event' => ['id', 'title', 'event_start']]]]);
        $this->assertArrayNotHasKey('contact_id', $response->json('data.0'));
    }

    public function test_linked_guests_exclude_guests_from_other_accounts(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $contact = $this->contact($owner, ['email' => 'ada@example.test', 'normalized_email' => 'ada@example.test']);
        $this->guest($this->event($owner), ['contact_id' => $contact->id]);

        // A guest belonging to another account's event, force-linked to the contact.
        $foreignEvent = $this->event($other);
        $this->guest($foreignEvent, ['contact_id' => $contact->id]);

        $this->actingAs($owner)
            ->getJson(route('api.contacts.guests', $contact))
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_partial_update_changes_only_provided_fields_and_recomputes_normalized(): void
    {
        $owner = $this->owner();
        $contact = $this->contact($owner, ['name' => 'Ada', 'email' => 'ada@example.test', 'normalized_email' => 'ada@example.test', 'notes' => 'keep me']);

        $this->actingAs($owner)
            ->patchJson(route('api.contacts.update', $contact), ['name' => 'Ada Lovelace'])
            ->assertOk()
            ->assertJsonPath('name', 'Ada Lovelace');

        $contact->refresh();
        $this->assertSame('ada@example.test', $contact->email);
        $this->assertSame('keep me', $contact->notes);
        $this->assertSame('ada@example.test', $contact->normalized_email);

        // Now change the email; the normalized key must be recomputed.
        $this->actingAs($owner)
            ->patchJson(route('api.contacts.update', $contact), ['email' => '  New.Address@Example.TEST '])
            ->assertOk();

        $contact->refresh();
        $this->assertSame('New.Address@Example.TEST', $contact->email);
        $this->assertSame('new.address@example.test', $contact->normalized_email);
    }

    public function test_update_rejects_duplicate_email(): void
    {
        $owner = $this->owner();
        $contact = $this->contact($owner, ['email' => 'a@example.test', 'normalized_email' => 'a@example.test']);
        $this->contact($owner, ['email' => 'b@example.test', 'normalized_email' => 'b@example.test']);

        $this->actingAs($owner)
            ->patchJson(route('api.contacts.update', $contact), ['email' => 'b@example.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame('a@example.test', $contact->fresh()->email);
    }

    public function test_update_rejects_duplicate_phone(): void
    {
        $owner = $this->owner();
        $contact = $this->contact($owner, ['email' => 'a@example.test', 'normalized_email' => 'a@example.test', 'phone' => '+255700000001', 'normalized_phone' => '255700000001']);
        $this->contact($owner, ['email' => 'b@example.test', 'normalized_email' => 'b@example.test', 'phone' => '+255700000002', 'normalized_phone' => '255700000002']);

        $this->actingAs($owner)
            ->patchJson(route('api.contacts.update', $contact), ['phone' => '+255 700-000-002'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_update_allows_clearing_optional_identifiers(): void
    {
        $owner = $this->owner();
        $contact = $this->contact($owner, ['email' => 'a@example.test', 'normalized_email' => 'a@example.test', 'phone' => '+255700000001', 'normalized_phone' => '255700000001']);

        $this->actingAs($owner)
            ->patchJson(route('api.contacts.update', $contact), ['email' => null, 'phone' => null])
            ->assertOk();

        $contact->refresh();
        $this->assertNull($contact->email);
        $this->assertNull($contact->phone);
        $this->assertNull($contact->normalized_email);
        $this->assertNull($contact->normalized_phone);
    }

    public function test_update_does_not_change_linked_guest_snapshots(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $contact = $this->contact($owner, ['email' => 'ada@example.test', 'normalized_email' => 'ada@example.test']);
        $guest = $this->guest($event, ['contact_id' => $contact->id, 'name' => 'Guest Name', 'email' => 'guest@example.test', 'phone' => '+255700000009']);

        $this->actingAs($owner)
            ->patchJson(route('api.contacts.update', $contact), ['name' => 'Renamed Contact', 'email' => 'renamed@example.test'])
            ->assertOk();

        $guest->refresh();
        $this->assertSame('Guest Name', $guest->name);
        $this->assertSame('guest@example.test', $guest->email);
        $this->assertSame('+255700000009', $guest->phone);
        $this->assertSame($contact->id, $guest->contact_id);
    }

    public function test_update_ignores_client_supplied_ownership_and_normalized_fields(): void
    {
        $owner = $this->owner();
        $contact = $this->contact($owner, ['name' => 'Ada', 'email' => 'ada@example.test', 'normalized_email' => 'ada@example.test']);
        $other = $this->owner();

        $this->actingAs($owner)
            ->patchJson(route('api.contacts.update', $contact), [
                'name' => 'Ada Two',
                'user_id' => $other->id,
                'normalized_email' => 'hacked@example.test',
            ])
            ->assertOk();

        $contact->refresh();
        $this->assertSame($owner->id, $contact->user_id);
        $this->assertSame('ada@example.test', $contact->normalized_email);
    }

    public function test_update_rejects_invalid_email(): void
    {
        $owner = $this->owner();
        $contact = $this->contact($owner, ['email' => 'ada@example.test', 'normalized_email' => 'ada@example.test']);

        $this->actingAs($owner)
            ->patchJson(route('api.contacts.update', $contact), ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_unique_violation_race_returns_a_validation_error(): void
    {
        $owner = $this->owner();
        $contact = $this->contact($owner, ['name' => 'Ada', 'email' => 'ada@example.test', 'normalized_email' => 'ada@example.test']);

        // Simulate a concurrent insert committing the same normalized email
        // after the pre-check but before this update is written.
        $injected = false;
        Contact::saving(function (Contact $saving) use ($owner, &$injected) {
            if ($injected) {
                return;
            }
            $injected = true;

            DB::table('contacts')->insert([
                'user_id' => $owner->id,
                'name' => 'Concurrent',
                'normalized_email' => 'race@example.test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $this->actingAs($owner)
                ->patchJson(route('api.contacts.update', $contact), ['email' => 'race@example.test'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('email');
        } finally {
            Contact::flushEventListeners();
        }

        $this->assertSame('ada@example.test', $contact->fresh()->normalized_email);
    }

    public function test_delete_detaches_guests_but_preserves_them_and_their_history(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);
        $contact = $this->contact($owner, ['email' => 'ada@example.test', 'normalized_email' => 'ada@example.test']);
        $guest = $this->guest($event, ['contact_id' => $contact->id]);
        $token = $guest->invite_token;

        \App\Models\Rsvp::create(['guest_id' => $guest->id, 'attending_count' => 2, 'answers' => ['meal' => 'veg']]);
        \App\Models\InvitationDelivery::create(['guest_id' => $guest->id, 'kind' => 'invitation', 'channel' => 'mail', 'status' => 'sent', 'provider_message_id' => 'p1']);

        $this->actingAs($owner)
            ->deleteJson(route('api.contacts.destroy', $contact))
            ->assertOk()
            ->assertJson(['deleted' => true, 'detached_guests' => 1]);

        $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);

        $guest->refresh();
        $this->assertNull($guest->contact_id);
        $this->assertDatabaseHas('guests', ['id' => $guest->id]);
        $this->assertSame($token, $guest->invite_token);
        $this->assertDatabaseHas('rsvps', ['guest_id' => $guest->id]);
        $this->assertDatabaseHas('invitation_deliveries', ['guest_id' => $guest->id, 'provider_message_id' => 'p1']);
    }

    public function test_update_and_delete_write_audit_logs(): void
    {
        $owner = $this->owner();
        $contact = $this->contact($owner, ['email' => 'ada@example.test', 'normalized_email' => 'ada@example.test']);

        $this->actingAs($owner)
            ->patchJson(route('api.contacts.update', $contact), ['notes' => 'updated'])
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $owner->id,
            'action' => 'contacts.update',
            'auditable_type' => Contact::class,
            'auditable_id' => $contact->id,
        ]);

        $this->actingAs($owner)
            ->deleteJson(route('api.contacts.destroy', $contact))
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $owner->id,
            'action' => 'contacts.delete',
            'auditable_type' => Contact::class,
            'auditable_id' => $contact->id,
        ]);
    }

    public function test_failed_audit_write_rolls_back_the_update(): void
    {
        $owner = $this->owner();
        $contact = $this->contact($owner, ['name' => 'Ada', 'email' => 'ada@example.test', 'normalized_email' => 'ada@example.test']);

        ActivityLog::creating(function () {
            throw new RuntimeException('audit boom');
        });

        try {
            $this->withoutExceptionHandling();
            try {
                $this->actingAs($owner)
                    ->patchJson(route('api.contacts.update', $contact), ['name' => 'Should Not Persist']);
                $this->fail('Expected the audit failure to abort the request.');
            } catch (RuntimeException $e) {
                // expected
            }
        } finally {
            ActivityLog::flushEventListeners();
        }

        $this->assertSame('Ada', $contact->fresh()->name);
    }

    public function test_guest_api_still_hides_contact_id(): void
    {
        $owner = $this->owner();
        $event = $this->event($owner);

        $this->actingAs($owner)
            ->postJson(route('api.events.guests.store', [$event->id]), ['name' => 'Ada', 'email' => 'ada@example.test'])
            ->assertStatus(201);

        $response = $this->actingAs($owner)->getJson(route('api.events.guests.index', [$event->id]))->assertOk();

        $this->assertNotEmpty($response->json('data'));
        $this->assertArrayNotHasKey('contact_id', $response->json('data.0'));
    }
}
