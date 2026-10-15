<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_includes_accepted_and_declined_counts(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        Guest::factory()->count(2)->create(['event_id' => $event->id, 'status' => 'accepted']);
        Guest::factory()->create(['event_id' => $event->id, 'status' => 'declined']);
        Guest::factory()->create(['event_id' => $event->id, 'status' => 'pending']);

        $this->actingAs($user)
            ->getJson(route('api.reports.events'))
            ->assertOk()
            ->assertJsonPath('events.0.guests_total', 4)
            ->assertJsonPath('events.0.guests_accepted', 2)
            ->assertJsonPath('events.0.guests_declined', 1)
            ->assertJsonPath('events.0.guests_responded', 3);
    }

    public function test_report_is_scoped_to_the_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Event::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->getJson(route('api.reports.events'))
            ->assertOk()
            ->assertJsonPath('total_events', 0);
    }
}
