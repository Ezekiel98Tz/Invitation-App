<?php

namespace Tests\Feature\Api;

use App\Models\Contact;
use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function contact(User $owner, array $attributes = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'user_id' => $owner->id,
            'phone' => null,
            'normalized_phone' => null,
        ], $attributes));
    }

    public function test_all_contact_endpoints_require_authentication(): void
    {
        $contact = $this->contact(User::factory()->create(), ['email' => 'a@example.test', 'normalized_email' => 'a@example.test']);

        $this->getJson(route('api.contacts.index'))->assertStatus(401);
        $this->getJson(route('api.contacts.show', $contact))->assertStatus(401);
        $this->getJson(route('api.contacts.guests', $contact))->assertStatus(401);
        $this->patchJson(route('api.contacts.update', $contact), ['name' => 'X'])->assertStatus(401);
        $this->deleteJson(route('api.contacts.destroy', $contact))->assertStatus(401);
    }

    public function test_owner_can_manage_own_contacts(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);
        $contact = $this->contact($owner, ['name' => 'Ada', 'email' => 'ada@example.test', 'normalized_email' => 'ada@example.test']);
        Guest::factory()->create(['event_id' => $event->id, 'contact_id' => $contact->id]);

        $this->actingAs($owner)->getJson(route('api.contacts.show', $contact))->assertOk();
        $this->actingAs($owner)->getJson(route('api.contacts.guests', $contact))->assertOk();
        $this->actingAs($owner)->patchJson(route('api.contacts.update', $contact), ['name' => 'Ada L'])->assertOk();
        $this->actingAs($owner)->deleteJson(route('api.contacts.destroy', $contact))->assertOk();
    }

    public function test_staff_can_view_and_edit_owner_contacts_but_not_delete(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create(['role' => 'staff', 'owner_id' => $owner->id]);
        $contact = $this->contact($owner, ['name' => 'Ada', 'email' => 'ada@example.test', 'normalized_email' => 'ada@example.test']);

        $this->actingAs($staff)
            ->getJson(route('api.contacts.index'))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Ada');

        $this->actingAs($staff)->getJson(route('api.contacts.show', $contact))->assertOk();
        $this->actingAs($staff)->getJson(route('api.contacts.guests', $contact))->assertOk();
        $this->actingAs($staff)->patchJson(route('api.contacts.update', $contact), ['notes' => 'staff edit'])->assertOk();
        $this->actingAs($staff)->deleteJson(route('api.contacts.destroy', $contact))->assertForbidden();
    }

    public function test_cross_account_access_is_forbidden_for_every_endpoint(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $contact = $this->contact($owner, ['name' => 'Ada', 'email' => 'ada@example.test', 'normalized_email' => 'ada@example.test']);

        $this->actingAs($intruder)->getJson(route('api.contacts.show', $contact))->assertForbidden();
        $this->actingAs($intruder)->getJson(route('api.contacts.guests', $contact))->assertForbidden();
        $this->actingAs($intruder)->patchJson(route('api.contacts.update', $contact), ['name' => 'Hacked'])->assertForbidden();
        $this->actingAs($intruder)->deleteJson(route('api.contacts.destroy', $contact))->assertForbidden();

        $this->assertSame('Ada', $contact->fresh()->name);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
    }

    public function test_cross_account_index_is_empty(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $this->contact($owner, ['name' => 'Private', 'email' => 'private@example.test', 'normalized_email' => 'private@example.test']);

        $this->actingAs($intruder)
            ->getJson(route('api.contacts.index'))
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_index_search_cannot_leak_other_accounts_contacts(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $this->contact($owner, ['name' => 'Secret Person', 'email' => 'secret@example.test', 'normalized_email' => 'secret@example.test']);

        $this->actingAs($intruder)
            ->getJson(route('api.contacts.index', ['search' => 'secret@example.test']))
            ->assertOk()
            ->assertJsonPath('total', 0);
    }
}
