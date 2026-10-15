<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Foundation tests for the additive contacts schema introduced in Phase 3.1.
 *
 * These cover the schema/model contract only. No backfill, resolution, merge,
 * API or workflow behaviour is exercised (none exists yet).
 */
class ContactFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_belongs_to_its_owning_account(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create(['user_id' => $user->id]);

        $this->assertSame($user->id, $contact->user_id);
        $this->assertTrue($contact->user->is($user));
    }

    public function test_contact_can_be_linked_to_guests_across_events(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create(['user_id' => $user->id]);

        $eventA = Event::factory()->create(['user_id' => $user->id]);
        $eventB = Event::factory()->create(['user_id' => $user->id]);

        $guestA = Guest::factory()->create(['event_id' => $eventA->id, 'contact_id' => $contact->id]);
        $guestB = Guest::factory()->create(['event_id' => $eventB->id, 'contact_id' => $contact->id]);

        $this->assertCount(2, $contact->guests);
        $this->assertTrue($guestA->contact->is($contact));
        $this->assertTrue($guestB->contact->is($contact));
    }

    public function test_guest_can_exist_without_a_contact(): void
    {
        $guest = Guest::factory()->create();

        $this->assertNull($guest->contact_id);
        $this->assertNull($guest->contact);
        // Existing guest fields are untouched by the new relationship.
        $this->assertNotNull($guest->name);
        $this->assertNotNull($guest->invite_token);
        $this->assertSame('pending', $guest->status);
    }

    public function test_guest_serialization_does_not_expose_contact_id(): void
    {
        $guest = Guest::factory()->create();

        // The new column must not change existing guest API/serialization.
        $this->assertArrayNotHasKey('contact_id', $guest->toArray());
        $this->assertArrayNotHasKey('contact_id', $guest->fresh()->toArray());
    }

    public function test_deleting_a_contact_detaches_guests_without_deleting_them(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create(['user_id' => $user->id]);
        $guest = Guest::factory()->create(['contact_id' => $contact->id]);

        $contact->delete();

        $guest->refresh();
        $this->assertNull($guest->contact_id);
        $this->assertDatabaseHas('guests', ['id' => $guest->id]);
        $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);
    }

    public function test_deleting_a_guest_does_not_delete_its_contact(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create(['user_id' => $user->id]);
        $guest = Guest::factory()->create(['contact_id' => $contact->id]);

        $guest->delete();

        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
    }

    public function test_normalized_email_is_unique_per_account(): void
    {
        $user = User::factory()->create();
        Contact::factory()->create(['user_id' => $user->id, 'normalized_email' => 'duplicate@example.test']);

        $this->expectException(QueryException::class);
        Contact::factory()->create(['user_id' => $user->id, 'normalized_email' => 'duplicate@example.test']);
    }

    public function test_same_normalized_email_is_allowed_across_accounts(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();

        Contact::factory()->create(['user_id' => $ownerA->id, 'normalized_email' => 'shared@example.test']);
        Contact::factory()->create(['user_id' => $ownerB->id, 'normalized_email' => 'shared@example.test']);

        $this->assertSame(2, Contact::count());
    }

    public function test_multiple_contacts_may_have_no_normalized_identity(): void
    {
        $user = User::factory()->create();

        Contact::factory()->count(3)->create([
            'user_id' => $user->id,
            'normalized_email' => null,
            'normalized_phone' => null,
        ]);

        $this->assertSame(3, Contact::count());
    }

    public function test_normalized_phone_is_unique_per_account(): void
    {
        $user = User::factory()->create();
        Contact::factory()->create([
            'user_id' => $user->id,
            'normalized_email' => null,
            'normalized_phone' => '255700000001',
        ]);

        $this->expectException(QueryException::class);
        Contact::factory()->create([
            'user_id' => $user->id,
            'normalized_email' => null,
            'normalized_phone' => '255700000001',
        ]);
    }
}
