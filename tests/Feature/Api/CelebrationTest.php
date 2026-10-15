<?php

namespace Tests\Feature\Api;

use App\Models\Celebration;
use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CelebrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_celebrations_index_requires_authentication(): void
    {
        $this->getJson(route('api.celebrations.index'))->assertStatus(401);
    }

    public function test_owner_can_create_list_show_update_and_delete_a_celebration(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.celebrations.store'), [
                'name' => "Amina's Wedding Celebration",
                'description' => 'Three related occasions.',
            ])
            ->assertStatus(201)
            ->assertJsonPath('name', "Amina's Wedding Celebration");

        $celebration = Celebration::query()->latest('id')->first();
        $this->assertSame($user->id, $celebration->user_id);

        $this->actingAs($user)
            ->getJson(route('api.celebrations.index'))
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'description', 'events_count']]])
            ->assertJsonPath('data.0.id', $celebration->id);

        $this->actingAs($user)
            ->getJson(route('api.celebrations.show', $celebration))
            ->assertOk()
            ->assertJsonPath('celebration.id', $celebration->id)
            ->assertJsonStructure(['celebration', 'events']);

        $this->actingAs($user)
            ->putJson(route('api.celebrations.update', $celebration), ['name' => 'Renamed Celebration'])
            ->assertOk()
            ->assertJsonPath('name', 'Renamed Celebration');

        $this->actingAs($user)
            ->deleteJson(route('api.celebrations.destroy', $celebration))
            ->assertOk()
            ->assertJson(['deleted' => true]);

        $this->assertDatabaseMissing('celebrations', ['id' => $celebration->id]);
    }

    public function test_celebration_creation_validates_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.celebrations.store'), ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->actingAs($user)
            ->postJson(route('api.celebrations.store'), ['description' => 'No name'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_staff_can_view_and_update_owner_celebrations_but_not_create_or_delete(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create(['role' => 'staff', 'owner_id' => $owner->id]);
        $celebration = Celebration::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($staff)
            ->getJson(route('api.celebrations.index'))
            ->assertOk()
            ->assertJsonFragment(['id' => $celebration->id]);

        $this->actingAs($staff)
            ->getJson(route('api.celebrations.show', $celebration))
            ->assertOk();

        $this->actingAs($staff)
            ->putJson(route('api.celebrations.update', $celebration), ['name' => 'Staff Renamed'])
            ->assertOk();

        $this->actingAs($staff)
            ->postJson(route('api.celebrations.store'), ['name' => 'Nope'])
            ->assertForbidden();

        $this->actingAs($staff)
            ->deleteJson(route('api.celebrations.destroy', $celebration))
            ->assertForbidden();
    }

    public function test_user_cannot_access_another_owners_celebration(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $celebration = Celebration::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->getJson(route('api.celebrations.show', $celebration))
            ->assertForbidden();

        $this->actingAs($other)
            ->putJson(route('api.celebrations.update', $celebration), ['name' => 'Hacked'])
            ->assertForbidden();

        $this->actingAs($other)
            ->deleteJson(route('api.celebrations.destroy', $celebration))
            ->assertForbidden();
    }

    public function test_index_is_scoped_to_the_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Celebration::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->getJson(route('api.celebrations.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_show_lists_associated_events(): void
    {
        $owner = User::factory()->create();
        $celebration = Celebration::factory()->create(['user_id' => $owner->id]);
        $attached = Event::factory()->create(['user_id' => $owner->id, 'celebration_id' => $celebration->id]);
        Event::factory()->create(['user_id' => $owner->id]); // standalone

        $this->actingAs($owner)
            ->getJson(route('api.celebrations.show', $celebration))
            ->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.id', $attached->id);
    }

    public function test_event_can_be_attached_and_detached(): void
    {
        $owner = User::factory()->create();
        $celebration = Celebration::factory()->create(['user_id' => $owner->id]);
        $event = Event::factory()->create(['user_id' => $owner->id, 'celebration_id' => null]);

        $this->actingAs($owner)
            ->postJson(route('api.celebrations.events.attach', [$celebration->id, $event->id]))
            ->assertOk()
            ->assertJsonPath('celebration_id', $celebration->id);

        $this->assertDatabaseHas('events', ['id' => $event->id, 'celebration_id' => $celebration->id]);

        $this->actingAs($owner)
            ->deleteJson(route('api.celebrations.events.detach', [$celebration->id, $event->id]))
            ->assertOk()
            ->assertJsonPath('celebration_id', null);

        $this->assertDatabaseHas('events', ['id' => $event->id, 'celebration_id' => null]);
    }

    public function test_attaching_an_event_already_in_another_celebration_moves_it(): void
    {
        $owner = User::factory()->create();
        $first = Celebration::factory()->create(['user_id' => $owner->id]);
        $second = Celebration::factory()->create(['user_id' => $owner->id]);
        $event = Event::factory()->create(['user_id' => $owner->id, 'celebration_id' => $first->id]);

        $this->actingAs($owner)
            ->postJson(route('api.celebrations.events.attach', [$second->id, $event->id]))
            ->assertOk()
            ->assertJsonPath('celebration_id', $second->id);

        $this->assertDatabaseHas('events', ['id' => $event->id, 'celebration_id' => $second->id]);
    }

    public function test_event_cannot_be_attached_to_a_celebration_in_another_account(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();

        $celebration = Celebration::factory()->create(['user_id' => $ownerA->id]);
        $foreignEvent = Event::factory()->create(['user_id' => $ownerB->id, 'celebration_id' => null]);

        // The celebration owner cannot adopt another account's event...
        $this->actingAs($ownerA)
            ->postJson(route('api.celebrations.events.attach', [$celebration->id, $foreignEvent->id]))
            ->assertForbidden();

        // ...and the other owner cannot attach their event to a foreign celebration.
        $this->actingAs($ownerB)
            ->postJson(route('api.celebrations.events.attach', [$celebration->id, $foreignEvent->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('events', ['id' => $foreignEvent->id, 'celebration_id' => null]);
    }

    public function test_detach_requires_the_event_to_belong_to_the_celebration(): void
    {
        $owner = User::factory()->create();
        $celebration = Celebration::factory()->create(['user_id' => $owner->id]);
        $event = Event::factory()->create(['user_id' => $owner->id, 'celebration_id' => null]);

        $this->actingAs($owner)
            ->deleteJson(route('api.celebrations.events.detach', [$celebration->id, $event->id]))
            ->assertNotFound();
    }

    public function test_deleting_a_celebration_detaches_events_without_deleting_them_or_guests(): void
    {
        $owner = User::factory()->create();
        $celebration = Celebration::factory()->create(['user_id' => $owner->id]);
        $event = Event::factory()->create(['user_id' => $owner->id, 'celebration_id' => $celebration->id]);
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $this->actingAs($owner)
            ->deleteJson(route('api.celebrations.destroy', $celebration))
            ->assertOk()
            ->assertJsonPath('detached_events', 1);

        $this->assertDatabaseMissing('celebrations', ['id' => $celebration->id]);
        $this->assertDatabaseHas('events', ['id' => $event->id, 'celebration_id' => null]);
        $this->assertDatabaseHas('guests', ['id' => $guest->id, 'event_id' => $event->id]);
    }

    public function test_standalone_events_and_the_event_api_are_unaffected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.events.store'), [
                'title' => 'Standalone Party',
                'event_start' => now()->addWeek()->toDateTimeString(),
                'venue' => 'The Hall',
                'settings' => [],
            ])
            ->assertStatus(201)
            ->assertJsonPath('celebration_id', null);

        $event = Event::query()->latest('id')->first();

        $this->actingAs($user)
            ->getJson(route('api.events.index'))
            ->assertOk()
            ->assertJsonFragment(['id' => $event->id]);

        $this->actingAs($user)
            ->getJson(route('api.events.show', $event))
            ->assertOk()
            ->assertJsonPath('id', $event->id)
            ->assertJsonPath('celebration_id', null);
    }

    public function test_event_in_a_celebration_still_supports_public_invite_and_rsvp(): void
    {
        $owner = User::factory()->create();
        $celebration = Celebration::factory()->create(['user_id' => $owner->id]);
        $event = Event::factory()->create([
            'user_id' => $owner->id,
            'celebration_id' => $celebration->id,
            'settings' => ['allow_plus_ones' => true, 'custom_questions' => []],
        ]);
        $guest = Guest::factory()->create(['event_id' => $event->id, 'status' => 'pending']);

        $this->getJson(route('api.invites.show', ['token' => $guest->invite_token]))
            ->assertOk()
            ->assertJsonPath('event.id', $event->id)
            ->assertJsonPath('settings.allow_plus_ones', true);

        $this->postJson(route('api.invites.store', ['token' => $guest->invite_token]), [
            'status' => 'accepted',
            'attending_count' => 2,
        ])->assertOk()->assertJsonPath('accepted', true);

        $this->assertSame('accepted', $guest->fresh()->status);
    }
}
