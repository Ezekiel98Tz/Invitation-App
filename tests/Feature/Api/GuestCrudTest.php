<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestCrudTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOwner(): array
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        return [$user, $event];
    }

    public function test_guest_creation_requires_authentication(): void
    {
        $event = Event::factory()->create();

        $this->postJson(route('api.events.guests.store', ['event' => $event->id]), ['name' => 'A'])
            ->assertStatus(401);
    }

    public function test_guest_can_be_created_manually(): void
    {
        [$user, $event] = $this->actingAsOwner();

        $this->actingAs($user)
            ->postJson(route('api.events.guests.store', ['event' => $event->id]), [
                'name' => 'Dana',
                'email' => 'dana@example.test',
                'phone' => '+255123',
            ])
            ->assertStatus(201)
            ->assertJsonPath('name', 'Dana')
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('guests', ['event_id' => $event->id, 'name' => 'Dana']);
    }

    public function test_guest_creation_validates_input(): void
    {
        [$user, $event] = $this->actingAsOwner();

        $this->actingAs($user)
            ->postJson(route('api.events.guests.store', ['event' => $event->id]), ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->actingAs($user)
            ->postJson(route('api.events.guests.store', ['event' => $event->id]), ['name' => 'X', 'email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_duplicate_guest_email_is_rejected_on_create(): void
    {
        [$user, $event] = $this->actingAsOwner();
        Guest::factory()->create(['event_id' => $event->id, 'email' => 'dup@example.test']);

        $this->actingAs($user)
            ->postJson(route('api.events.guests.store', ['event' => $event->id]), [
                'name' => 'Someone',
                'email' => 'DUP@example.test',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_guest_creation_is_forbidden_for_another_users_event(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->postJson(route('api.events.guests.store', ['event' => $event->id]), ['name' => 'A'])
            ->assertStatus(403);
    }

    public function test_guest_can_be_updated(): void
    {
        [$user, $event] = $this->actingAsOwner();
        $guest = Guest::factory()->create(['event_id' => $event->id, 'name' => 'Old', 'email' => null]);

        $this->actingAs($user)
            ->putJson(route('api.events.guests.update', ['event' => $event->id, 'guest' => $guest->id]), [
                'name' => 'New Name',
                'email' => 'new@example.test',
            ])
            ->assertOk()
            ->assertJsonPath('name', 'New Name')
            ->assertJsonPath('email', 'new@example.test');
    }

    public function test_guest_update_requires_ownership_and_event_match(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $this->actingAs($other)
            ->putJson(route('api.events.guests.update', ['event' => $event->id, 'guest' => $guest->id]), ['name' => 'Hacked'])
            ->assertStatus(403);

        // Guest does not belong to this (owner's other) event -> 404.
        $otherEvent = Event::factory()->create(['user_id' => $owner->id]);
        $this->actingAs($owner)
            ->putJson(route('api.events.guests.update', ['event' => $otherEvent->id, 'guest' => $guest->id]), ['name' => 'X'])
            ->assertStatus(404);
    }

    public function test_guest_can_be_deleted(): void
    {
        [$user, $event] = $this->actingAsOwner();
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $this->actingAs($user)
            ->deleteJson(route('api.events.guests.destroy', ['event' => $event->id, 'guest' => $guest->id]))
            ->assertOk()
            ->assertJson(['deleted' => true]);

        $this->assertDatabaseMissing('guests', ['id' => $guest->id]);
    }

    public function test_guest_delete_is_forbidden_for_another_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $this->actingAs($other)
            ->deleteJson(route('api.events.guests.destroy', ['event' => $event->id, 'guest' => $guest->id]))
            ->assertStatus(403);
    }

    public function test_individual_reminder_respects_eligibility(): void
    {
        [$user, $event] = $this->actingAsOwner();

        $pending = Guest::factory()->create(['event_id' => $event->id, 'status' => 'pending', 'reminded_at' => null]);
        $accepted = Guest::factory()->create(['event_id' => $event->id, 'status' => 'accepted']);
        $reminded = Guest::factory()->create(['event_id' => $event->id, 'status' => 'pending', 'reminded_at' => now()]);

        $this->actingAs($user)
            ->postJson(route('api.events.guests.remind', ['event' => $event->id, 'guest' => $pending->id]))
            ->assertStatus(202)
            ->assertJson(['queued' => true]);

        $this->actingAs($user)
            ->postJson(route('api.events.guests.remind', ['event' => $event->id, 'guest' => $accepted->id]))
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson(route('api.events.guests.remind', ['event' => $event->id, 'guest' => $reminded->id]))
            ->assertStatus(422);
    }
}
