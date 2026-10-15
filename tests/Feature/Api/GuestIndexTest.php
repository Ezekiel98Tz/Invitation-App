<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_index_returns_paginator_and_status_counts(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        Guest::factory()->count(2)->create(['event_id' => $event->id, 'status' => 'pending']);
        Guest::factory()->create(['event_id' => $event->id, 'status' => 'accepted']);
        Guest::factory()->create(['event_id' => $event->id, 'status' => 'declined']);

        $this->actingAs($user)
            ->getJson(route('api.events.guests.index', ['event' => $event->id]))
            ->assertOk()
            ->assertJsonPath('counts.total', 4)
            ->assertJsonPath('counts.pending', 2)
            ->assertJsonPath('counts.accepted', 1)
            ->assertJsonPath('counts.declined', 1)
            ->assertJsonStructure(['data', 'current_page', 'last_page', 'counts']);
    }

    public function test_status_filter_does_not_change_the_event_wide_counts(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        Guest::factory()->count(3)->create(['event_id' => $event->id, 'status' => 'pending']);
        Guest::factory()->create(['event_id' => $event->id, 'status' => 'accepted']);

        $this->actingAs($user)
            ->getJson(route('api.events.guests.index', ['event' => $event->id, 'status' => 'accepted']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('counts.total', 4)
            ->assertJsonPath('counts.accepted', 1);
    }

    public function test_guest_index_is_forbidden_for_another_users_event(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->getJson(route('api.events.guests.index', ['event' => $event->id]))
            ->assertStatus(403);
    }
}
