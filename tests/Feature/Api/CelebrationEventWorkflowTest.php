<?php

namespace Tests\Feature\Api;

use App\Models\Celebration;
use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CelebrationEventWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function eventPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Ceremony',
            'event_start' => now()->addWeek()->toDateTimeString(),
            'venue' => 'The Hall',
            'settings' => [],
        ], $overrides);
    }

    public function test_event_can_be_created_directly_inside_a_celebration(): void
    {
        $owner = User::factory()->create();
        $celebration = Celebration::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)
            ->postJson(route('api.events.store'), $this->eventPayload([
                'title' => 'Kitchen Party',
                'celebration_id' => $celebration->id,
            ]))
            ->assertStatus(201)
            ->assertJsonPath('celebration_id', $celebration->id)
            ->assertJsonPath('title', 'Kitchen Party');

        $event = Event::query()->latest('id')->first();
        $this->assertSame($celebration->id, $event->celebration_id);
        $this->assertSame($owner->id, $event->user_id);
    }

    public function test_new_event_appears_in_the_event_listing_and_celebration(): void
    {
        $owner = User::factory()->create();
        $celebration = Celebration::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)
            ->postJson(route('api.events.store'), $this->eventPayload([
                'title' => 'Send-off',
                'celebration_id' => $celebration->id,
            ]))
            ->assertStatus(201);

        $event = Event::query()->latest('id')->first();

        $this->actingAs($owner)
            ->getJson(route('api.events.index'))
            ->assertOk()
            ->assertJsonFragment(['id' => $event->id])
            ->assertJsonPath('data.0.celebration.name', $celebration->name);

        $this->actingAs($owner)
            ->getJson(route('api.celebrations.show', $celebration))
            ->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.id', $event->id);
    }

    public function test_creating_an_event_inside_a_celebration_preserves_settings_and_design(): void
    {
        $owner = User::factory()->create();
        $celebration = Celebration::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)
            ->postJson(route('api.events.store'), $this->eventPayload([
                'celebration_id' => $celebration->id,
                'settings' => [
                    'event_type' => 'wedding',
                    'allow_plus_ones' => true,
                ],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('event_type', 'wedding')
            ->assertJsonPath('capabilities.plus_ones', true);

        $event = Event::query()->latest('id')->first();

        // The invitation design is stored through the design endpoint, exactly
        // as the app does; it must survive alongside the celebration membership.
        $this->actingAs($owner)
            ->putJson(route('api.events.invitation.update', $event), [
                'template' => 'blush-romance',
                'heading' => 'Our day',
            ])
            ->assertOk();

        $event->refresh();
        $this->assertSame($celebration->id, $event->celebration_id);
        $this->assertSame('wedding', $event->settings['event_type']);
        $this->assertSame('blush-romance', $event->settings['design']['template']);
        $this->assertSame('Our day', $event->settings['design']['heading']);
    }

    public function test_standalone_event_creation_remains_compatible(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->postJson(route('api.events.store'), $this->eventPayload(['title' => 'Standalone']))
            ->assertStatus(201)
            ->assertJsonPath('celebration_id', null);
    }

    public function test_event_cannot_be_created_in_another_accounts_celebration(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $celebration = Celebration::factory()->create(['user_id' => $ownerA->id]);

        $this->actingAs($ownerB)
            ->postJson(route('api.events.store'), $this->eventPayload([
                'celebration_id' => $celebration->id,
            ]))
            ->assertForbidden();

        $this->assertDatabaseCount('events', 0);
    }

    public function test_unknown_celebration_id_is_rejected(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->postJson(route('api.events.store'), $this->eventPayload(['celebration_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('celebration_id');
    }

    public function test_standalone_candidate_list_excludes_grouped_events(): void
    {
        $owner = User::factory()->create();
        $celebration = Celebration::factory()->create(['user_id' => $owner->id]);

        $standalone = Event::factory()->count(3)->create(['user_id' => $owner->id, 'celebration_id' => null]);
        Event::factory()->count(2)->create(['user_id' => $owner->id, 'celebration_id' => $celebration->id]);

        $response = $this->actingAs($owner)
            ->getJson(route('api.events.index', ['standalone' => 1]))
            ->assertOk();

        $response->assertJsonPath('total', 3);

        $ids = collect($response->json('data'))->pluck('id')->all();
        foreach ($standalone as $event) {
            $this->assertContains($event->id, $ids);
        }
    }

    public function test_standalone_candidates_are_paginated_beyond_the_first_page(): void
    {
        $owner = User::factory()->create();
        Event::factory()->count(16)->create(['user_id' => $owner->id, 'celebration_id' => null]);

        $this->actingAs($owner)
            ->getJson(route('api.events.index', ['standalone' => 1, 'page' => 1, 'per_page' => 10]))
            ->assertOk()
            ->assertJsonPath('total', 16)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('last_page', 2);

        $this->actingAs($owner)
            ->getJson(route('api.events.index', ['standalone' => 1, 'page' => 2, 'per_page' => 10]))
            ->assertOk()
            ->assertJsonCount(6, 'data');
    }

    public function test_standalone_candidates_can_be_searched_by_title(): void
    {
        $owner = User::factory()->create();
        Event::factory()->create(['user_id' => $owner->id, 'celebration_id' => null, 'title' => 'Alpha Party']);
        Event::factory()->create(['user_id' => $owner->id, 'celebration_id' => null, 'title' => 'Beta Party']);

        $this->actingAs($owner)
            ->getJson(route('api.events.index', ['standalone' => 1, 'search' => 'Alpha']))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.title', 'Alpha Party');
    }

    public function test_detaching_an_event_keeps_the_event_and_its_guests(): void
    {
        $owner = User::factory()->create();
        $celebration = Celebration::factory()->create(['user_id' => $owner->id]);
        $event = Event::factory()->create([
            'user_id' => $owner->id,
            'celebration_id' => $celebration->id,
            'settings' => ['event_type' => 'send_off', 'design' => ['template' => 'midnight-gold']],
        ]);
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $this->actingAs($owner)
            ->deleteJson(route('api.celebrations.events.detach', [$celebration->id, $event->id]))
            ->assertOk()
            ->assertJsonPath('celebration_id', null);

        $event->refresh();
        $this->assertDatabaseHas('events', ['id' => $event->id, 'celebration_id' => null]);
        $this->assertDatabaseHas('guests', ['id' => $guest->id, 'event_id' => $event->id]);
        $this->assertSame('send_off', $event->settings['event_type']);
        $this->assertSame('midnight-gold', $event->settings['design']['template']);
    }
}
