<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventTypeAndCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $settings = [], array $overrides = []): array
    {
        return array_merge([
            'title' => 'Launch Party',
            'description' => 'A launch.',
            'event_start' => now()->addWeek()->toDateTimeString(),
            'timezone' => 'Africa/Dar_es_Salaam',
            'venue' => 'The Hall',
            'capacity' => 100,
            'settings' => $settings,
        ], $overrides);
    }

    public function test_event_types_endpoint_is_public_and_returns_taxonomy(): void
    {
        $this->getJson(route('api.event-types'))
            ->assertOk()
            ->assertJsonPath('default', 'custom')
            ->assertJsonCount(7, 'types')
            ->assertJsonStructure([
                'default',
                'types' => [
                    'wedding' => ['label', 'description'],
                ],
                'capabilities' => [
                    'plus_ones' => ['label', 'description', 'implemented', 'default'],
                    'contributions' => ['label', 'description', 'implemented', 'default'],
                ],
            ]);
    }

    public function test_admin_can_create_an_event_with_a_type_and_capabilities(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.events.store'), $this->payload([
                'event_type' => 'wedding',
                'capabilities' => ['plus_ones' => true],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('event_type', 'wedding')
            ->assertJsonPath('capabilities.plus_ones', true)
            ->assertJsonPath('capabilities.contributions', false);

        $event = Event::query()->latest('id')->first();
        $this->assertSame('wedding', $event->settings['event_type']);
        $this->assertTrue($event->settings['capabilities']['plus_ones']);
    }

    public function test_event_without_a_type_defaults_to_the_configured_default(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.events.store'), $this->payload([]))
            ->assertStatus(201)
            ->assertJsonPath('event_type', 'custom');

        $this->assertSame('custom', Event::query()->latest('id')->first()->settings['event_type']);
    }

    public function test_unknown_event_type_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.events.store'), $this->payload(['event_type' => 'not_a_type']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('settings.event_type');
    }

    public function test_unknown_capability_key_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.events.store'), $this->payload([
                'capabilities' => ['teleportation' => true],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('settings.capabilities');
    }

    public function test_not_yet_implemented_capability_cannot_be_enabled(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.events.store'), $this->payload([
                'capabilities' => ['households' => true],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('settings.capabilities');
    }

    public function test_check_in_and_contributions_are_accepted_but_default_off(): void
    {
        $user = User::factory()->create();

        // Default-off when the capability is not mentioned.
        $this->actingAs($user)
            ->postJson(route('api.events.store'), $this->payload([]))
            ->assertStatus(201)
            ->assertJsonPath('capabilities.check_in', false)
            ->assertJsonPath('capabilities.contributions', false);

        // Explicit opt-in is accepted and persisted for both capabilities.
        $this->actingAs($user)
            ->postJson(route('api.events.store'), $this->payload([
                'capabilities' => ['check_in' => true, 'contributions' => true],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('capabilities.check_in', true)
            ->assertJsonPath('capabilities.contributions', true);

        $event = Event::query()->latest('id')->first();
        $this->assertTrue($event->settings['capabilities']['check_in']);
        $this->assertTrue($event->settings['capabilities']['contributions']);
    }

    public function test_event_types_endpoint_marks_check_in_and_contributions_implemented(): void
    {
        $this->getJson(route('api.event-types'))
            ->assertOk()
            ->assertJsonPath('capabilities.check_in.implemented', true)
            ->assertJsonPath('capabilities.check_in.default', false)
            ->assertJsonPath('capabilities.contributions.implemented', true)
            ->assertJsonPath('capabilities.contributions.default', false)
            ->assertJsonPath('capabilities.households.implemented', false);
    }

    public function test_unknown_or_not_yet_implemented_capabilities_stay_forced_off(): void
    {
        // A stored value for an unimplemented capability (for example written
        // directly or left over from an older build) never resolves as enabled,
        // even when a raw factory insert bypasses validation.
        $event = Event::factory()->create([
            'settings' => [
                'capabilities' => [
                    'households' => true,
                    'waitlist' => true,
                ],
            ],
        ]);

        $capabilities = $event->capabilities;

        $this->assertFalse($capabilities['households']);
        $this->assertFalse($capabilities['waitlist']);
        $this->assertFalse($capabilities['check_in']);
        $this->assertFalse($capabilities['contributions']);
    }

    public function test_legacy_settings_resolve_capabilities_without_migration(): void
    {
        $event = Event::factory()->create([
            'settings' => [
                'allow_plus_ones' => true,
                'custom_questions' => [['key' => 'meal', 'type' => 'text', 'required' => false]],
                'enable_waitlist' => true,
            ],
        ]);

        $capabilities = $event->capabilities;

        $this->assertTrue($capabilities['plus_ones']);
        $this->assertTrue($capabilities['custom_questions']);
        // The waitlist workflow does not exist yet, so its legacy flag never
        // reports as an enabled capability.
        $this->assertFalse($capabilities['waitlist']);
    }

    public function test_legacy_allow_plus_ones_still_returns_for_public_invite(): void
    {
        $event = Event::factory()->create([
            'settings' => ['allow_plus_ones' => true, 'custom_questions' => []],
        ]);
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $this->getJson(route('api.invites.show', ['token' => $guest->invite_token]))
            ->assertOk()
            ->assertJsonPath('settings.allow_plus_ones', true)
            ->assertJsonPath('settings.capabilities.plus_ones', true);
    }

    public function test_capability_enables_plus_ones_on_the_public_invite(): void
    {
        $event = Event::factory()->create([
            'settings' => [
                'event_type' => 'wedding',
                'capabilities' => ['plus_ones' => true],
            ],
        ]);
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $this->getJson(route('api.invites.show', ['token' => $guest->invite_token]))
            ->assertOk()
            ->assertJsonPath('settings.allow_plus_ones', true)
            ->assertJsonPath('event.event_type', 'wedding');
    }

    public function test_update_preserves_unknown_settings_keys(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'user_id' => $user->id,
            'settings' => [
                'allow_plus_ones' => false,
                'custom_questions' => [],
                'enable_waitlist' => false,
                'design' => ['template' => 'blush-romance', 'heading' => 'Our day'],
                'future_key' => 'keep-me',
            ],
        ]);

        $this->actingAs($user)
            ->putJson(route('api.events.update', $event), [
                'settings' => ['event_type' => 'birthday'],
            ])
            ->assertOk()
            ->assertJsonPath('event_type', 'birthday');

        $settings = $event->fresh()->settings;
        $this->assertSame('keep-me', $settings['future_key']);
        $this->assertSame('blush-romance', $settings['design']['template']);
        $this->assertSame('birthday', $settings['event_type']);
    }

    public function test_partial_capability_update_preserves_other_capabilities(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'user_id' => $user->id,
            'settings' => ['capabilities' => ['plus_ones' => true]],
        ]);

        $this->actingAs($user)
            ->putJson(route('api.events.update', $event), [
                'settings' => ['capabilities' => ['custom_questions' => true]],
            ])
            ->assertOk()
            ->assertJsonPath('capabilities.plus_ones', true)
            ->assertJsonPath('capabilities.custom_questions', true);
    }

    public function test_legacy_plus_ones_toggle_still_updates_the_capability(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.events.store'), $this->payload(['allow_plus_ones' => false]))
            ->assertStatus(201);

        $event = Event::query()->latest('id')->first();
        $this->assertFalse($event->capabilities['plus_ones']);

        // The dashboard edit form sends the flat `allow_plus_ones` key, not the
        // capability map. Toggling it on must still take effect.
        $this->actingAs($user)
            ->putJson(route('api.events.update', $event), [
                'settings' => ['allow_plus_ones' => true],
            ])
            ->assertOk()
            ->assertJsonPath('capabilities.plus_ones', true);

        $this->actingAs($user)
            ->putJson(route('api.events.update', $event), [
                'settings' => ['allow_plus_ones' => false],
            ])
            ->assertOk()
            ->assertJsonPath('capabilities.plus_ones', false);
    }

    public function test_empty_custom_questions_does_not_disable_the_capability(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'user_id' => $user->id,
            'settings' => ['capabilities' => ['custom_questions' => true]],
        ]);

        // The dashboard always sends an empty array; it must not silently turn
        // the explicitly enabled capability back off.
        $this->actingAs($user)
            ->putJson(route('api.events.update', $event), [
                'settings' => ['custom_questions' => [], 'allow_plus_ones' => false],
            ])
            ->assertOk()
            ->assertJsonPath('capabilities.custom_questions', true);
    }
}
