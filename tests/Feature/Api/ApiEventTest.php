<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiEventTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Launch Party',
            'description' => 'A launch.',
            'event_start' => now()->addWeek()->toDateTimeString(),
            'timezone' => 'Africa/Dar_es_Salaam',
            'venue' => 'The Hall',
            'capacity' => 100,
        ], $overrides);
    }

    public function test_events_index_requires_authentication(): void
    {
        $this->getJson(route('api.events.index'))->assertStatus(401);
    }

    public function test_admin_can_create_and_list_events(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.events.store'), $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('title', 'Launch Party');

        $this->actingAs($user)
            ->getJson(route('api.events.index'))
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'title', 'venue']]]);
    }

    public function test_event_can_be_shown_updated_duplicated_and_deleted(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->getJson(route('api.events.show', $event))
            ->assertOk()
            ->assertJsonPath('id', $event->id);

        $this->actingAs($user)
            ->putJson(route('api.events.update', $event), ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('title', 'Renamed');

        $this->actingAs($user)
            ->postJson(route('api.events.duplicate', $event))
            ->assertStatus(201);

        $this->assertDatabaseCount('events', 2);

        $this->actingAs($user)
            ->deleteJson(route('api.events.destroy', $event))
            ->assertOk()
            ->assertJson(['deleted' => true]);
    }

    public function test_event_show_validates_request_payload(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.events.store'), $this->payload(['title' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    public function test_user_cannot_access_another_users_event(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->getJson(route('api.events.show', $event))
            ->assertStatus(403);
    }

    public function test_staff_can_view_own_owners_event_but_not_delete(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create(['role' => 'staff', 'owner_id' => $owner->id]);
        $event = Event::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($staff)
            ->getJson(route('api.events.show', $event))
            ->assertOk();

        $this->actingAs($staff)
            ->deleteJson(route('api.events.destroy', $event))
            ->assertStatus(403);
    }
}
