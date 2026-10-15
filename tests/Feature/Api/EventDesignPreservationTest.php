<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventDesignPreservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_an_event_preserves_the_saved_invitation_design(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['user_id' => $user->id]);

        // 1. Save an invitation design.
        $this->actingAs($user)
            ->putJson(route('api.events.invitation.update', $event), [
                'template' => 'blush-romance',
                'heading' => 'Our wedding',
                'colors' => ['accent' => '#db2777'],
            ])
            ->assertOk();

        // 2. Update the event (as the dashboard edit modal does, sending settings).
        $this->actingAs($user)
            ->putJson(route('api.events.update', $event), [
                'title' => 'Renamed Event',
                'settings' => [
                    'allow_plus_ones' => true,
                    'custom_questions' => [],
                    'enable_waitlist' => false,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('title', 'Renamed Event');

        // 3. The saved design must remain unchanged...
        $this->actingAs($user)
            ->getJson(route('api.events.invitation.show', $event))
            ->assertOk()
            ->assertJsonPath('design.template', 'blush-romance')
            ->assertJsonPath('design.heading', 'Our wedding')
            ->assertJsonPath('design.colors.accent', '#db2777');

        // 4. ...and the intended settings update must have applied.
        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'title' => 'Renamed Event',
        ]);
        $this->assertTrue((bool) $event->fresh()->settings['allow_plus_ones']);
        $this->assertSame('blush-romance', $event->fresh()->settings['design']['template']);
    }

    public function test_settings_not_submitted_are_left_untouched(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'user_id' => $user->id,
            'settings' => [
                'allow_plus_ones' => true,
                'custom_questions' => [['key' => 'meal', 'type' => 'text', 'required' => false]],
                'enable_waitlist' => true,
            ],
        ]);

        // Update without a settings key at all.
        $this->actingAs($user)
            ->putJson(route('api.events.update', $event), ['title' => 'Only Title'])
            ->assertOk();

        $settings = $event->fresh()->settings;
        $this->assertTrue((bool) $settings['allow_plus_ones']);
        $this->assertTrue((bool) $settings['enable_waitlist']);
        $this->assertSame('meal', $settings['custom_questions'][0]['key']);
    }
}
